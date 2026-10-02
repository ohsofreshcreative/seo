<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Discovery\KeywordMetricsWriter;
use OsfSeo\Market\CostBudget;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\MarketTaskRepository;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Serp\SerpDictionary;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;

/**
 * Wykonanie przebiegów importu — jedyne miejsce, które wysyła płatne żądania Luk SEO. Wywoływane wyłącznie pod wspólną
 * blokadą płatnych żądań DataForSEO (`MarketSyncService::LOCK`), w tle albo z CLI; nigdy przy renderowaniu strony.
 *
 * Każda strona (domena × offset): kontrola wspólnego limitu (dzienny, miesięczny) → wiersz w rejestrze kosztów
 * (`market_tasks`, `trigger_type = gap`) i znacznik żądania w locie → wywołanie → metryki do wspólnych `market_keywords`
 * (tylko brakujące lub po TTL), URL-e do słownika `serp_urls`, pozycje do zbioru domeny → przesunięcie offsetu.
 *
 * Błędy: limit żądań dostawcy → strona wraca do kolejki (do 3 prób, nic nie zostało opłacone); błąd konta → wstrzymanie
 * (wspólne z danymi rynkowymi); sieć/5xx/uszkodzona odpowiedź → domena kończy się jako niepełna BEZ ponawiania (żądanie
 * mogło zostać opłacone). Limit kosztów → przebieg wstrzymany (pobrane strony zostają), wznawia się, gdy limit pozwoli.
 */
final class GapImporter
{
	public const TRIGGER = MarketTaskRepository::TRIGGER_GAP;

	public function __construct(
		private readonly CompetitorKeywordsProvider $provider,
		private readonly GapRunRepository $runs,
		private readonly GapDomainRepository $domains,
		private readonly MarketMetricsRepository $metrics,
		private readonly MarketTaskRepository $tasks,
		private readonly SerpDictionary $dictionary,
		private readonly MarketSyncService $market,
		private readonly MarketDataConfig $marketConfig,
		private readonly GapConfig $config,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Najwyżej $maxRequests płatnych żądań w budżecie czasu (aktywne przebiegi od najstarszego albo jeden wskazany).
	 *
	 * @return array{requests: int, cost: float, finished: array<int, string>, projects: list<int>, blocked_by: ?string, error: ?string}
	 */
	public function process(int $maxRequests, float $budgetSeconds, ?int $onlyRunId = null): array
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

			while (true) {
				$target = $this->runs->nextTarget($run->id);

				if ($target === null) {
					break;
				}

				$domainId = (int) $target['domain_id'];
				$dataset = $this->domains->findById($domainId);

				if ($dataset === null) {
					$this->runs->targetFinished($run->id, $domainId, GapRunRepository::TARGET_FAILED, 0, 0, 0, 0, [], 'missing_dataset');

					continue;
				}

				$coverage = $this->targetCoverage($run, (string) $target['role']);

				if ((int) $target['pages_done'] === 0 && ! $run->forced && $dataset->satisfies($coverage, $this->runs->now())) {
					// Inny projekt zaimportował ten zbiór w międzyczasie — bez żądań.
					$this->runs->targetCached($run->id, $domainId);
					$report['projects'][$run->projectId] = $run->projectId;

					continue;
				}

				if ($report['requests'] >= $maxRequests) {
					$report['blocked_by'] = CostBudget::TASK_LIMIT;

					break 2;
				}

				if (microtime(true) - $started >= $budgetSeconds) {
					break 2;
				}

				$offset = (int) $target['next_offset'];
				$limit = max(1, min($this->provider->maxItemsPerRequest(), min($coverage->maxRows, $this->provider->maxRowsPerDomain()) - $offset));
				$estimate = $this->provider->estimateCost($limit);
				$blocked = $budget->check($estimate);

				if ($blocked !== CostBudget::OK && $blocked !== CostBudget::TASK_LIMIT) {
					// Wspólny limit kosztów — przebieg czeka (pobrane strony zostają) i wznowi się, gdy limit na to pozwoli.
					$this->runs->pause($run->id, $blocked);
					$report['blocked_by'] = $blocked;
					$report['projects'][$run->projectId] = $run->projectId;

					continue 2;
				}

				$outcome = $this->request($run, $market, $target, $dataset, $coverage, $offset, $limit, $estimate, $budget);
				$report['requests']++;
				$report['cost'] += $outcome['cost'];
				$report['projects'][$run->projectId] = $run->projectId;

				if ($outcome['account_error']) {
					$report['error'] = $outcome['error'];

					break 2;
				}

				$run = $this->runs->findById($run->id) ?? $run;

				if (! $run->isActive()) {
					// Anulowany w trakcie żądania: bez kolejnych stron, rozpoczęty import zbioru domykamy jako niepełny.
					$this->closeOpenTargets();

					break;
				}
			}

			$status = $this->runs->finishIfDone($run->id);

			if ($status !== null) {
				$report['finished'][$run->id] = $status;
				$report['projects'][$run->projectId] = $run->projectId;
				$this->logger->info('Keyword gap run {run} finished: {status}.', ['run' => $run->publicId, 'status' => $status]);
			}
		}

		$report['projects'] = array_values($report['projects']);
		$report['cost'] = round($report['cost'], 6);

		return $report;
	}

	/**
	 * Utrzymanie (krok tła, bez żądań): żądania w locie starsze niż godzina → domena niepełna bez ponowienia, domeny
	 * przebiegów anulowanych z zewnątrz → domknięcie importu zbioru, przebiegi wstrzymane dłużej niż 7 dni → częściowe.
	 *
	 * @return array{interrupted: int, expired: int, closed: int}
	 */
	public function maintenance(): array
	{
		$report = ['interrupted' => 0, 'expired' => 0, 'closed' => 0];

		foreach ($this->runs->interrupted() as $target) {
			$runId = (int) $target['run_id'];
			$estimate = round((float) ($target['task_estimate'] ?? 0), 6);
			$this->runs->addFailure($runId, $estimate, 'interrupted', 'Request interrupted — not retried automatically.');
			$this->abort($runId, $target, 'interrupted');
			$this->runs->finishIfDone($runId);
			$report['interrupted']++;
		}

		foreach ($this->runs->expiredPaused() as $run) {
			foreach ($this->runs->targets($run->id) as $target) {
				if ($target['status'] === GapRunRepository::TARGET_RUNNING && $target['inflight_task_id'] === null) {
					$this->abort($run->id, $target + ['max_rank' => (string) $run->coverage->maxRank, 'min_volume' => (string) $run->coverage->minVolume, 'max_rows' => (string) $run->coverage->maxRows], 'expired');
				}
			}

			$this->runs->closePending($run->id, 'expired');
			$this->runs->finishIfDone($run->id);
			$report['expired']++;
		}

		$report['closed'] = $this->closeOpenTargets();

		return $report;
	}

	/** Zakres importu domeny (konkurent: zakres przebiegu; projekt: TOP100 z tym samym wolumenem i limitem fraz). */
	public function targetCoverage(GapRun $run, string $role): Coverage
	{
		$maxRows = min($run->coverage->maxRows, $this->provider->maxRowsPerDomain());

		return $role === PlannedTarget::ROLE_PROJECT
			? new Coverage(GapConfig::BASELINE_MAX_RANK, $run->coverage->minVolume, $maxRows)
			: new Coverage($run->coverage->maxRank, $run->coverage->minVolume, $maxRows);
	}

	/**
	 * @param array<string, string|null> $target
	 * @return array{cost: float, account_error: bool, error: ?string}
	 */
	private function request(GapRun $run, Market $market, array $target, GapDomain $dataset, Coverage $coverage, int $offset, int $limit, float $estimate, CostBudget $budget): array
	{
		$domainId = $dataset->id;
		$domain = $dataset->domain;

		if ($offset === 0 && ! $dataset->isImportingBy($run->id)) {
			$this->domains->startImport($domainId, $run->id);
		}

		$taskId = $this->tasks->create($run->provider, $this->provider->endpoint(), self::TRIGGER, $run->projectId, $market, [$domain], $estimate);
		$budget->spend($estimate);
		$this->runs->markStarted($run->id);
		$this->runs->targetStarted($run->id, $domainId, $taskId);

		try {
			$batch = $this->provider->rankedKeywords($market, new RankedKeywordsQuery($domain, $limit, $offset, $coverage->maxRank, $coverage->minVolume));
		} catch (ProviderException $exception) {
			return $this->failed($run, $target, $taskId, $estimate, $exception);
		}

		$cost = $batch->cost ?? $estimate;
		$this->tasks->markCompleted($taskId, count($batch->items), $batch->cost);
		$stored = $this->store($market, $dataset, $run->id, $batch, $taskId);
		$nextOffset = $offset + $limit;
		$lastVolume = $stored['last_volume'];
		// Fraza zapisana już na wcześniejszej stronie tego importu = przesunięcie stron u dostawcy → nieobecność niewiarygodna.
		$duplicates = $stored['duplicates'] + (int) ($target['error_code'] === 'duplicates');
		$finished = $batch->received < $limit
			|| $nextOffset >= $batch->totalCount
			|| $nextOffset >= min($coverage->maxRows, $this->provider->maxRowsPerDomain());

		$this->runs->targetPage($run->id, $domainId, $batch->received, $cost, $nextOffset, $batch->totalCount, $lastVolume, $duplicates);
		$this->runs->addProgress($run->id, 1, $cost, count($batch->items));

		if ($finished) {
			$fresh = $this->domains->findById($domainId) ?? $dataset;
			$result = $this->domains->finishImport(
				$fresh,
				$run->id,
				$coverage,
				max($batch->totalCount, $offset + $batch->received),
				$duplicates,
				$lastVolume ?? ($target['last_volume'] === null ? null : (int) $target['last_volume']),
				$this->config->ttlDays(),
			);
			$events = $this->domains->eventCounts($domainId, $run->id);
			$this->runs->targetFinished(
				$run->id,
				$domainId,
				GapRunRepository::TARGET_DONE,
				$result['rows_unique'],
				(int) ($events['new'] ?? 0),
				$result['rows_lost'],
				(int) (($events['up'] ?? 0) + ($events['down'] ?? 0) + ($events['url'] ?? 0)),
				$result['stats'] + ['complete' => $result['complete'] ? 1 : 0, 'covered_min_volume' => (int) $result['covered_min_volume'], 'total_count' => $batch->totalCount],
			);
		}

		return ['cost' => $cost, 'account_error' => false, 'error' => null];
	}

	/**
	 * @param array<string, string|null> $target
	 * @return array{cost: float, account_error: bool, error: ?string}
	 */
	private function failed(GapRun $run, array $target, int $taskId, float $estimate, ProviderException $exception): array
	{
		$domainId = (int) $target['domain_id'];
		$category = $exception->category();
		// Sieć, 5xx, uszkodzona odpowiedź: dostawca mógł wykonać (i opłacić) żądanie — koszt szacowany zostaje w limicie.
		$charged = in_array($category, [ProviderErrorCategory::Network, ProviderErrorCategory::Transient, ProviderErrorCategory::MalformedResponse], true);
		$this->tasks->markFailed($taskId, $category, $exception->getMessage(), $charged);
		$cost = $charged ? $estimate : 0.0;
		$this->logger->warning('Keyword gap request of run {run} failed: {category}.', ['run' => $run->publicId, 'category' => $category->value]);

		if ($category->isAccountLevel()) {
			$this->market->pause($category);
			$this->runs->targetRetry($run->id, $domainId, $this->offset(MarketDataConfig::PAUSE_AFTER_ACCOUNT_ERROR), $category->value);
			$this->runs->pause($run->id, 'paused');

			return ['cost' => $cost, 'account_error' => true, 'error' => $category->value];
		}

		if ($category === ProviderErrorCategory::RateLimited && (int) $target['attempts'] + 1 < GapConfig::RATE_LIMIT_ATTEMPTS) {
			$this->runs->targetRetry($run->id, $domainId, $this->offset(GapConfig::RATE_LIMIT_RETRY), $category->value);

			return ['cost' => $cost, 'account_error' => false, 'error' => $category->value];
		}

		$this->runs->addFailure($run->id, $cost, $category->value, $exception->getMessage());
		$this->abort($run->id, $target + ['max_rank' => (string) $run->coverage->maxRank, 'min_volume' => (string) $run->coverage->minVolume, 'max_rows' => (string) $run->coverage->maxRows], $category->value);

		return ['cost' => $cost, 'account_error' => false, 'error' => $category->value];
	}

	/**
	 * Domknięcie importu domeny jako niepełnego: zbiór zachowuje dotychczasowe dane (nic nie jest usuwane ani oznaczane
	 * jako utracone), domena przebiegu kończy się jako `partial` (część stron pobrana) albo `failed`.
	 *
	 * @param array<string, string|null> $target wiersz domeny przebiegu z zakresem przebiegu (`max_rank`, `min_volume`, `max_rows`)
	 */
	private function abort(int $runId, array $target, string $errorCode): void
	{
		$domainId = (int) $target['domain_id'];
		$dataset = $this->domains->findById($domainId);
		$pages = (int) $target['pages_done'];
		$result = ['rows_unique' => 0, 'stats' => []];

		if ($dataset !== null && $dataset->isImportingBy($runId)) {
			$run = $this->runs->findById($runId);
			$coverage = $run === null
				? new Coverage(max(1, min(100, (int) $target['max_rank'])), (int) $target['min_volume'], max(1, (int) $target['max_rows']))
				: $this->targetCoverage($run, (string) $target['role']);
			$result = $this->domains->abortImport(
				$dataset,
				$runId,
				$coverage,
				$target['total_count'] === null ? null : (int) $target['total_count'],
				$target['error_code'] === 'duplicates' ? 1 : 0,
				$target['last_volume'] === null ? null : (int) $target['last_volume'],
			);
		}

		$this->runs->targetFinished($runId, $domainId, $pages > 0 ? GapRunRepository::TARGET_PARTIAL : GapRunRepository::TARGET_FAILED, $result['rows_unique'], 0, 0, 0, $result['stats'], $errorCode);
	}

	/** Domknięcie rozpoczętych importów zbiorów w przebiegach anulowanych lub zakończonych z zewnątrz (bez żądań). */
	public function closeOpenTargets(): int
	{
		$closed = 0;

		foreach ($this->runs->openTargetsOfInactiveRuns() as $target) {
			$this->abort((int) $target['run_id'], $target, 'cancelled');
			$closed++;
		}

		return $closed;
	}

	/**
	 * Zapis strony: metryki do wspólnych fraz rynkowych, adresy do słownika, pozycje do zbioru domeny.
	 *
	 * @return array{duplicates: int, last_volume: ?int}
	 */
	private function store(Market $market, GapDomain $dataset, int $runId, RankedKeywordsBatch $batch, int $taskId): array
	{
		if ($batch->items === []) {
			return ['duplicates' => 0, 'last_volume' => null];
		}

		$keywords = [];

		foreach ($batch->items as $item) {
			$keywords[MarketKeyword::normalize($item->keyword->keyword)] = $item->keyword;
		}

		$ids = (new KeywordMetricsWriter($this->metrics, $this->marketConfig))->store($market, $keywords, $taskId);
		$hosts = $this->dictionary->domainIds(array_values(array_unique(array_map(static fn (RankedKeyword $item): string => $item->host, $batch->items))));
		$urls = [];

		foreach ($batch->items as $item) {
			if ($item->url !== null && isset($hosts[$item->host])) {
				$urls[md5($item->url)] = ['url' => $item->url, 'domain_id' => $hosts[$item->host]];
			}
		}

		$urlIds = $urls === [] ? [] : $this->dictionary->urlIds($urls);
		$entries = [];
		$lastVolume = null;

		foreach ($batch->items as $item) {
			$id = $ids[MarketKeyword::normalize($item->keyword->keyword)] ?? null;

			if ($id === null) {
				continue;
			}

			$entries[] = [
				'market_keyword_id' => $id,
				'rank_group' => $item->rankGroup,
				'rank_absolute' => $item->rankAbsolute,
				'url_id' => $item->url === null ? null : ($urlIds[md5($item->url)] ?? null),
				'etv' => $item->etv,
				'serp_on' => $item->serpUpdatedAt === null ? null : substr($item->serpUpdatedAt, 0, 10),
				'volume' => $item->keyword->searchVolume,
			];
		}

		// Ostatni element strony wyznacza granicę wolumenu (kolejność dostawcy: wolumen malejąco) — także gdy został odrzucony.
		foreach (array_reverse($batch->items) as $item) {
			if ($item->keyword->searchVolume !== null) {
				$lastVolume = $item->keyword->searchVolume;

				break;
			}
		}

		$result = $this->domains->storePage($dataset, $runId, $entries);
		$titles = [];

		// Tytuł strony z najlepszej pozycji na stronie wyników (wyniki są już po jednym na frazę).
		foreach ($batch->items as $item) {
			$urlId = $item->url === null ? null : ($urlIds[md5($item->url)] ?? null);

			if ($urlId !== null && (! isset($titles[$urlId]) || $item->rankGroup < $titles[$urlId][0])) {
				$titles[$urlId] = [$item->rankGroup, (string) $item->title];
			}
		}

		$this->domains->storePages($dataset->id, array_map(static fn (array $title): string => $title[1], $titles));

		return ['duplicates' => $result['duplicates'], 'last_volume' => $lastVolume];
	}

	private function offset(int $seconds): string
	{
		return $this->clock->now()->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
	}
}
