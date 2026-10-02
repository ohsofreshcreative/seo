<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use OsfSeo\Support\ValidationException;
use Throwable;

/**
 * Pozycje SERP dla panelu i CLI (docs/ARCHITECTURE.md, sekcja 13) — wyłącznie przez ProjectContext.
 *
 * Żadna metoda wywoływana przy wyświetlaniu strony nie wysyła płatnych żądań: plan i podgląd kosztu są lokalne,
 * pomiar ręczny jest tylko kolejkowany (rezerwacja kosztu), a zlecenia wysyła tło (`runBackground`) albo CLI (`execute`).
 * Zmiany, koszty i pomiary wymagają `osf_seo_manage_serp_tracking`; odczyt — dostępu do projektu.
 */
final class SerpTrackingService
{
	public const TRIGGER_MANUAL = 'manual';

	public const TRIGGER_SCHEDULE = 'schedule';

	/** Pomiar z CLI — jak ręczny, ale bez odstępu między uruchomieniami (operator konsoli). */
	public const TRIGGER_CLI = 'cli';

	private const COOLDOWN_TRANSIENT = 'osf_seo_serp_manual_';

	private const SCHEDULE_PROJECTS_PER_RUN = 25;

	public function __construct(
		private readonly SerpProvider $provider,
		private readonly SerpPlanner $planner,
		private readonly SerpSubmitter $submitter,
		private readonly SerpCollector $collector,
		private readonly TrackedKeywordRepository $tracked,
		private readonly SerpRunRepository $runs,
		private readonly SerpSettingsRepository $settings,
		private readonly SerpContextRepository $contexts,
		private readonly CompetitorRepository $competitors,
		private readonly SerpReports $reports,
		private readonly SerpConfig $config,
		private readonly MarketSyncService $market,
		private readonly MarketMetricsRepository $metrics,
		private readonly ProjectGuard $guard,
		private readonly Connection $db,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	public function provider(): SerpProvider
	{
		return $this->provider;
	}

	public function config(): SerpConfig
	{
		return $this->config;
	}

	public function market(ProjectContext $context): ?Market
	{
		$project = $context->project();

		return $this->provider->resolveMarket($project->country, $project->language);
	}

	/**
	 * Szacowany maksymalny koszt jednego zadania dla każdej dostępnej głębokości (cennik konfigurowalny; koszt zgłoszony
	 * przez dostawcę po wykonaniu jest rozstrzygający).
	 *
	 * @return array<int, float>
	 */
	public function pricing(): array
	{
		$prices = [];

		foreach (SerpConfig::DEPTHS as $depth) {
			$prices[$depth] = $this->provider->estimateCost(new SerpContext(0, '', SerpDevice::Desktop, $depth));
		}

		return $prices;
	}

	/**
	 * Wstrzymanie płatnych wywołań DataForSEO po błędzie konta (wspólne dla wszystkich modułów).
	 *
	 * @return array{until: string, reason: string}|null
	 */
	public function paused(): ?array
	{
		return $this->market->paused();
	}

	public function activeRun(ProjectContext $context): ?SerpRun
	{
		return $this->runs->active($context->projectId());
	}

	public function settings(ProjectContext $context): SerpSettings
	{
		return $this->settings->get($context->projectId());
	}

	/**
	 * Podgląd kosztu ustawień (lokalnie): koszt pełnego pomiaru wszystkich monitorowanych fraz i koszt miesięczny.
	 */
	public function preview(ProjectContext $context, ?SerpFrequency $frequency = null, ?SerpDevice $device = null, ?int $depth = null): SerpPlan
	{
		return $this->planner->plan($context, $this->settings($context), null, $frequency, $device, $depth !== null && SerpConfig::validDepth($depth) ? $depth : null);
	}

	/**
	 * Zapis ustawień śledzenia. Włączenie płatnych pomiarów wymaga jawnego potwierdzenia (po podglądzie kosztu).
	 *
	 * @param array<string, mixed> $input enabled, frequency, device, depth, confirm
	 *
	 * @throws ValidationException
	 */
	public function saveSettings(ProjectContext $context, array $input): SerpSettings
	{
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);
		$current = $this->settings($context);
		$enabled = in_array($input['enabled'] ?? '', ['1', 1, true, 'on'], true);
		$frequency = SerpFrequency::tryFrom((string) ($input['frequency'] ?? '')) ?? $current->frequency;
		$device = SerpDevice::tryFrom((string) ($input['device'] ?? '')) ?? $current->device;
		$depth = (int) ($input['depth'] ?? $current->depth);
		$errors = [];

		if (! SerpConfig::validDepth($depth)) {
			$errors['depth'] = 'Wybierz głębokość: ' . implode(', ', SerpConfig::DEPTHS) . '.';
		}

		if ($enabled && ! $current->enabled && ! in_array($input['confirm'] ?? '', ['1', 1, true], true)) {
			$errors['confirm'] = 'Potwierdź włączenie płatnych pomiarów (koszt z podglądu).';
		}

		if ($enabled && ! $this->provider->isConfigured()) {
			$errors['enabled'] = 'DataForSEO nie jest skonfigurowane — nie można włączyć pomiarów.';
		}

		if ($enabled && $this->market($context) === null) {
			$errors['enabled'] = 'Rynek projektu nie jest obsługiwany.';
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$settings = $this->settings->save($context->projectId(), $enabled, $frequency, $device, $depth, $context->userId());
		$this->logger->info('SERP tracking settings of project {project} saved by user {user}: {tracking}, {frequency}, {device}, TOP{depth}.', [
			'project' => $context->publicId(),
			'user' => $context->userId(),
			'tracking' => $enabled ? 'enabled' : 'disabled',
			'frequency' => $frequency->value,
			'device' => $device->value,
			'depth' => $depth,
		]);

		return $settings;
	}

	/**
	 * Dodanie fraz do monitorowania — bez żadnego płatnego żądania. Źródła: ręcznie (tekst), Frazy GSC (tekst frazy
	 * z projektu), Nowe frazy (ULID kandydatów), Luki SEO (ULID luk) — zawsze ta sama fraza rynkowa, bez nowej tożsamości.
	 * Miękki limit `OSF_SEO_SERP_MAX_KEYWORDS`: przekroczenie = komunikat i brak zmian (bez obcinania listy).
	 *
	 * @param list<string>|string $input
	 * @return array{added: int, restored: int, existing: int, rejected: list<array{keyword: string, reason: string}>}
	 *
	 * @throws ValidationException
	 */
	public function addKeywords(ProjectContext $context, string $source, array|string $input): array
	{
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);
		$market = $this->market($context) ?? throw new ValidationException(['keywords' => 'Rynek projektu nie jest obsługiwany.']);
		$rejected = [];
		$entries = [];

		if ($source === 'discovery' || $source === 'gap') {
			$ids = array_values(array_filter(is_array($input) ? $input : [$input], static fn (mixed $id): bool => is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1));
			$table = $source === 'gap' ? 'gap_keywords' : 'discovery_candidates';

			foreach (array_chunk($ids, 500) as $chunk) {
				foreach ($this->db->fetchAll(
					"SELECT market_keyword_id FROM `{$this->db->table($table)}` WHERE project_id = %d AND public_id IN (" . Connection::placeholders($chunk) . ')',
					[$context->projectId(), ...$chunk],
				) as $row) {
					$entries[(int) $row['market_keyword_id']] = $source;
				}
			}
		} else {
			$texts = is_array($input) ? $input : (preg_split('/[\r\n,;]+/u', $input) ?: []);
			$keywords = [];

			foreach ($texts as $raw) {
				if (! is_string($raw) || trim($raw) === '') {
					continue;
				}

				if ($source === 'gsc' && ! $this->isGscKeyword($context->projectId(), $raw)) {
					$rejected[] = ['keyword' => mb_substr(trim($raw), 0, 200), 'reason' => 'not_found'];

					continue;
				}

				$keyword = MarketKeyword::normalize($raw);
				$reason = SerpKeywordRules::rejection($keyword);

				if ($reason !== null) {
					$rejected[] = ['keyword' => mb_substr($keyword, 0, 200), 'reason' => $reason];

					continue;
				}

				$keywords[$keyword] = true;
			}

			foreach ($this->metrics->ensure($market, array_map('strval', array_keys($keywords))) as $id) {
				$entries[$id] = $source === 'gsc' ? 'gsc' : 'manual';
			}
		}

		$this->assertWithinLimit($context->projectId(), array_keys($entries));
		$result = $this->tracked->add($context->projectId(), $entries, $context->userId());

		return $result + ['rejected' => $rejected];
	}

	/**
	 * @param list<string> $publicIds
	 */
	public function removeKeywords(ProjectContext $context, array $publicIds): int
	{
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);

		return $this->tracked->remove($context->projectId(), $publicIds);
	}

	/**
	 * Plan pomiaru bez API (podgląd „Sprawdź pozycje teraz”, `serp:plan`).
	 *
	 * @param list<string>|null $only
	 */
	public function plan(ProjectContext $context, ?array $only = null): SerpPlan
	{
		return $this->planner->plan($context, $this->settings($context), $only);
	}

	/**
	 * Pomiar ręczny: zakolejkowanie po ponownym przeliczeniu planu — nic nie jest wysyłane w tym żądaniu.
	 * Panel podaje liczbę zadań i koszt z potwierdzonego podglądu; plan większy lub droższy nie zostanie zakolejkowany.
	 *
	 * @param list<string>|null $only
	 */
	public function start(ProjectContext $context, ?int $expectedTasks = null, ?float $expectedCost = null, ?array $only = null, string $trigger = self::TRIGGER_MANUAL): SerpStartResult
	{
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);
		$cooldown = self::COOLDOWN_TRANSIENT . $context->projectId();

		if ($trigger === self::TRIGGER_MANUAL && get_transient($cooldown) !== false) {
			return new SerpStartResult(SerpStartResult::RATE_LIMITED);
		}

		$plan = $this->plan($context, $only);

		if ($plan->skipReason !== null) {
			return new SerpStartResult(match ($plan->skipReason) {
				SerpPlan::NOT_CONFIGURED => SerpStartResult::NOT_CONFIGURED,
				SerpPlan::UNSUPPORTED_MARKET => SerpStartResult::UNSUPPORTED_MARKET,
				SerpPlan::NO_KEYWORDS => SerpStartResult::NO_KEYWORDS,
				default => SerpStartResult::NOTHING_TO_DO,
			}, $plan);
		}

		if (($expectedTasks !== null && $plan->tasks() > $expectedTasks) || ($expectedCost !== null && $plan->estimatedCost() > $expectedCost + 1e-6)) {
			return new SerpStartResult(SerpStartResult::PLAN_CHANGED, $plan);
		}

		if ($this->market->paused() !== null) {
			return new SerpStartResult(SerpStartResult::PAUSED, $plan);
		}

		if ($plan->blockedBy() !== null) {
			return new SerpStartResult(SerpStartResult::OVER_BUDGET, $plan, null, $plan->blockedBy());
		}

		$queued = $this->submitter->queue($context->projectId(), $plan, self::TRIGGER_MANUAL, 'manual:' . strtolower(\OsfSeo\Support\Ulid::generate()), $context->userId());

		$status = match ($queued['status']) {
			'queued' => SerpStartResult::QUEUED,
			'over_budget' => SerpStartResult::OVER_BUDGET,
			'locked' => SerpStartResult::LOCKED,
			default => SerpStartResult::NOTHING_TO_DO,
		};

		if ($status !== SerpStartResult::QUEUED) {
			return new SerpStartResult($status, $plan, null, $queued['blocked_by']);
		}

		set_transient($cooldown, '1', SerpConfig::MANUAL_COOLDOWN);
		$run = $this->runs->findById((int) $queued['run_id']);
		$this->logger->info('SERP check {run} of project {project} queued by user {user}: {tasks} tasks, max cost {cost}.', [
			'run' => $run?->publicId,
			'project' => $context->publicId(),
			'user' => $context->userId(),
			'tasks' => $queued['tasks'],
			'cost' => $queued['cost'],
		]);

		return new SerpStartResult(SerpStartResult::QUEUED, $plan, $run);
	}

	/**
	 * Wykonanie zakolejkowanego przebiegu w bieżącym procesie (CLI) pod wspólną blokadą płatnych żądań.
	 *
	 * @return array<string, mixed>
	 */
	public function execute(ProjectContext $context, SerpRun $run, float $maxSeconds = 300.0): array
	{
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);

		if ($run->projectId !== $context->projectId()) {
			throw new \InvalidArgumentException('Run does not belong to the project.');
		}

		if (! $this->db->acquireLock(MarketSyncService::LOCK, 10)) {
			return ['status' => 'locked'];
		}

		try {
			$report = $this->submitter->submit(PHP_INT_MAX, $maxSeconds, $run->id);
		} finally {
			$this->db->releaseLock(MarketSyncService::LOCK);
		}

		$report['run'] = $this->runs->findById($run->id)?->toArray();

		return $report;
	}

	/**
	 * Odbiór wyników (bezpłatny) — CLI i krok w tle.
	 *
	 * @return array<string, int>
	 */
	public function collect(float $budgetSeconds = 20.0, ?int $limit = null): array
	{
		return $this->collector->collect($budgetSeconds, $limit);
	}

	/**
	 * Krok w tle po kolejce GSC (WP-Cron / `wp osf-seo sync:run`): odbiór wyników, a pod wspólną blokadą płatnych żądań —
	 * zaplanowanie pomiarów z harmonogramu i wysyłka zaplanowanych paczek. Nigdy przy renderowaniu strony.
	 *
	 * @return array<string, mixed>
	 */
	public function runBackground(float $budgetSeconds = 20.0): array
	{
		$report = ['collected' => null, 'scheduled' => [], 'submitted' => null];

		if (! ProjectGuard::isSystemProcess() || ! $this->provider->isConfigured()) {
			return $report;
		}

		$started = microtime(true);
		$report['collected'] = $this->collector->collect(max(1.0, $budgetSeconds / 2));

		if ($this->market->paused() !== null || ! $this->db->acquireLock(MarketSyncService::LOCK, 0)) {
			return $report;
		}

		try {
			$report['scheduled'] = $this->schedule();
			$report['submitted'] = $this->submitter->submit($this->config->maxPostsPerRun(), max(1.0, $budgetSeconds - (microtime(true) - $started)));
		} finally {
			$this->db->releaseLock(MarketSyncService::LOCK);
		}

		return $report;
	}

	/**
	 * Pomiary z harmonogramu: dla projektów z terminem — plan, pełny koszt w limicie albo pominięcie z powodem `budget`
	 * (bez arbitralnego częściowego pomiaru), termin chroniony unikalnym `slot_key`.
	 *
	 * @return array<string, string>
	 */
	public function schedule(): array
	{
		$result = [];

		foreach ($this->settings->due(self::SCHEDULE_PROJECTS_PER_RUN) as $projectId) {
			$publicId = (string) $this->db->fetchValue("SELECT public_id FROM `{$this->db->table('projects')}` WHERE id = %d", [$projectId]);

			try {
				$context = $this->guard->authorizeSystem($publicId);
			} catch (ProjectNotFound) {
				continue;
			}

			try {
				$result[$publicId] = $this->scheduleProject($context);
			} catch (Throwable $exception) {
				$this->logger->error('Scheduling SERP check of project {project} failed: {message}', ['project' => $publicId, 'message' => $exception->getMessage()]);
				$this->settings->skipped($projectId, 'internal_error', $this->offset(3600));
				$result[$publicId] = 'error';
			}
		}

		return $result;
	}

	private function scheduleProject(ProjectContext $context): string
	{
		$settings = $this->settings($context);
		$slot = (string) $settings->nextRunAt;
		$plan = $this->plan($context);
		$projectId = $context->projectId();

		if (in_array($plan->skipReason, [SerpPlan::NO_KEYWORDS, SerpPlan::NOTHING_TO_DO], true)) {
			$this->settings->nothingToDo($projectId, $slot, $settings->frequency);

			return 'nothing_to_do';
		}

		if ($plan->skipReason !== null || $plan->context === null) {
			$this->recordSkip($context, $plan, (string) $plan->skipReason, $this->offset(86400));

			return (string) $plan->skipReason;
		}

		if ($plan->blockedBy() !== null) {
			$this->recordSkip($context, $plan, (string) $plan->blockedBy(), $this->budgetReset((string) $plan->blockedBy()));

			return 'budget:' . $plan->blockedBy();
		}

		$queued = $this->submitter->queue($projectId, $plan, self::TRIGGER_SCHEDULE, 'auto:' . substr($slot, 0, 16), null);

		if ($queued['status'] === 'over_budget') {
			$this->recordSkip($context, $plan, (string) $queued['blocked_by'], $this->budgetReset((string) $queued['blocked_by']));

			return 'budget:' . $queued['blocked_by'];
		}

		if ($queued['status'] === 'locked') {
			return 'locked';
		}

		// Zakolejkowany, termin już zajęty (inny proces) albo nic do zrobienia — termin przesuwa się dalej.
		$this->settings->scheduled($projectId, $slot, $settings->frequency);

		return (string) $queued['status'];
	}

	private function recordSkip(ProjectContext $context, SerpPlan $plan, string $reason, string $retryAfter): void
	{
		$contextId = $plan->context === null ? 0 : (int) $this->contexts->ensure($plan->context)->id;
		$this->runs->create($context->projectId(), $contextId, self::TRIGGER_SCHEDULE, null, SerpRun::SKIPPED, $plan->tasks(), $plan->recent, $plan->estimatedCost(), null, $reason);
		$this->settings->skipped($context->projectId(), $reason, $retryAfter);
		$this->logger->warning('Scheduled SERP check of project {project} skipped: {reason} (estimated cost {cost}).', [
			'project' => $context->publicId(),
			'reason' => $reason,
			'cost' => $plan->estimatedCost(),
		]);
	}

	/** Kolejna próba po odnowieniu limitu: następna doba UTC (dzienny) albo pierwszy dzień miesiąca (miesięczny). */
	private function budgetReset(string $limit): string
	{
		$now = $this->clock->now();

		return $limit === 'monthly_limit'
			? $now->modify('first day of next month')->format('Y-m-d 00:05:00')
			: $now->modify('+1 day')->format('Y-m-d 00:05:00');
	}

	/**
	 * Anulowanie: niewysłane paczki nie zostaną zlecone (rezerwacja zwolniona); wysłane zadania zostają w kosztach.
	 *
	 * @throws \OsfSeo\Auth\AccessDenied
	 */
	public function cancel(ProjectContext $context, string $publicId): bool
	{
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);
		$run = $this->runs->find($context->projectId(), $publicId);

		if ($run === null || ! $run->isActive()) {
			return false;
		}

		return $this->submitter->cancelRest($run->id) > 0;
	}

	public function run(ProjectContext $context, string $publicId): SerpRun
	{
		return $this->runs->find($context->projectId(), $publicId) ?? throw new SerpNotFound();
	}

	/**
	 * @return list<SerpRun>
	 */
	public function recentRuns(ProjectContext $context, int $limit = 10): array
	{
		return $this->runs->recent($context->projectId(), $limit);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function progress(SerpRun $run, bool $withCost): array
	{
		return $run->toArray($withCost);
	}

	/**
	 * @return array<string, int|string|null>
	 */
	public function summary(ProjectContext $context): array
	{
		return $this->reports->summary($context->projectId());
	}

	/**
	 * Lista monitorowanych fraz: Pozycja SERP i zmiana (bieżący stan), najlepsze pozycje aktywnych konkurentów
	 * w ostatnim pomiarze i — osobno — średnia pozycja GSC z ostatnich 28 dni.
	 *
	 * @return array{rows: list<TrackedKeywordRow>, total: int, competitors: list<Competitor>}
	 */
	public function positions(ProjectContext $context, PositionsFilters $filters): array
	{
		$page = $this->reports->positions($context->projectId(), $filters);
		$competitors = $this->competitors->active($context->projectId());
		$this->decorate($context, $page['rows'], $competitors);

		return $page + ['competitors' => $competitors];
	}

	/**
	 * @param list<TrackedKeywordRow> $rows
	 * @param list<Competitor> $competitors
	 */
	private function decorate(ProjectContext $context, array $rows, array $competitors): void
	{
		if ($rows === []) {
			return;
		}

		$families = $this->families($competitors);
		$results = $this->reports->familyResults(array_values(array_filter(array_map(static fn (TrackedKeywordRow $row): ?int => $row->lastSnapshotId, $rows))), $families);
		$gsc = $this->reports->gscAverages($context->projectId(), array_map(static fn (TrackedKeywordRow $row): string => $row->keywordKey, $rows), SerpConfig::GSC_DAYS);

		foreach ($rows as $row) {
			foreach ($results[$row->lastSnapshotId ?? 0] ?? [] as $competitorId => $found) {
				$row->competitors[$competitorId] = ['best' => $found[0]['rank'], 'url' => $found[0]['url']];
			}

			$row->gscPosition = $gsc[$row->keywordKey]['position'] ?? null;
			$row->gscImpressions = $gsc[$row->keywordKey]['impressions'] ?? null;
		}
	}

	/**
	 * Szczegóły frazy: bieżący stan, historia pomiarów (z pozycjami konkurentów odtworzonymi z pełnych SERP-ów),
	 * wybrany pomiar (domyślnie ostatni) z pełnym TOP N i wyróżnieniem projektu oraz konkurentów.
	 *
	 * @return array<string, mixed>
	 */
	public function keyword(ProjectContext $context, string $publicId, ?string $snapshotId = null): array
	{
		$row = $this->reports->tracked($context->projectId(), $publicId) ?? throw new SerpNotFound();
		$competitors = $this->competitors->active($context->projectId());
		$this->decorate($context, [$row], $competitors);
		$contextId = $row->lastContextId;
		$history = $contextId === null ? [] : $this->reports->history($row->id, $contextId);
		$families = $this->families($competitors);
		$historyCompetitors = $this->reports->familyResults(array_map(static fn (array $item): int => (int) $item['id'], $history), $families);
		$snapshot = $snapshotId === null ? $this->reports->snapshot($row->id, null) : $this->reports->snapshot($row->id, $snapshotId);

		if ($snapshotId !== null && $snapshot === null) {
			throw new SerpNotFound();
		}

		$results = $snapshot === null ? [] : $this->reports->results((int) $snapshot['id']);
		$projectDomain = DomainFamily::normalize($context->project()->domain) ?? $context->project()->domain;

		foreach ($results as &$result) {
			$result['is_project'] = DomainFamily::matches((string) $result['host'], $projectDomain);
			$result['competitor'] = null;

			foreach ($competitors as $competitor) {
				if (DomainFamily::matches((string) $result['host'], $competitor->domain)) {
					$result['competitor'] = $competitor->name;

					break;
				}
			}
		}

		unset($result);

		return [
			'row' => $row,
			'context' => $contextId === null ? null : $this->contexts->find($contextId),
			'history' => $history,
			'history_competitors' => $historyCompetitors,
			'competitors' => $competitors,
			'snapshot' => $snapshot,
			'results' => $results,
			'project_domain' => $projectDomain,
		];
	}

	/**
	 * Stan modułu (CLI `serp:status`, nagłówek panelu).
	 *
	 * @return array<string, mixed>
	 */
	public function status(ProjectContext $context, bool $withCost): array
	{
		$plan = $this->preview($context);
		$active = $this->runs->active($context->projectId());
		$status = [
			'configured' => $this->provider->isConfigured(),
			'market' => $plan->market?->label(),
			'settings' => $this->settings($context)->toArray(),
			'summary' => $this->summary($context),
			'active_run' => $active?->toArray($withCost),
			'max_keywords' => $this->config->maxKeywords(),
			'competitors' => count($this->competitors->active($context->projectId())),
		];

		if ($withCost) {
			$status['cost_per_task'] = $plan->costPerTask;
			$status['full_measurement_cost'] = $plan->fullMeasurementCost();
			$status['monthly_cost'] = $plan->monthlyCost();
			$status['budget'] = $plan->budget;
			$status['paused'] = $this->market->paused();
		}

		return $status;
	}

	/**
	 * Bieżące Pozycje SERP fraz z listy Frazy (kolumna „Pozycja SERP”) — tylko frazy monitorowane. Klucz = tekst frazy;
	 * warianty GSC tej samej frazy rynkowej („Buty Damskie”, „buty damskie”) dostają ten sam pomiar.
	 *
	 * @param list<string> $keywords
	 * @return array<string, array{public_id: string, rank: ?int, found: ?bool, depth: ?int, checked_at: ?string}>
	 */
	public function ranksForKeywords(ProjectContext $context, array $keywords): array
	{
		$market = $this->market($context);

		if ($market === null || $keywords === []) {
			return [];
		}

		$keys = [];

		foreach ($keywords as $keyword) {
			$keys[(string) $keyword] = bin2hex(MarketKeyword::key(MarketKeyword::normalize((string) $keyword)));
		}

		$ranks = $this->reports->ranksForKeys($context->projectId(), $market, array_values(array_unique($keys)));
		$result = [];

		foreach ($keys as $keyword => $key) {
			if (isset($ranks[$key])) {
				$result[(string) $keyword] = $ranks[$key];
			}
		}

		return $result;
	}

	/**
	 * Rodziny domen konkurentów: ULID konkurenta → identyfikatory hostów (domena + subdomeny) w słowniku.
	 *
	 * @param list<Competitor> $competitors
	 * @return array<string, list<int>>
	 */
	public function families(array $competitors): array
	{
		$families = [];

		foreach ($competitors as $competitor) {
			$families[$competitor->publicId] = $this->reports->familyDomainIds($competitor->domain);
		}

		return $families;
	}

	/**
	 * ULID monitorowanej frazy z ULID-u albo tekstu frazy (CLI).
	 */
	public function trackedId(ProjectContext $context, string $value): ?string
	{
		if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1) {
			return $this->tracked->find($context->projectId(), $value)['public_id'] ?? null;
		}

		$id = $this->db->fetchValue(
			"SELECT STRAIGHT_JOIN t.public_id FROM `{$this->db->table('serp_tracked_keywords')}` t
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = t.market_keyword_id
			WHERE t.project_id = %d AND m.keyword_key = UNHEX(%s) LIMIT 1",
			[$context->projectId(), bin2hex(MarketKeyword::key(MarketKeyword::normalize($value)))],
		);

		return $id === null ? null : (string) $id;
	}

	private function isGscKeyword(int $projectId, string $keyword): bool
	{
		return $this->db->fetchValue(
			"SELECT 1 FROM `{$this->db->table('keywords')}` WHERE project_id = %d AND keyword_hash = UNHEX(%s) LIMIT 1",
			[$projectId, md5($keyword)],
		) !== null;
	}

	/**
	 * @param list<int> $marketKeywordIds
	 *
	 * @throws ValidationException
	 */
	private function assertWithinLimit(int $projectId, array $marketKeywordIds): void
	{
		$limit = $this->config->maxKeywords();
		$active = $this->tracked->activeCount($projectId);
		$new = 0;

		foreach (array_chunk($marketKeywordIds, 500) as $chunk) {
			$alreadyActive = (int) $this->db->fetchValue(
				"SELECT COUNT(*) FROM `{$this->db->table('serp_tracked_keywords')}` WHERE project_id = %d AND status = 'active' AND market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$projectId, ...$chunk],
			);
			$new += count($chunk) - $alreadyActive;
		}

		if ($active + $new > $limit) {
			throw new ValidationException(['keywords' => sprintf(
				'Limit monitorowanych fraz w projekcie: %d (obecnie %d). Możesz dodać jeszcze %d, a wybrano %d nowych. Zmień wybór albo limit (OSF_SEO_SERP_MAX_KEYWORDS).',
				$limit,
				$active,
				max(0, $limit - $active),
				$new,
			)]);
		}
	}

	private function offset(int $seconds): string
	{
		return $this->clock->now()->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
	}
}
