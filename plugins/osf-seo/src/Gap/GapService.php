<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Database\Connection;
use OsfSeo\Market\CostBudget;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Serp\Competitor;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Discovery\ExclusionList;
use OsfSeo\Support\Clock;
use OsfSeo\Support\DateRange;
use OsfSeo\Support\Logger;
use OsfSeo\Support\ValidationException;
use Throwable;

/**
 * Luki SEO dla panelu i CLI (docs/ARCHITECTURE.md, sekcja 14) — wyłącznie przez ProjectContext:
 *
 * - plan bez API (podgląd kosztu), uruchomienie = zakolejkowanie potwierdzonego planu (płatne żądania wysyła tło albo
 *   CLI), anulowanie, postęp, ponowne przeliczenie (bezpłatne),
 * - luki fraz, grupy (luki treści), strony konkurencji, szczegóły z dowodami, praca nad luką (status, notatka),
 * - ustawienia projektu, warianty marki konkurentów, harmonogram (domyślnie wyłączony).
 *
 * Mutacje, plan i koszty wymagają `osf_seo_manage_keyword_gap`; klient ma tylko odczyt. Płatny import obejmuje wyłącznie
 * skonfigurowanych konkurentów (nigdy automatycznie konkurentów organicznych). Limity kosztów, wstrzymanie po błędzie
 * konta i blokada płatnych żądań są wspólne z danymi rynkowymi, Nowymi frazami i Pozycjami SERP.
 */
final class GapService
{
	public const TRIGGER_MANUAL = 'manual';

	public const TRIGGER_CLI = 'cli';

	public const TRIGGER_SCHEDULE = 'schedule';

	private const COOLDOWN_TRANSIENT = 'osf_seo_gap_manual_';

	private const REFRESH_TRANSIENT = 'osf_seo_gap_refreshed';

	private const REFRESH_INTERVAL = 300;

	public function __construct(
		private readonly CompetitorKeywordsProvider $provider,
		private readonly GapPlanner $planner,
		private readonly GapImporter $importer,
		private readonly GapRefresher $refresher,
		private readonly GapRunRepository $runs,
		private readonly GapDomainRepository $domains,
		private readonly GapSettingsRepository $settings,
		private readonly GapReports $reports,
		private readonly CompetitorRepository $competitors,
		private readonly GapConfig $config,
		private readonly MarketSyncService $market,
		private readonly MarketDataConfig $marketConfig,
		private readonly ProjectGuard $guard,
		private readonly Connection $db,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	public function provider(): CompetitorKeywordsProvider
	{
		return $this->provider;
	}

	public function config(): GapConfig
	{
		return $this->config;
	}

	public function market(ProjectContext $context): ?Market
	{
		$project = $context->project();

		return $this->provider->resolveMarket($project->country, $project->language);
	}

	public function settings(ProjectContext $context): GapSettings
	{
		return $this->settings->get($context->projectId());
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function request(ProjectContext $context, array $input): GapRequest
	{
		return GapRequest::fromInput($input, $this->settings($context), $this->provider->maxRowsPerDomain());
	}

	/**
	 * Plan bez wywołań API (podgląd „Sprawdź koszt”, `gap:plan`).
	 *
	 * @throws \OsfSeo\Auth\AccessDenied
	 */
	public function plan(ProjectContext $context, GapRequest $request): GapPlan
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_GAP);

		return $this->planner->plan($context, $request);
	}

	/**
	 * Uruchomienie: zakolejkowanie przebiegu po ponownym przeliczeniu planu. Panel podaje liczbę żądań i maksymalny koszt
	 * z potwierdzonego podglądu — plan większy lub droższy nie zostanie zakolejkowany. Nic nie jest wysyłane w tym żądaniu.
	 *
	 * @throws \OsfSeo\Auth\AccessDenied
	 */
	public function start(ProjectContext $context, GapRequest $request, string $trigger, ?int $expectedRequests = null, ?float $expectedCost = null): GapStartResult
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_GAP);

		if (! $this->provider->isConfigured()) {
			return new GapStartResult(GapStartResult::NOT_CONFIGURED, null, null, implode(', ', $this->provider->configurationProblems()));
		}

		$lock = 'gap_start_' . $context->projectId();

		if (! $this->db->acquireLock($lock, 0)) {
			return new GapStartResult(GapStartResult::ALREADY_RUNNING);
		}

		try {
			$active = $this->runs->active($context->projectId());

			if ($active !== null) {
				return new GapStartResult(GapStartResult::ALREADY_RUNNING, null, $active);
			}

			if ($trigger === self::TRIGGER_MANUAL && get_transient(self::COOLDOWN_TRANSIENT . $context->projectId()) !== false) {
				return new GapStartResult(GapStartResult::RATE_LIMITED);
			}

			$plan = $this->planner->plan($context, $request);

			if ($plan->skipReason !== null) {
				return new GapStartResult($plan->skipReason === GapPlan::UNSUPPORTED_MARKET ? GapStartResult::UNSUPPORTED_MARKET : GapStartResult::NO_COMPETITORS, $plan);
			}

			if (($expectedRequests !== null && $plan->requests() > $expectedRequests) || ($expectedCost !== null && $plan->estimatedCost() > $expectedCost + 1e-6)) {
				return new GapStartResult(GapStartResult::PLAN_CHANGED, $plan);
			}

			if ($this->market->paused() !== null && $plan->requests() > 0) {
				return new GapStartResult(GapStartResult::PAUSED, $plan);
			}

			if ($plan->blockedBy() !== null) {
				return new GapStartResult(GapStartResult::OVER_BUDGET, $plan, null, $plan->blockedBy());
			}

			if ($plan->requests() === 0 && ! in_array(PlannedTarget::WAITING, array_map(static fn (PlannedTarget $target): string => $target->state, $plan->targets), true)) {
				// Wszystko z pamięci — bez przebiegu; luki przeliczamy od razu (bezpłatnie).
				$this->safeRefresh($context->projectId(), true);

				return new GapStartResult(GapStartResult::NOTHING_TO_DO, $plan);
			}

			if ($trigger === self::TRIGGER_MANUAL) {
				set_transient(self::COOLDOWN_TRANSIENT . $context->projectId(), '1', GapConfig::MANUAL_COOLDOWN);
			}

			$market = $plan->market ?? throw new \LogicException('Plan without market.');
			$domainIds = [];

			foreach ($plan->targets as $target) {
				$domainIds[$target->domain] = $this->domains->ensure($market, $target->domain)->id;
			}

			$run = $this->runs->create($context->projectId(), $plan, $domainIds, $trigger, $context->userId());
			$this->logger->info('Keyword gap run {run} of project {project} queued by user {user}: {targets} domains, {requests} requests, max cost {cost}.', [
				'run' => $run->publicId,
				'project' => $context->publicId(),
				'user' => $context->userId(),
				'targets' => count($plan->targets),
				'requests' => $plan->requests(),
				'cost' => $plan->estimatedCost(),
			]);

			return new GapStartResult(GapStartResult::QUEUED, $plan, $run);
		} finally {
			$this->db->releaseLock($lock);
		}
	}

	/**
	 * Wykonanie przebiegu w bieżącym procesie (CLI) — kolejne porcje żądań aż do końca przebiegu, limitu kosztów, błędu
	 * albo budżetu czasu — pod wspólną blokadą płatnych żądań. W panelu przebieg wykonuje tło.
	 *
	 * @return array<string, mixed>
	 */
	public function execute(ProjectContext $context, GapRun $run, float $maxSeconds = 300.0): array
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_GAP);

		if ($run->projectId !== $context->projectId()) {
			throw new \InvalidArgumentException('Run does not belong to the project.');
		}

		if (! $this->db->acquireLock(MarketSyncService::LOCK, 10)) {
			return ['status' => 'locked'];
		}

		$started = microtime(true);
		$report = ['requests' => 0, 'cost' => 0.0, 'blocked_by' => null, 'error' => null];

		try {
			do {
				$pass = $this->importer->process($this->config->maxRequestsPerTick(), max(1.0, $maxSeconds - (microtime(true) - $started)), $run->id);
				$report['requests'] += $pass['requests'];
				$report['cost'] += $pass['cost'];
				$report['blocked_by'] = $pass['blocked_by'];
				$report['error'] = $pass['error'];
				$current = $this->runs->findById($run->id);
			} while (
				$current !== null && $current->isActive() && $current->status !== GapRun::PAUSED && $pass['error'] === null
				&& ($pass['requests'] > 0 || $pass['finished'] !== []) && in_array($pass['blocked_by'], [null, CostBudget::TASK_LIMIT], true)
				&& microtime(true) - $started < $maxSeconds
			);
		} finally {
			$this->db->releaseLock(MarketSyncService::LOCK);
		}

		$this->safeRefresh($run->projectId, true);
		$report['cost'] = round($report['cost'], 6);
		$report['run'] = $this->runs->findById($run->id)?->toArray();

		return $report;
	}

	/**
	 * Krok w tle po kolejce synchronizacji (WP-Cron / `wp osf-seo sync:run`): utrzymanie (przerwane żądania, wygasłe
	 * wstrzymania), strony aktywnych przebiegów (najwyżej `OSF_SEO_GAP_MAX_REQUESTS_PER_TICK`, wspólne limity i blokada),
	 * harmonogram (jeśli włączony) i przeliczenie projektów ze zmienionymi danymi. Nigdy przy renderowaniu strony; błąd
	 * nie dotyka GSC ani innych modułów.
	 *
	 * @return array<string, mixed>
	 */
	public function runBackground(float $budgetSeconds = 20.0, bool $ignoreInterval = false): array
	{
		$report = ['maintenance' => null, 'processed' => null, 'scheduled' => [], 'refreshed' => []];

		if (! ProjectGuard::isSystemProcess()) {
			return $report;
		}

		$started = microtime(true);
		$touched = [];

		// Utrzymanie jest tanie i obejmuje też przebiegi już nieaktywne (żądanie w locie anulowanego przebiegu).
		$report['maintenance'] = $this->importer->maintenance();

		if ($this->runs->hasActive()) {
			if ($this->provider->isConfigured() && $this->market->paused() === null && $this->db->acquireLock(MarketSyncService::LOCK, 0)) {
				try {
					$report['processed'] = $this->importer->process($this->config->maxRequestsPerTick(), $budgetSeconds);
				} finally {
					$this->db->releaseLock(MarketSyncService::LOCK);
				}

				foreach ($report['processed']['finished'] as $runId => $status) {
					$run = $this->runs->findById((int) $runId);

					if ($run !== null) {
						$touched[$run->projectId] = true;
					}
				}
			}
		}

		$report['scheduled'] = $this->schedule();

		foreach (array_keys($touched) as $projectId) {
			if (microtime(true) - $started < $budgetSeconds) {
				$report['refreshed'][$projectId] = $this->safeRefresh($projectId, true);
			}
		}

		if ($ignoreInterval || get_transient(self::REFRESH_TRANSIENT) === false) {
			set_transient(self::REFRESH_TRANSIENT, '1', self::REFRESH_INTERVAL);

			// Sprawdzenie klucza danych jest tanie; przeliczenie tylko po zmianie danych (GSC, SERP, ustawienia, zbiory).
			foreach ($this->settings->projectIds() as $projectId) {
				if (! isset($touched[$projectId]) && microtime(true) - $started < $budgetSeconds) {
					$result = $this->safeRefresh($projectId, false);

					if ($result !== null && ! $result['skipped']) {
						$report['refreshed'][$projectId] = $result;
					}
				}
			}
		}

		return $report;
	}

	/**
	 * Bezpłatne przeliczenie luk projektu (panel „Przelicz”, `gap:recalculate`).
	 *
	 * @return array{skipped: bool, keywords: int, listed: int, clusters: int, pages: int}
	 */
	public function recalculate(ProjectContext $context, bool $force = true): array
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_GAP);

		return $this->refresher->refresh($context->projectId(), $force);
	}

	/**
	 * @throws \OsfSeo\Auth\AccessDenied
	 */
	public function cancel(ProjectContext $context, string $publicId): bool
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_GAP);
		$run = $this->run($context, $publicId);
		$cancelled = $this->runs->cancel($run->id);

		if ($cancelled) {
			// Rozpoczęty import zbioru (bez żądania w locie) domykamy od razu jako niepełny; żądanie w locie domknie krok tła.
			$this->importer->closeOpenTargets();
			$this->logger->info('Keyword gap run {run} cancelled by user {user}.', ['run' => $run->publicId, 'user' => $context->userId()]);
		}

		return $cancelled;
	}

	/**
	 * @throws GapNotFound
	 */
	public function run(ProjectContext $context, string $publicId): GapRun
	{
		return $this->runs->find($context->projectId(), strtoupper(trim($publicId))) ?? throw new GapNotFound();
	}

	public function activeRun(ProjectContext $context): ?GapRun
	{
		return $this->runs->active($context->projectId());
	}

	/**
	 * @return list<GapRun>
	 */
	public function runs(ProjectContext $context, int $limit = 10): array
	{
		return $this->runs->recent($context->projectId(), $limit);
	}

	/**
	 * Domeny przebiegu (postęp).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function runTargets(ProjectContext $context, GapRun $run): array
	{
		if ($run->projectId !== $context->projectId()) {
			throw new GapNotFound();
		}

		return array_map(static fn (array $row): array => [
			'domain' => (string) $row['domain'],
			'role' => (string) $row['role'],
			'competitor' => $row['competitor_name'],
			'status' => (string) $row['status'],
			'pages_done' => (int) $row['pages_done'],
			'rows_received' => (int) $row['rows_received'],
			'rows_unique' => (int) $row['rows_unique'],
			'total_count' => $row['total_count'] === null ? null : (int) $row['total_count'],
			'rows_new' => (int) $row['rows_new'],
			'rows_lost' => (int) $row['rows_lost'],
			'cost' => round((float) $row['cost'], 6),
			'estimated_cost' => round((float) $row['estimated_cost'], 6),
			'error_code' => $row['error_code'],
			'finished_at' => $row['finished_at'],
		], $this->runs->targets($run->id));
	}

	/**
	 * Aktywni konkurenci projektu (filtr listy luk, formularz importu).
	 *
	 * @return list<Competitor>
	 */
	public function competitors(ProjectContext $context): array
	{
		return $this->competitors->active($context->projectId());
	}

	/**
	 * Zbiory domen projektu (konkurenci + punkt odniesienia): stan, zakres, świeżość, ostatni import.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function datasets(ProjectContext $context): array
	{
		$market = $this->market($context);

		if ($market === null) {
			return [];
		}

		$projectDomain = DomainFamily::normalize($context->project()->domain);
		$entries = [];

		foreach ($this->competitors->active($context->projectId()) as $competitor) {
			$domain = DomainFamily::normalize($competitor->domain);

			if ($domain !== null) {
				$entries[$domain] = ['role' => PlannedTarget::ROLE_COMPETITOR, 'label' => $competitor->name, 'competitor' => $competitor];
			}
		}

		if ($projectDomain !== null) {
			$entries[$projectDomain] = ['role' => PlannedTarget::ROLE_PROJECT, 'label' => $context->project()->name, 'competitor' => null];
		}

		$datasets = $this->domains->forDomains($market, array_map('strval', array_keys($entries)));
		$imports = $this->runs->lastImports(array_map(static fn (GapDomain $dataset): int => $dataset->id, array_values($datasets)));
		$now = $this->clock->now()->format('Y-m-d H:i:s');
		$result = [];

		foreach ($entries as $domain => $entry) {
			$dataset = $datasets[(string) $domain] ?? null;
			$result[] = [
				'domain' => (string) $domain,
				'role' => $entry['role'],
				'label' => $entry['label'],
				'competitor_public_id' => $entry['competitor']?->publicId,
				'brand_terms' => $entry['competitor']?->brandTerms ?? '',
				'dataset' => $dataset?->toArray(),
				'status_label' => $dataset === null ? 'nie pobrano' : $dataset->statusLabel(),
				'fresh' => $dataset !== null && $dataset->isFresh($now),
				'last_import' => $dataset === null ? null : ($imports[$dataset->id] ?? null),
			];
		}

		return $result;
	}

	/**
	 * Lista luk fraz.
	 *
	 * @return array{rows: list<array<string, string|null>>, total: int}
	 */
	public function keywords(ProjectContext $context, GapFilters $filters): array
	{
		$settings = $this->settings($context);

		return $this->reports->keywords($context->projectId(), $filters, $this->competitorDatasetId($context, $filters->competitor), $settings->competitorMaxRank);
	}

	/**
	 * Liczniki przeglądu (nowe = luki pierwszy raz widziane od startu ostatniego importu, gdy był wcześniejszy).
	 *
	 * @return array<string, mixed>
	 */
	public function counts(ProjectContext $context): array
	{
		$finished = array_values(array_filter($this->runs->recent($context->projectId(), 5), static fn (GapRun $run): bool => $run->finishedAt !== null && in_array($run->status, [GapRun::COMPLETED, GapRun::PARTIAL], true)));
		// Nowe = pierwszy raz widziane od startu ostatniego importu (przeliczenie po nim); przy pierwszym imporcie — brak.
		$since = isset($finished[1]) ? ($finished[0]->startedAt ?? $finished[0]->createdAt) : null;

		return $this->reports->counts($context->projectId(), $since) + ['filter_reasons' => $this->reports->filterReasons($context->projectId())];
	}

	/**
	 * Szczegóły luki frazy: metryki, priorytet, dowody projektu (SERP, GSC, Labs), każdy konkurent, historia, grupa.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws GapNotFound
	 */
	public function keyword(ProjectContext $context, string $publicId): array
	{
		$row = $this->reports->keyword($context->projectId(), strtoupper(trim($publicId))) ?? throw new GapNotFound();
		$market = $this->market($context);
		$marketKeywordId = (int) $row['market_keyword_id'];
		$competitors = [];
		$domainIds = [];
		$baseline = null;
		$projectDomain = DomainFamily::normalize($context->project()->domain);

		if ($market !== null) {
			$active = array_values(array_filter($this->competitors->active($context->projectId()), static fn (Competitor $competitor): bool => DomainFamily::normalize($competitor->domain) !== null));
			$datasets = $this->domains->forDomains($market, [...array_map(static fn (Competitor $competitor): string => (string) DomainFamily::normalize($competitor->domain), $active), ...($projectDomain === null ? [] : [$projectDomain])]);
			$evidence = $this->reports->evidence(array_map(static fn (GapDomain $dataset): int => $dataset->id, array_values($datasets)), $marketKeywordId);

			foreach ($active as $competitor) {
				$dataset = $datasets[(string) DomainFamily::normalize($competitor->domain)] ?? null;
				$domainIds[] = $dataset?->id ?? 0;
				$competitors[] = ['competitor' => $competitor, 'dataset' => $dataset, 'row' => $dataset === null ? null : ($evidence[$dataset->id] ?? null)];
			}

			$baselineDataset = $projectDomain === null ? null : ($datasets[$projectDomain] ?? null);

			if ($baselineDataset !== null) {
				$baseline = ['dataset' => $baselineDataset, 'row' => $evidence[$baselineDataset->id] ?? null];
				$domainIds[] = $baselineDataset->id;
			}
		}

		$latest = $this->db->fetchValue("SELECT MAX(date) FROM `{$this->db->table('gsc_query_daily')}` WHERE project_id = %d", [$context->projectId()]);
		$window = $latest === null ? null : [DateRange::shift($latest, -($this->config->windowDays() - 1)), $latest];
		$project = $this->reports->projectEvidence($context->projectId(), $marketKeywordId, (string) $row['keyword_hex'], $window);
		$serpHosts = [];

		if ($project['serp'] !== null && $project['serp']['last_snapshot_id'] !== null) {
			foreach ($this->reports->snapshotHosts((int) $project['serp']['last_snapshot_id']) as $result) {
				foreach ($competitors as $index => $entry) {
					$domain = (string) DomainFamily::normalize($entry['competitor']->domain);

					if (DomainFamily::matches($result['host'], $domain) && ! isset($serpHosts[$index])) {
						$serpHosts[$index] = $result;
					}
				}
			}
		}

		return [
			'row' => $row,
			'score' => json_decode((string) $row['score'], true) ?: null,
			'competitors' => $competitors,
			'serp_competitors' => $serpHosts,
			'baseline' => $baseline,
			'evidence' => $project,
			'window' => $window,
			'events' => $this->domains->events(array_values(array_filter($domainIds)), $marketKeywordId),
			'settings' => $this->settings($context),
		];
	}

	/** Luka frazy po tekście (CLI). */
	public function keywordId(ProjectContext $context, string $value): ?string
	{
		if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', trim($value)) === 1) {
			return strtoupper(trim($value));
		}

		return $this->reports->keywordByKey($context->projectId(), bin2hex(MarketKeyword::key(MarketKeyword::normalize($value))));
	}

	/**
	 * @return array{rows: list<array<string, string|null>>, total: int}
	 */
	public function clusters(ProjectContext $context, ?string $content, string $status = '', string $q = '', string $sort = 'priority', int $page = 1): array
	{
		return $this->reports->clusters($context->projectId(), ContentGap::fromInput($content)?->value, in_array($status, ['', 'all', ...array_map(static fn (GapStatus $case): string => $case->value, GapStatus::cases())], true) ? $status : '', mb_substr(trim($q), 0, 100), $sort, max(1, $page));
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws GapNotFound
	 */
	public function cluster(ProjectContext $context, string $publicId): array
	{
		$cluster = $this->reports->cluster($context->projectId(), strtoupper(trim($publicId))) ?? throw new GapNotFound();
		$market = $this->market($context);
		$names = [];
		$domainIds = [];

		if ($market !== null) {
			foreach ($this->competitors->active($context->projectId()) as $competitor) {
				$domain = DomainFamily::normalize($competitor->domain);
				$dataset = $domain === null ? null : $this->domains->find($market, $domain);

				if ($dataset !== null) {
					$domainIds[] = $dataset->id;
					$names[$dataset->id] = $competitor->name;
				}
			}
		}

		return [
			'cluster' => $cluster,
			'keywords' => $this->reports->clusterKeywords($context->projectId(), (int) $cluster['id']),
			'urls' => array_map(static fn (array $row): array => $row + ['competitor_name' => $names[(int) $row['domain_id']] ?? null], $this->reports->clusterUrls($context->projectId(), (int) $cluster['id'], $domainIds, $this->settings($context)->competitorMaxRank)),
		];
	}

	/**
	 * @return array{rows: list<array<string, string|null>>, total: int}
	 */
	public function pages(ProjectContext $context, ?string $competitorPublicId, string $q = '', string $sort = 'gap', int $page = 1): array
	{
		$competitor = $competitorPublicId === null || $competitorPublicId === '' ? null : $this->competitors->find($context->projectId(), strtoupper($competitorPublicId));

		if ($competitorPublicId !== null && $competitorPublicId !== '' && $competitor === null) {
			return ['rows' => [], 'total' => 0];
		}

		return $this->reports->pages($context->projectId(), $competitor?->id, mb_substr(trim($q), 0, 200), $sort, max(1, $page));
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws GapNotFound
	 */
	public function page(ProjectContext $context, string $competitorPublicId, string $urlKey): array
	{
		$competitor = $this->competitors->find($context->projectId(), strtoupper(trim($competitorPublicId))) ?? throw new GapNotFound();
		$urlKey = strtolower(trim($urlKey));

		if (preg_match('/^[0-9a-f]{32}$/', $urlKey) !== 1) {
			throw new GapNotFound();
		}

		$page = $this->reports->page($context->projectId(), $competitor->id, $urlKey) ?? throw new GapNotFound();
		$market = $this->market($context);
		$domain = DomainFamily::normalize($competitor->domain);
		$dataset = $market === null || $domain === null ? null : $this->domains->find($market, $domain);

		return [
			'page' => $page,
			'competitor' => $competitor,
			'keywords' => $dataset === null ? [] : $this->reports->pageKeywords($context->projectId(), $dataset->id, (int) $page['url_id']),
		];
	}

	/**
	 * Praca nad luką (status, notatka) — pojedynczo albo zbiorczo, w obrębie projektu.
	 *
	 * @param list<string> $publicIds
	 */
	public function setStatus(ProjectContext $context, string $kind, array $publicIds, GapStatus $status, ?string $note = null): int
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_GAP);

		return $this->reports->setStatus($context->projectId(), $kind === 'cluster' ? 'cluster' : 'keyword', array_map(static fn (string $id): string => strtoupper(trim($id)), $publicIds), $status, $note, $context->userId());
	}

	/**
	 * Ustawienia projektu (bez API). Włączenie harmonogramu oznacza przyszłe płatne importy — wymaga potwierdzenia.
	 *
	 * @param array<string, mixed> $input
	 *
	 * @throws ValidationException
	 */
	public function saveSettings(ProjectContext $context, array $input): GapSettings
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_GAP);
		$current = $this->settings($context);
		$errors = [];
		$int = static fn (string $key): int|false => filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);
		$values = [];

		$rank = $int('competitor_max_rank');
		$values['competitor_max_rank'] = $rank !== false && in_array($rank, GapConfig::COMPETITOR_RANKS, true) ? $rank : $current->competitorMaxRank;
		$fetchRank = $int('fetch_max_rank');
		$values['fetch_max_rank'] = $fetchRank !== false && in_array($fetchRank, GapConfig::FETCH_RANKS, true) ? $fetchRank : $current->fetchMaxRank;

		foreach (['min_volume' => [0, 100000], 'fetch_min_volume' => [0, 100000], 'max_rows' => [100, $this->provider->maxRowsPerDomain()], 'refresh_days' => [7, 180]] as $key => [$min, $max]) {
			if (array_key_exists($key, $input) && $input[$key] !== '') {
				$value = $int($key);

				if ($value === false || $value < $min || $value > $max) {
					$errors[$key] = sprintf('Podaj liczbę od %d do %d.', $min, $max);
				} else {
					$values[$key] = $value;
				}
			}
		}

		if (array_key_exists('max_difficulty', $input)) {
			$kd = $int('max_difficulty');
			$values['max_difficulty'] = $input['max_difficulty'] === '' || $input['max_difficulty'] === null ? null : ($kd === false || $kd < 0 || $kd > 100 ? null : $kd);

			if ($input['max_difficulty'] !== '' && $input['max_difficulty'] !== null && ($kd === false || $kd < 0 || $kd > 100)) {
				$errors['max_difficulty'] = 'Podaj liczbę od 0 do 100 albo zostaw puste.';
			}
		}

		foreach (['include_terms', 'brand_terms'] as $key) {
			if (array_key_exists($key, $input)) {
				$values[$key] = ExclusionList::parse(is_string($input[$key]) ? $input[$key] : '')->toText();
			}
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$settings = $this->settings->save($context->projectId(), $values, $context->userId());
		$this->logger->info('Keyword gap settings of project {project} saved by user {user}.', ['project' => $context->publicId(), 'user' => $context->userId()]);

		return $settings;
	}

	/**
	 * Harmonogram odświeżania (domyślnie wyłączony). Włączenie wymaga potwierdzenia szacowanego kosztu miesięcznego.
	 *
	 * @throws ValidationException
	 */
	public function setSchedule(ProjectContext $context, bool $enabled, bool $confirmed): GapSettings
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_GAP);

		if ($enabled && ! $confirmed) {
			throw new ValidationException(['confirm' => 'Potwierdź szacowany koszt odświeżania.']);
		}

		$now = $this->clock->now()->format('Y-m-d H:i:s');

		return $this->settings->save($context->projectId(), $enabled
			? ['schedule_enabled' => 1, 'schedule_enabled_at' => $now, 'schedule_enabled_by' => $context->userId(), 'next_refresh_at' => $now, 'last_skip_reason' => null, 'last_skip_at' => null]
			: ['schedule_enabled' => 0, 'next_refresh_at' => null], $context->userId());
	}

	/**
	 * Szacowany miesięczny koszt odświeżania (plan z ustawień projektu, przeskalowany do 30 dni) — bez API.
	 */
	public function monthlyEstimate(ProjectContext $context): float
	{
		$settings = $this->settings($context);
		$plan = $this->planner->plan($context, new GapRequest([], $settings->fetchCoverage($this->provider->maxRowsPerDomain()), true, true));

		return round($plan->estimatedCost() * 30 / max(7, $settings->refreshDays), 4);
	}

	/**
	 * Warianty marki konkurenta (frazy markowe nie są lukami).
	 *
	 * @throws GapNotFound
	 */
	public function saveBrandTerms(ProjectContext $context, string $competitorPublicId, string $terms): Competitor
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_GAP);
		$competitor = $this->competitors->find($context->projectId(), strtoupper(trim($competitorPublicId))) ?? throw new GapNotFound();

		return $this->competitors->updateBrandTerms($competitor, ExclusionList::parse($terms)->toText(), $context->userId());
	}

	/**
	 * Harmonogram (krok tła): projekty z włączonym odświeżaniem, których termin minął. Cały maksymalny koszt musi zmieścić
	 * się w dzisiejszym i miesięcznym limicie — inaczej odświeżenie jest pomijane z powodem i ponawiane następnego dnia.
	 *
	 * @return array<int, string>
	 */
	private function schedule(): array
	{
		$result = [];

		foreach ($this->settings->dueProjects() as $projectId) {
			try {
				$publicId = (string) $this->db->fetchValue("SELECT public_id FROM `{$this->db->table('projects')}` WHERE id = %d", [$projectId]);
				$context = $this->guard->authorizeSystem($publicId);
				$settings = $this->settings->get($projectId);
				$plan = $this->planner->plan($context, new GapRequest([], $settings->fetchCoverage($this->provider->maxRowsPerDomain()), true, false));
				$tomorrow = $this->clock->now()->modify('+1 day')->format('Y-m-d H:i:s');

				if ($plan->skipReason !== null || $this->runs->active($projectId) !== null || ! $this->provider->isConfigured()) {
					$this->settings->recordSkip($projectId, $plan->skipReason ?? ($this->provider->isConfigured() ? 'already_running' : 'not_configured'), $tomorrow);
					$result[$projectId] = 'skipped';

					continue;
				}

				if ($plan->requests() === 0) {
					$this->settings->scheduleNext($projectId, $this->nextRefresh($plan));
					$result[$projectId] = 'fresh';

					continue;
				}

				if ($plan->estimatedCost() > $plan->remainingToday() + 1e-6 || $plan->estimatedCost() > $plan->remainingMonth() + 1e-6) {
					$this->settings->recordSkip($projectId, $plan->estimatedCost() > $plan->remainingMonth() + 1e-6 ? 'monthly_limit' : 'daily_limit', $tomorrow);
					$result[$projectId] = 'over_budget';

					continue;
				}

				$start = $this->start($context, $plan->request, self::TRIGGER_SCHEDULE);
				$this->settings->scheduleNext($projectId, $start->isQueued() ? $this->clock->now()->modify('+' . $settings->refreshDays . ' days')->format('Y-m-d H:i:s') : $tomorrow);
				$result[$projectId] = $start->status;
			} catch (Throwable $exception) {
				$this->logger->error('Keyword gap schedule of project {project} failed: {error}.', ['project' => $projectId, 'error' => $exception->getMessage()]);
				$result[$projectId] = 'error';
			}
		}

		return $result;
	}

	private function nextRefresh(GapPlan $plan): string
	{
		$stale = array_filter(array_map(static fn (PlannedTarget $target): ?string => $target->dataset?->staleAfter, $plan->targets));

		return $stale === [] ? $this->clock->now()->modify('+1 day')->format('Y-m-d H:i:s') : min($stale);
	}

	private function competitorDatasetId(ProjectContext $context, ?string $publicId): ?int
	{
		if ($publicId === null) {
			return null;
		}

		$competitor = $this->competitors->find($context->projectId(), $publicId);
		$market = $this->market($context);
		$domain = $competitor === null ? null : DomainFamily::normalize($competitor->domain);

		return $market === null || $domain === null ? null : $this->domains->find($market, $domain)?->id;
	}

	/**
	 * @return array{skipped: bool, keywords: int, listed: int, clusters: int, pages: int}|null
	 */
	private function safeRefresh(int $projectId, bool $force): ?array
	{
		try {
			return $this->refresher->refresh($projectId, $force);
		} catch (Throwable $exception) {
			$this->logger->error('Keyword gap recalculation of project {project} failed: {error}.', ['project' => $projectId, 'error' => $exception->getMessage()]);

			return null;
		}
	}
}
