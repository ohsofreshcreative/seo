<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Market\CostBudget;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\MarketTaskRepository;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;

/**
 * Wykonanie przebiegów wyszukiwania — jedyne miejsce, które wysyła płatne żądania discovery. Wywoływane wyłącznie
 * pod wspólną blokadą płatnych żądań DataForSEO (`MarketSyncService::LOCK`), w tle albo z CLI.
 *
 * Każde żądanie (seed × strona): kontrola wspólnego limitu (dzienny, miesięczny, zadania na przebieg) → wiersz
 * w rejestrze kosztów (`market_tasks`, `trigger_type = discovery`) → wywołanie → zapis metryk do wspólnych
 * `market_keywords` (tylko brakujące lub po TTL; świeże dane STEP 12 nie są nadpisywane) → kandydaci i źródła.
 * Błędy: limit żądań dostawcy → seed wraca do kolejki (do 3 prób, nic nie zostało opłacone); błąd konta → wstrzymanie
 * (wspólne z danymi rynkowymi); pozostałe → seed kończy się błędem bez ponawiania (mógł zostać opłacony).
 */
final class DiscoveryRunner
{
	private const RATE_LIMIT_RETRY = 300;

	public function __construct(
		private readonly KeywordDiscoveryProvider $provider,
		private readonly DiscoveryRunRepository $runs,
		private readonly DiscoveryCandidateRepository $candidates,
		private readonly DiscoverySettingsRepository $settings,
		private readonly MarketMetricsRepository $metrics,
		private readonly MarketTaskRepository $tasks,
		private readonly MarketSyncService $market,
		private readonly MarketDataConfig $marketConfig,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Najwyżej $maxTasks płatnych żądań w budżecie czasu (aktywne przebiegi od najstarszego albo jeden wskazany).
	 *
	 * @return array{requests: int, cost: float, finished: array<int, string>, projects: list<int>, blocked_by: ?string, error: ?string}
	 */
	public function process(int $maxTasks, float $budgetSeconds, ?int $onlyRunId = null): array
	{
		$report = ['requests' => 0, 'cost' => 0.0, 'finished' => [], 'projects' => [], 'blocked_by' => null, 'error' => null];
		$started = microtime(true);
		$budget = $this->market->budget();
		$runs = $onlyRunId === null ? $this->runs->activeRuns() : array_filter([$this->runs->findById($onlyRunId)]);

		foreach ($runs as $run) {
			if (! $run->isActive()) {
				continue;
			}

			$market = new Market($run->provider, '', '', $run->locationCode, $run->languageCode, (string) $run->locationCode, $run->languageCode);
			$exclusions = $this->settings->exclusions($run->projectId);

			while (true) {
				$seed = $this->runs->nextSeed($run->id);

				if ($seed === null) {
					break;
				}

				if ($report['requests'] >= $maxTasks) {
					$report['blocked_by'] = CostBudget::TASK_LIMIT;

					break 2;
				}

				if (microtime(true) - $started >= $budgetSeconds) {
					break 2;
				}

				$limit = $this->pageLimit($run, (int) $seed['next_offset']);
				$estimate = $this->provider->estimateCost($limit);
				$blocked = $budget->check($estimate);

				if ($blocked !== CostBudget::OK && $blocked !== CostBudget::TASK_LIMIT) {
					// Wspólny limit kosztów — przebieg czeka (aktywny) i wznowi się, gdy limit na to pozwoli.
					$this->runs->block($run->id, $blocked);
					$report['blocked_by'] = $blocked;

					break 2;
				}

				$outcome = $this->request($run, $market, $seed, $limit, $estimate, $exclusions, $budget);
				$report['requests']++;
				$report['cost'] += $outcome['cost'];
				$report['projects'][$run->projectId] = $run->projectId;

				if ($outcome['account_error']) {
					$report['error'] = $outcome['error'];

					break 2;
				}

				$run = $this->runs->findById($run->id) ?? $run;

				if (! $run->isActive()) {
					// Anulowany w trakcie żądania: bez kolejnych stron, niedokończone seedy też anulowane.
					$this->runs->cancelPendingSeeds($run->id);

					break;
				}
			}

			$status = $this->runs->finishIfDone($run->id);

			if ($status !== null) {
				$report['finished'][$run->id] = $status;
				$report['projects'][$run->projectId] = $run->projectId;
				$this->logger->info('Keyword discovery run {run} finished: {status}.', ['run' => $run->publicId, 'status' => $status]);
			}
		}

		$report['projects'] = array_values($report['projects']);
		$report['cost'] = round($report['cost'], 6);

		return $report;
	}

	/** Limit elementów kolejnej strony seeda (górna granica metody i limit przebiegu na seed). */
	public function pageLimit(DiscoveryRun $run, int $offset): int
	{
		$items = min($run->seedLimit, $this->provider->maxResults($run->method, $run->depth) ?? $run->seedLimit);

		return max(1, min($this->provider->maxItemsPerRequest(), $items - $offset));
	}

	/**
	 * @param array<string, string|null> $seed
	 * @return array{cost: float, account_error: bool, error: ?string}
	 */
	private function request(DiscoveryRun $run, Market $market, array $seed, int $limit, float $estimate, ExclusionList $exclusions, CostBudget $budget): array
	{
		$seedText = (string) $seed['seed'];
		$seedHex = (string) $seed['seed_hex'];
		$offset = (int) $seed['next_offset'];
		$endpoint = $this->provider->endpoint($run->method);
		$taskId = $this->tasks->create($run->provider, $endpoint, MarketTaskRepository::TRIGGER_DISCOVERY, $run->projectId, $market, [$seedText], $estimate);
		$budget->spend($estimate);
		$this->runs->markStarted($run->id);
		$this->runs->seedStarted($run->id, $seedHex, $taskId);

		try {
			$batch = $this->provider->discover($market, new DiscoveryQuery(
				$run->method,
				$seedText,
				$limit,
				$offset,
				$run->depth,
				$run->minVolume,
				$run->maxDifficulty,
				$offset === 0,
			));
		} catch (ProviderException $exception) {
			return $this->failed($run, $seedHex, (int) $seed['attempts'], $taskId, $estimate, $exception);
		}

		$cost = $batch->cost ?? $estimate;
		$this->tasks->markCompleted($taskId, count($batch->items), $batch->cost);
		$stored = $this->store($run, $market, $seedText, $batch, $exclusions, $taskId);
		$nextOffset = $offset + $limit;
		$finished = count($batch->items) < $limit
			|| $nextOffset >= min($run->seedLimit, $this->provider->maxResults($run->method, $run->depth) ?? $run->seedLimit)
			|| $nextOffset >= $batch->totalCount
			|| (int) $seed['pages_done'] + 1 >= DiscoveryConfig::MAX_PAGES_PER_SEED;

		$this->runs->seedPage($run->id, $seedHex, count($batch->items), $stored['new'], $cost, $nextOffset, $finished);
		$this->runs->addProgress($run->id, 1, $cost, count($batch->items), $stored['new'], $stored['seen'], $stored['rejected']);

		return ['cost' => $cost, 'account_error' => false, 'error' => null];
	}

	/**
	 * @return array{cost: float, account_error: bool, error: ?string}
	 */
	private function failed(DiscoveryRun $run, string $seedHex, int $attempts, int $taskId, float $estimate, ProviderException $exception): array
	{
		$category = $exception->category();
		// Sieć, 5xx, uszkodzona odpowiedź: dostawca mógł wykonać (i opłacić) żądanie — koszt szacowany zostaje w limicie.
		$charged = in_array($category, [ProviderErrorCategory::Network, ProviderErrorCategory::Transient, ProviderErrorCategory::MalformedResponse], true);
		$this->tasks->markFailed($taskId, $category, $exception->getMessage(), $charged);
		$cost = $charged ? $estimate : 0.0;

		$this->logger->warning('Keyword discovery request of run {run} failed: {category}.', ['run' => $run->publicId, 'category' => $category->value]);

		if ($category->isAccountLevel()) {
			$this->market->pause($category);
			$this->runs->seedRetry($run->id, $seedHex, $this->offset(MarketDataConfig::PAUSE_AFTER_ACCOUNT_ERROR), $category->value);
			$this->runs->block($run->id, 'paused');

			return ['cost' => $cost, 'account_error' => true, 'error' => $category->value];
		}

		if ($category === ProviderErrorCategory::RateLimited && $attempts + 1 < DiscoveryConfig::RATE_LIMIT_ATTEMPTS) {
			$this->runs->seedRetry($run->id, $seedHex, $this->offset(self::RATE_LIMIT_RETRY), $category->value);

			return ['cost' => $cost, 'account_error' => false, 'error' => $category->value];
		}

		$this->runs->seedFailed($run->id, $seedHex, $category->value, $cost);
		$this->runs->addFailedTask($run->id, $cost, $category->value, $exception->getMessage());

		return ['cost' => $cost, 'account_error' => false, 'error' => $category->value];
	}

	/**
	 * Zapis wyników żądania: filtry jakości, metryki do wspólnej frazy rynkowej (tylko brakujące lub po TTL), kandydaci
	 * (z limitem przebiegu) i źródła fraza × seed.
	 *
	 * @return array{new: int, seen: int, rejected: array<string, int>}
	 */
	private function store(DiscoveryRun $run, Market $market, string $seed, DiscoveryBatch $batch, ExclusionList $exclusions, int $taskId): array
	{
		$rejected = [];
		$accepted = [];
		$items = $batch->seed === null ? $batch->items : [$batch->seed, ...$batch->items];

		foreach ($items as $item) {
			$keyword = MarketKeyword::normalize($item->keyword);
			$isSeed = $item === $batch->seed;
			$reason = match (true) {
				$keyword === '' => 'invalid',
				isset($accepted[$keyword]) => 'duplicate',
				$exclusions->match($keyword) !== null => 'excluded',
				$run->skipsOtherLanguage() && $item->isAnotherLanguage === true => 'other_language',
				$run->minVolume > 0 && ($item->searchVolume === null || $item->searchVolume < $run->minVolume) => 'min_volume',
				$run->maxDifficulty !== null && $item->keywordDifficulty !== null && $item->keywordDifficulty > $run->maxDifficulty => 'max_difficulty',
				default => null,
			};

			if ($reason !== null) {
				$rejected[$reason] = ($rejected[$reason] ?? 0) + 1;

				continue;
			}

			$accepted[$keyword] = ['item' => $item, 'seed' => $isSeed];
		}

		if ($accepted === []) {
			return ['new' => 0, 'seen' => 0, 'rejected' => $rejected];
		}

		$ids = $this->reuseMetrics($market, $accepted, $taskId);
		$entries = [];

		foreach ($accepted as $keyword => ['item' => $item, 'seed' => $isSeed]) {
			// Klucze tablic PHP zamieniają frazy liczbowe („2024”) na int.
			$keyword = (string) $keyword;

			if (isset($ids[$keyword])) {
				$entries[] = [
					'market_id' => $ids[$keyword],
					'relation' => Relation::of($run->method, $seed, $keyword, $item->depth, $isSeed),
					'depth' => $isSeed ? 0 : $item->depth,
					'position' => $isSeed ? null : $item->position,
					'meta' => $item->meta(),
				];
			}
		}

		$current = $this->runs->findById($run->id);
		$allowNew = max(0, $run->maxCandidates - ($current?->candidatesNew ?? $run->candidatesNew));
		$result = $this->candidates->upsertBatch($run->projectId, $run->id, $run->method, $seed, $entries, $allowNew);

		if ($result['limited'] > 0) {
			$rejected['limit'] = ($rejected['limit'] ?? 0) + $result['limited'];
		}

		return ['new' => $result['new'], 'seen' => $result['seen'], 'rejected' => $rejected];
	}

	/**
	 * Metryki z odpowiedzi discovery trafiają do wspólnych `market_keywords` (`KeywordMetricsWriter`: tylko brakujące lub
	 * po TTL — świeży wolumen Google Ads ze STEP 12 nie jest nadpisywany). Kandydaci nie wymagają osobnych płatnych
	 * żądań wzbogacających.
	 *
	 * @param array<string, array{item: DiscoveredKeyword, seed: bool}> $accepted
	 * @return array<string, int> postać znormalizowana → id frazy rynkowej
	 */
	private function reuseMetrics(Market $market, array $accepted, int $taskId): array
	{
		return (new KeywordMetricsWriter($this->metrics, $this->marketConfig))
			->store($market, array_map(static fn (array $entry): DiscoveredKeyword => $entry['item'], $accepted), $taskId);
	}

	private function offset(int $seconds): string
	{
		return $this->clock->now()->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
	}
}
