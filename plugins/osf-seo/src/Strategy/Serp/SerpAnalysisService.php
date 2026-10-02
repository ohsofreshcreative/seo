<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpKeywordRules;
use OsfSeo\Serp\SerpPlan;
use OsfSeo\Serp\SerpPlanner;
use OsfSeo\Serp\SerpProvider;
use OsfSeo\Serp\SerpRun;
use OsfSeo\Serp\SerpRunRepository;
use OsfSeo\Serp\SerpSettingsRepository;
use OsfSeo\Serp\SerpStartResult;
use OsfSeo\Serp\SerpSubmitter;
use OsfSeo\Serp\SerpTrackingService;
use OsfSeo\Serp\TrackedKeywordRepository;
use OsfSeo\Strategy\StrategyConfig;
use OsfSeo\Strategy\StrategyKeywordRepository;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Ulid;

/**
 * Jednorazowa analiza SERP Strategii (STEP 16, faza B — docs/ARCHITECTURE.md, sekcja 15.12, D55–D56).
 *
 * - **Kwalifikacja**: aktywni kandydaci Strategii na rynku projektu, fraza zgodna z regułami pomiaru; domyślnie w kolejności Strategii
 *   (poziom źródła, wyświetlenia — od fazy C: liderzy tematów), albo jawnie wskazane frazy (ULID kandydata lub tekst).
 * - **Ponowne użycie**: świeży (≤ 30 dni) zgodny pomiar w kontekście projektu → bez nowego kosztu; pomiar zlecony w oknie ponownego
 *   sprawdzenia (6 h) → „w toku”; reszta (brak, nieaktualny, wygasły pomiar) → nowy pomiar. Najwyżej `OSF_SEO_STRATEGY_SERP_MAX_PER_RUN`
 *   nowych pomiarów na uruchomienie; jawny wybór ponad limit jest odrzucany (bez cichego obcinania).
 * - **Pomiar**: wyłącznie przez `SerpSubmitter` STEP 14 (rezerwacja kosztu we wspólnych limitach, blokady, `uncertain` bez ponawiania,
 *   odstęp pomiaru ręcznego wspólny z „Sprawdź pozycje teraz”) — frazy w statusie `analysis`: poza harmonogramem, listą Pozycji
 *   i miękkim limitem; dodanie do monitorowania (`serp:track`) zmienia je na `active` bez utraty historii.
 * - Bez automatycznego harmonogramu analiz, PAA, powiązanych wyszukiwań i nowych endpointów (sekcja 15.11).
 */
final class SerpAnalysisService
{
	public const TRIGGER = 'analysis';

	public const TRIGGER_MANUAL = 'manual';

	/** Uruchomienie z CLI — bez odstępu między uruchomieniami (operator konsoli), jak `serp:run`. */
	public const TRIGGER_CLI = 'cli';

	private const PAGE = 500;

	public function __construct(
		private readonly SerpIntelligence $intelligence,
		private readonly SerpProvider $provider,
		private readonly TrackedKeywordRepository $tracked,
		private readonly SerpSubmitter $submitter,
		private readonly SerpPlanner $planner,
		private readonly SerpRunRepository $runs,
		private readonly SerpSettingsRepository $settings,
		private readonly StrategyKeywordRepository $keywords,
		private readonly MarketSyncService $market,
		private readonly StrategyConfig $config,
		private readonly Connection $db,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Podgląd bez żadnego żądania i bez zapisu. Zawiera koszty — wymaga uprawnień do analizy (jak plan pomiaru Pozycji).
	 *
	 * @param list<string>|null $values ULID-y kandydatów albo teksty fraz; null — kolejność Strategii
	 */
	public function plan(ProjectContext $context, ?array $values = null): SerpAnalysisPlan
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);
		$project = $context->project();
		$market = $this->provider->resolveMarket($project->country, $project->language);
		$limit = $this->config->serpMaxPerRun();
		$explicit = $values !== null;

		if ($market === null) {
			return new SerpAnalysisPlan(null, null, SerpAnalysisPlan::UNSUPPORTED_MARKET, [], $limit, $explicit, 0.0, null);
		}

		$serpContext = $this->intelligence->analysisContext($context->projectId(), $market);
		$items = $explicit ? $this->explicitItems($context->projectId(), $market, $values) : $this->priorityItems($context->projectId(), $market, $limit);
		$measure = count(array_filter($items, static fn (array $item): bool => $item['action'] === SerpAnalysisPlan::MEASURE));
		$skip = match (true) {
			! $this->provider->isConfigured() => SerpAnalysisPlan::NOT_CONFIGURED,
			$items === [] => SerpAnalysisPlan::NO_CANDIDATES,
			$measure > $limit => SerpAnalysisPlan::OVER_RUN_LIMIT,
			$measure === 0 => SerpAnalysisPlan::NOTHING_TO_DO,
			default => null,
		};

		return new SerpAnalysisPlan(
			$market,
			$serpContext,
			$skip,
			$items,
			$limit,
			$explicit,
			$this->provider->estimateCost($serpContext),
			$this->market->budget()->toArray(),
			$this->market->paused() !== null,
			get_transient(SerpTrackingService::COOLDOWN_TRANSIENT . $context->projectId()) !== false,
		);
	}

	/**
	 * Zakolejkowanie analizy po ponownym przeliczeniu planu (nic nie jest wysyłane w tym wywołaniu — wysyłka w tle albo `execute` z CLI).
	 * Panel/CLI podaje liczbę zadań i koszt z potwierdzonego podglądu; plan większy lub droższy nie zostanie zakolejkowany.
	 *
	 * @param list<string>|null $values
	 * @return array{status: string, plan: SerpAnalysisPlan, run: ?SerpRun, reason: ?string}
	 */
	public function start(ProjectContext $context, ?array $values, ?int $expectedTasks, ?float $expectedCost, string $trigger = self::TRIGGER_MANUAL): array
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);
		$cooldown = SerpTrackingService::COOLDOWN_TRANSIENT . $context->projectId();
		$plan = $this->plan($context, $values);
		$result = static fn (string $status, ?SerpRun $run = null, ?string $reason = null): array => ['status' => $status, 'plan' => $plan, 'run' => $run, 'reason' => $reason];

		if ($trigger === self::TRIGGER_MANUAL && get_transient($cooldown) !== false) {
			return $result(SerpStartResult::RATE_LIMITED);
		}

		if ($plan->skipReason !== null) {
			return $result(match ($plan->skipReason) {
				SerpAnalysisPlan::NOT_CONFIGURED => SerpStartResult::NOT_CONFIGURED,
				SerpAnalysisPlan::UNSUPPORTED_MARKET => SerpStartResult::UNSUPPORTED_MARKET,
				SerpAnalysisPlan::NO_CANDIDATES => SerpStartResult::NO_KEYWORDS,
				default => $plan->skipReason,
			}, null, $plan->skipReason);
		}

		if (($expectedTasks !== null && $plan->tasks() > $expectedTasks) || ($expectedCost !== null && $plan->estimatedCost() > $expectedCost + 1e-6)) {
			return $result(SerpStartResult::PLAN_CHANGED);
		}

		if ($this->market->paused() !== null) {
			return $result(SerpStartResult::PAUSED);
		}

		if ($plan->blockedBy() !== null) {
			return $result(SerpStartResult::OVER_BUDGET, null, $plan->blockedBy());
		}

		$market = $plan->market ?? throw new \LogicException('Plan without market.');
		$serpContext = $plan->context ?? throw new \LogicException('Plan without context.');
		$measure = $plan->byAction(SerpAnalysisPlan::MEASURE);
		$prepared = $this->tracked->prepareAnalysis($context->projectId(), array_column($measure, 'market_keyword_id'), $context->userId());
		$keywords = [];

		foreach ($measure as $item) {
			$row = $prepared['rows'][$item['market_keyword_id']] ?? null;

			if ($row !== null) {
				$keywords[] = [
					'id' => $row['id'],
					'market_keyword_id' => $item['market_keyword_id'],
					'keyword' => $item['keyword'],
					'location_code' => $market->locationCode,
					'language_code' => $market->languageCode,
				];
			}
		}

		$serpPlan = new SerpPlan(
			$market,
			$serpContext,
			$this->settings->get($context->projectId())->frequency,
			null,
			$keywords,
			count($keywords),
			count($plan->byAction(SerpAnalysisPlan::PENDING)),
			$plan->costPerTask,
			$this->provider->maxTasksPerPost(),
			$this->market->budget()->toArray(),
		);
		$queued = $this->submitter->queue($context->projectId(), $serpPlan, self::TRIGGER, 'analysis:' . strtolower(Ulid::generate()), $context->userId());

		if ($queued['status'] !== 'queued') {
			// Nic nie zostało zarezerwowane — frazy wracają do stanu sprzed przygotowania (bez śladu nieudanej analizy).
			$this->tracked->rollbackAnalysis($context->projectId(), $prepared['created'], $prepared['changed']);

			return $result(match ($queued['status']) {
				'over_budget' => SerpStartResult::OVER_BUDGET,
				'locked' => SerpStartResult::LOCKED,
				default => SerpStartResult::NOTHING_TO_DO,
			}, null, $queued['blocked_by']);
		}

		if ($trigger === self::TRIGGER_MANUAL) {
			set_transient($cooldown, '1', SerpConfig::MANUAL_COOLDOWN);
		}

		$run = $this->runs->findById((int) $queued['run_id']);
		$this->logger->info('Strategy SERP analysis {run} of project {project} queued by user {user}: {tasks} tasks, max cost {cost}, {reused} measurements reused.', [
			'run' => $run?->publicId,
			'project' => $context->publicId(),
			'user' => $context->userId(),
			'tasks' => $queued['tasks'],
			'cost' => $queued['cost'],
			'reused' => count($plan->byAction(SerpAnalysisPlan::REUSE)),
		]);

		return $result(SerpStartResult::QUEUED, $run);
	}

	/**
	 * Stan analiz projektu: frazy w statusie `analysis` (z pomiarem i bez), ostatnie przebiegi analizy.
	 *
	 * @return array<string, mixed>
	 */
	public function status(ProjectContext $context, bool $withCost): array
	{
		$counts = $this->db->fetchRow(
			"SELECT COUNT(*) AS keywords, COALESCE(SUM(last_snapshot_id IS NOT NULL), 0) AS measured, MAX(last_checked_at) AS last_checked
			FROM `{$this->db->table('serp_tracked_keywords')}` WHERE project_id = %d AND status = 'analysis'",
			[$context->projectId()],
		) ?? [];
		$runs = array_map(
			static fn (SerpRun $run): array => $run->toArray($withCost),
			array_values(array_filter($this->runs->recent($context->projectId(), 50), static fn (SerpRun $run): bool => $run->trigger === self::TRIGGER)),
		);

		return [
			'analysis_keywords' => (int) ($counts['keywords'] ?? 0),
			'measured' => (int) ($counts['measured'] ?? 0),
			'last_checked_at' => $counts['last_checked'] ?? null,
			'runs' => array_slice($runs, 0, 10),
			'limit_per_run' => $this->config->serpMaxPerRun(),
		];
	}

	/**
	 * @param list<string> $values
	 * @return list<array<string, mixed>>
	 */
	private function explicitItems(int $projectId, Market $market, array $values): array
	{
		$items = [];
		$candidates = [];

		foreach ($this->keywords->resolve($projectId, $market, $values) as $value => $candidate) {
			if ($candidate === null || ! $candidate['active']) {
				$items[] = self::item($candidate['public_id'] ?? '', $candidate['market_keyword_id'] ?? 0, $candidate['keyword'] ?? (string) $value, SerpAnalysisPlan::REJECTED, $candidate === null ? 'not_candidate' : 'inactive_candidate');

				continue;
			}

			$candidates[$candidate['market_keyword_id']] = $candidate;
		}

		return [...$items, ...$this->classify($projectId, $market, array_values($candidates), PHP_INT_MAX)];
	}

	/**
	 * Kolejność Strategii: kolejne strony aktywnych kandydatów, aż do limitu nowych pomiarów.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function priorityItems(int $projectId, Market $market, int $limit): array
	{
		$items = [];
		$measure = 0;

		for ($offset = 0; $measure < $limit; $offset += self::PAGE) {
			$page = $this->keywords->ordered($projectId, $market, $offset, self::PAGE);

			if ($page === []) {
				break;
			}

			foreach ($this->classify($projectId, $market, $page, $limit - $measure) as $item) {
				$items[] = $item;
				$measure += $item['action'] === SerpAnalysisPlan::MEASURE ? 1 : 0;
			}

			if (count($page) < self::PAGE) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Klasyfikacja kandydatów: odrzucenie (reguły pomiaru), ponowne użycie świeżego zgodnego pomiaru, pomiar w toku, nowy pomiar
	 * (najwyżej `$maxMeasure` — reszta tej strony nie jest zwracana).
	 *
	 * @param list<array{public_id: string, market_keyword_id: int, keyword: string, active: bool, tier: ?int}> $candidates
	 * @return list<array<string, mixed>>
	 */
	private function classify(int $projectId, Market $market, array $candidates, int $maxMeasure): array
	{
		if ($candidates === []) {
			return [];
		}

		$ids = array_column($candidates, 'market_keyword_id');
		$latest = $this->intelligence->latest($projectId, $this->intelligence->analysisContext($projectId, $market), $ids);
		$rows = $this->tracked->rowsFor($projectId, $ids);
		$cutoff = $this->planner->cutoff();
		$now = $this->clock->now();
		$items = [];
		$measure = 0;

		foreach ($candidates as $candidate) {
			$id = $candidate['market_keyword_id'];
			$snapshot = $latest[$id] ?? null;
			$freshness = $snapshot === null ? null : SerpFreshness::of($snapshot['checked_at'], $now);
			$row = $rows[$id] ?? null;
			$rejection = SerpKeywordRules::rejection($candidate['keyword']);

			if ($rejection !== null) {
				$items[] = self::item($candidate['public_id'], $id, $candidate['keyword'], SerpAnalysisPlan::REJECTED, 'keyword_' . $rejection, $snapshot, $freshness, $row);
			} elseif ($freshness === SerpFreshness::FRESH) {
				$items[] = self::item($candidate['public_id'], $id, $candidate['keyword'], SerpAnalysisPlan::REUSE, 'fresh_measurement', $snapshot, $freshness, $row);
			} elseif ($row !== null && $row['last_requested_at'] !== null && $row['last_requested_at'] >= $cutoff) {
				$items[] = self::item($candidate['public_id'], $id, $candidate['keyword'], SerpAnalysisPlan::PENDING, 'requested_recently', $snapshot, $freshness, $row);
			} elseif ($measure < $maxMeasure) {
				$measure++;
				$items[] = self::item($candidate['public_id'], $id, $candidate['keyword'], SerpAnalysisPlan::MEASURE, $freshness === null ? 'no_measurement' : $freshness . '_measurement', $snapshot, $freshness, $row);
			}
		}

		return $items;
	}

	/**
	 * @param array{public_id: string, checked_at: string}|null $snapshot
	 * @param array{status: string}|null $row
	 * @return array{candidate: string, market_keyword_id: int, keyword: string, action: string, reason: string, snapshot: ?string, checked_at: ?string, freshness: ?string, tracking: ?string}
	 */
	private static function item(string $candidate, int $marketKeywordId, string $keyword, string $action, string $reason, ?array $snapshot = null, ?string $freshness = null, ?array $row = null): array
	{
		return [
			'candidate' => $candidate,
			'market_keyword_id' => $marketKeywordId,
			'keyword' => $keyword,
			'action' => $action,
			'reason' => $reason,
			'snapshot' => $snapshot['public_id'] ?? null,
			'checked_at' => $snapshot['checked_at'] ?? null,
			'freshness' => $freshness,
			'tracking' => $row['status'] ?? null,
		];
	}
}
