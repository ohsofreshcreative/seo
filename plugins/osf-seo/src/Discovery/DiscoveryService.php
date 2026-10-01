<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Support\Logger;
use OsfSeo\Support\ValidationException;
use Throwable;

/**
 * Wyszukiwanie nowych fraz dla panelu i CLI (docs/ARCHITECTURE.md, sekcja 12) — wyłącznie przez ProjectContext.
 *
 * - plan bez API (podgląd kosztu), uruchomienie = zakolejkowanie potwierdzonego planu (płatne żądania wysyła
 *   przetwarzanie w tle albo CLI), anulowanie, postęp,
 * - lista i szczegóły kandydatów, praca nad frazą (status, notatka), akcja zbiorcza, wykluczenia projektu,
 * - przeliczenie widoczności i priorytetu (bezpłatne).
 *
 * Mutacje i plan (koszty) wymagają `osf_seo_manage_keyword_discovery`; klient ma tylko odczyt listy i szczegółów.
 * Limity kosztów, wstrzymanie po błędzie konta i blokada płatnych żądań są wspólne z danymi rynkowymi (STEP 12).
 */
final class DiscoveryService
{
	public const TRIGGER_MANUAL = 'manual';

	public const TRIGGER_CLI = 'cli';

	private const COOLDOWN_TRANSIENT = 'osf_seo_discovery_manual_';

	private const REFRESH_TRANSIENT = 'osf_seo_discovery_refreshed';

	private const REFRESH_INTERVAL = 300;

	public function __construct(
		private readonly KeywordDiscoveryProvider $provider,
		private readonly DiscoveryPlanner $planner,
		private readonly DiscoveryRunner $runner,
		private readonly DiscoveryRefresher $refresher,
		private readonly SeedSuggester $suggester,
		private readonly DiscoveryRunRepository $runs,
		private readonly DiscoveryCandidateRepository $candidates,
		private readonly DiscoverySettingsRepository $settings,
		private readonly DiscoveryConfig $config,
		private readonly MarketSyncService $market,
		private readonly MarketDataConfig $marketConfig,
		private readonly MarketMetricsRepository $metrics,
		private readonly ProjectGuard $guard,
		private readonly Connection $db,
		private readonly Logger $logger,
	) {
	}

	public function provider(): KeywordDiscoveryProvider
	{
		return $this->provider;
	}

	public function config(): DiscoveryConfig
	{
		return $this->config;
	}

	public function market(ProjectContext $context): ?Market
	{
		$project = $context->project();

		return $this->provider->resolveMarket($project->country, $project->language);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function request(array $input): DiscoveryRequest
	{
		return DiscoveryRequest::fromInput($input, $this->config, fn (string $seed): bool => $this->provider->acceptsKeyword($seed));
	}

	/**
	 * Plan bez wywołań API (podgląd „Sprawdź koszt”, `discovery:plan`).
	 *
	 * @throws \OsfSeo\Auth\AccessDenied
	 */
	public function plan(ProjectContext $context, DiscoveryRequest $request): DiscoveryPlan
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_DISCOVERY);

		return $this->planner->plan($context, $request);
	}

	/**
	 * Uruchomienie: zakolejkowanie przebiegu po ponownym przeliczeniu planu. Panel podaje liczbę żądań i koszt
	 * z potwierdzonego podglądu — plan większy lub droższy nie zostanie zakolejkowany.
	 *
	 * @throws \OsfSeo\Auth\AccessDenied
	 */
	public function start(ProjectContext $context, DiscoveryRequest $request, string $trigger, ?int $expectedRequests = null, ?float $expectedCost = null): DiscoveryStartResult
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_DISCOVERY);

		if (! $this->provider->isConfigured()) {
			return new DiscoveryStartResult(DiscoveryStartResult::NOT_CONFIGURED, null, null, implode(', ', $this->provider->configurationProblems()));
		}

		$lock = 'discovery_start_' . $context->projectId();

		if (! $this->db->acquireLock($lock, 0)) {
			return new DiscoveryStartResult(DiscoveryStartResult::ALREADY_RUNNING);
		}

		try {
			$active = $this->runs->active($context->projectId());

			if ($active !== null) {
				return new DiscoveryStartResult(DiscoveryStartResult::ALREADY_RUNNING, null, $active);
			}

			if ($trigger === self::TRIGGER_MANUAL && get_transient(self::COOLDOWN_TRANSIENT . $context->projectId()) !== false) {
				return new DiscoveryStartResult(DiscoveryStartResult::RATE_LIMITED);
			}

			$plan = $this->planner->plan($context, $request);

			if ($plan->skipReason !== null) {
				return new DiscoveryStartResult(match ($plan->skipReason) {
					DiscoveryPlan::UNSUPPORTED_MARKET => DiscoveryStartResult::UNSUPPORTED_MARKET,
					DiscoveryPlan::NO_SEEDS => DiscoveryStartResult::NO_SEEDS,
					default => DiscoveryStartResult::NOT_CONFIGURED,
				}, $plan);
			}

			if (($expectedRequests !== null && $plan->requests() > $expectedRequests) || ($expectedCost !== null && $plan->estimatedCost() > $expectedCost + 1e-6)) {
				return new DiscoveryStartResult(DiscoveryStartResult::PLAN_CHANGED, $plan);
			}

			if ($plan->requests() === 0) {
				return new DiscoveryStartResult(DiscoveryStartResult::NOTHING_TO_DO, $plan);
			}

			if ($this->market->paused() !== null) {
				return new DiscoveryStartResult(DiscoveryStartResult::PAUSED, $plan);
			}

			if ($plan->blockedBy() !== null) {
				return new DiscoveryStartResult(DiscoveryStartResult::OVER_BUDGET, $plan, null, $plan->blockedBy());
			}

			if ($trigger === self::TRIGGER_MANUAL) {
				set_transient(self::COOLDOWN_TRANSIENT . $context->projectId(), '1', DiscoveryConfig::MANUAL_COOLDOWN);
			}

			$run = $this->runs->create($context->projectId(), $plan->market ?? throw new \LogicException('Plan without market.'), $plan, $trigger, $context->userId());
			$this->logger->info('Keyword discovery run {run} of project {project} queued by user {user}: {seeds} seeds, {requests} requests, max cost {cost}.', [
				'run' => $run->publicId,
				'project' => $context->publicId(),
				'user' => $context->userId(),
				'seeds' => count($plan->seeds),
				'requests' => $plan->requests(),
				'cost' => $plan->estimatedCost(),
			]);

			return new DiscoveryStartResult(DiscoveryStartResult::QUEUED, $plan, $run);
		} finally {
			$this->db->releaseLock($lock);
		}
	}

	/**
	 * Wykonanie przebiegu w bieżącym procesie (CLI): kolejne porcje po `MAX_TASKS_PER_RUN` żądań aż do końca przebiegu,
	 * limitu kosztów lub błędu — pod wspólną blokadą płatnych żądań. W panelu przebieg wykonuje tło.
	 *
	 * @return array<string, mixed>
	 */
	public function execute(ProjectContext $context, DiscoveryRun $run, float $maxSeconds = 300.0): array
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_DISCOVERY);

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
				$pass = $this->runner->process($this->marketConfig->maxTasksPerRun(), max(1.0, $maxSeconds - (microtime(true) - $started)), $run->id);
				$report['requests'] += $pass['requests'];
				$report['cost'] += $pass['cost'];
				$report['blocked_by'] = $pass['blocked_by'];
				$report['error'] = $pass['error'];
				$current = $this->runs->findById($run->id);
			} while (
				$current !== null && $current->isActive() && $pass['requests'] > 0 && $pass['error'] === null
				&& in_array($pass['blocked_by'], [null, \OsfSeo\Market\CostBudget::TASK_LIMIT], true)
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
	 * Krok w tle po przebiegu kolejki synchronizacji (WP-Cron / `wp osf-seo sync:run`): zamknięcie przerwanych seedów,
	 * żądania aktywnych przebiegów (najwyżej `MAX_TASKS_PER_RUN`, wspólne limity i blokada), przeliczenie kandydatów
	 * projektów ze zmienionymi danymi. Nigdy nie jest wywoływany przy renderowaniu strony; błąd nie dotyka GSC.
	 *
	 * @return array<string, mixed>
	 */
	public function runBackground(float $budgetSeconds = 20.0, bool $ignoreInterval = false): array
	{
		$report = ['interrupted' => 0, 'processed' => null, 'refreshed' => []];

		if (! ProjectGuard::isSystemProcess()) {
			return $report;
		}

		$report['interrupted'] = $this->runs->closeInterrupted();
		$touched = [];

		if ($this->runs->hasActive()) {
			foreach ($this->runs->activeRuns() as $run) {
				if ($this->runs->finishIfDone($run->id) !== null) {
					$touched[$run->projectId] = true;
				}
			}

			if ($this->provider->isConfigured() && $this->market->paused() === null && $this->db->acquireLock(MarketSyncService::LOCK, 0)) {
				try {
					$report['processed'] = $this->runner->process($this->marketConfig->maxTasksPerRun(), $budgetSeconds);
				} finally {
					$this->db->releaseLock(MarketSyncService::LOCK);
				}

				foreach ($report['processed']['projects'] as $projectId) {
					$touched[$projectId] = true;
				}
			}
		}

		foreach (array_keys($touched) as $projectId) {
			$report['refreshed'][$projectId] = $this->safeRefresh($projectId, false);
		}

		if ($ignoreInterval || get_transient(self::REFRESH_TRANSIENT) === false) {
			set_transient(self::REFRESH_TRANSIENT, '1', self::REFRESH_INTERVAL);

			// Sprawdzenie klucza danych jest tanie (kilka zapytań); przeliczenie tylko po zmianie danych GSC lub metryk.
			foreach ($this->candidates->projectIds() as $projectId) {
				if (! isset($touched[$projectId])) {
					$report['refreshed'][$projectId] = $this->safeRefresh($projectId, false);
				}
			}
		}

		return $report;
	}

	/**
	 * @throws \OsfSeo\Auth\AccessDenied
	 */
	public function cancel(ProjectContext $context, string $publicId): bool
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_DISCOVERY);
		$run = $this->runs->find($context->projectId(), $publicId) ?? throw new DiscoveryNotFound();
		$cancelled = $this->runs->cancel($run->id);

		if ($cancelled) {
			$this->logger->info('Keyword discovery run {run} cancelled by user {user}.', ['run' => $run->publicId, 'user' => $context->userId()]);
		}

		return $cancelled;
	}

	/**
	 * @throws DiscoveryNotFound
	 */
	public function run(ProjectContext $context, string $publicId): DiscoveryRun
	{
		return $this->runs->find($context->projectId(), $publicId) ?? throw new DiscoveryNotFound();
	}

	/**
	 * @return list<array<string, string|null>>
	 */
	public function runSeeds(DiscoveryRun $run): array
	{
		return $this->runs->seeds($run->id);
	}

	/**
	 * @return list<DiscoveryRun>
	 */
	public function recentRuns(ProjectContext $context, int $limit = 10): array
	{
		return $this->runs->recent($context->projectId(), $limit);
	}

	/**
	 * Stan modułu dla panelu i CLI (bez sekretów): dostawca, rynek, aktywny przebieg z postępem, liczby kandydatów,
	 * ustawienia; koszty i limity — tylko z uprawnieniem.
	 *
	 * @return array<string, mixed>
	 */
	public function status(ProjectContext $context): array
	{
		$market = $this->market($context);
		$active = $this->runs->active($context->projectId());
		$canManage = $context->can(Capabilities::MANAGE_KEYWORD_DISCOVERY);

		return [
			'provider' => $this->provider->label(),
			'configured' => $this->provider->isConfigured(),
			'missing' => $this->provider->configurationProblems(),
			'market' => $market?->label(),
			'active' => $active === null ? null : $this->progress($active, $canManage),
			'summary' => $market === null ? null : $this->candidates->summary($context->projectId(), $market),
			'refresh' => $this->settings->refreshState($context->projectId()),
			'paused' => $this->market->paused(),
			'budget' => $canManage ? $this->market->budget()->toArray() : null,
			'settings' => $this->config->effective(),
		];
	}

	/**
	 * Postęp przebiegu (JSON stanu w panelu).
	 *
	 * @return array<string, mixed>
	 */
	public function progress(DiscoveryRun $run, bool $withCost): array
	{
		$seeds = $this->runs->seeds($run->id);
		$done = count(array_filter($seeds, static fn (array $seed): bool => in_array($seed['status'], [DiscoveryRunRepository::SEED_DONE, DiscoveryRunRepository::SEED_CACHED, DiscoveryRunRepository::SEED_FAILED, DiscoveryRunRepository::SEED_CANCELLED], true)));
		$progress = [
			'id' => $run->publicId,
			'status' => $run->status,
			'status_label' => $run->statusLabel(),
			'active' => $run->isActive(),
			'seeds_total' => count($seeds),
			'seeds_done' => $done,
			'candidates_new' => $run->candidatesNew,
			'candidates_seen' => $run->candidatesSeen,
			'blocked_by' => $run->blockedBy,
			'error' => $run->errorCode,
		];

		if ($withCost) {
			$progress['cost'] = round($run->cost, 6);
			$progress['estimated_cost'] = round($run->estimatedCost, 6);
			$progress['requests_done'] = $run->tasksDone;
			$progress['requests_planned'] = $run->tasksPlanned;
		}

		return $progress;
	}

	public function list(ProjectContext $context, CandidateFilters $filters): ?CandidatePage
	{
		$market = $this->market($context);

		return $market === null ? null : $this->candidates->list($context->projectId(), $market, $filters);
	}

	/**
	 * Szczegóły kandydata: źródła i historia wolumenu (z wspólnej frazy rynkowej).
	 *
	 * @throws DiscoveryNotFound
	 */
	public function candidate(ProjectContext $context, string $publicId): CandidateRow
	{
		$candidate = $this->candidates->find($context->projectId(), $publicId) ?? throw new DiscoveryNotFound();
		$candidate->market->monthly = $this->metrics->history([$candidate->market->id])[$candidate->market->id] ?? [];

		return $candidate;
	}

	/**
	 * Praca nad frazą: status i notatka (kto i kiedy zmienił status).
	 *
	 * @param array<string, mixed> $input status, note
	 * @throws \OsfSeo\Auth\AccessDenied
	 * @throws DiscoveryNotFound
	 * @throws ValidationException
	 */
	public function update(ProjectContext $context, string $publicId, array $input): CandidateRow
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_DISCOVERY);
		$candidate = $this->candidates->find($context->projectId(), $publicId) ?? throw new DiscoveryNotFound();
		$status = is_string($input['status'] ?? null) ? CandidateStatus::tryFrom($input['status']) : null;
		$note = is_string($input['note'] ?? null) ? trim(str_replace("\r\n", "\n", $input['note'])) : '';
		$errors = [];

		if ($status === null) {
			$errors['status'] = 'Wybierz status z listy.';
		}

		if (mb_strlen($note) > DiscoveryCandidateRepository::NOTE_MAX_LENGTH) {
			$errors['note'] = sprintf('Notatka może mieć najwyżej %d znaków.', DiscoveryCandidateRepository::NOTE_MAX_LENGTH);
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$fields = ['note' => $note === '' ? null : $note];

		if ($status !== $candidate->status) {
			$fields['status'] = $status->value;
			$fields['status_changed_at'] = gmdate('Y-m-d H:i:s');
			$fields['status_changed_by'] = $context->userId() > 0 ? $context->userId() : null;
		}

		$this->candidates->updateWorkflow($context->projectId(), $candidate->id, $fields);
		$this->logger->info('Discovered keyword {candidate} of project {project} updated by user {user}: status {status}.', [
			'candidate' => $candidate->publicId,
			'project' => $context->publicId(),
			'user' => $context->userId(),
			'status' => $status->value,
		]);

		return $this->candidate($context, $publicId);
	}

	/**
	 * Zmiana statusu wielu fraz naraz (maks. 100).
	 *
	 * @param list<string> $publicIds
	 * @throws \OsfSeo\Auth\AccessDenied
	 * @throws ValidationException
	 */
	public function bulkUpdate(ProjectContext $context, array $publicIds, string $status): int
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_DISCOVERY);
		$target = CandidateStatus::tryFrom($status);

		if ($target === null || $publicIds === [] || count($publicIds) > 100) {
			throw new ValidationException(['status' => 'Zaznacz od 1 do 100 fraz i wybierz status.']);
		}

		return $this->candidates->bulkStatus($context->projectId(), array_values(array_filter($publicIds, 'is_string')), $target, $context->userId());
	}

	public function exclusions(ProjectContext $context): ExclusionList
	{
		return $this->settings->exclusions($context->projectId());
	}

	/**
	 * Wykluczone słowa projektu — po zapisie kandydaci są przeliczani od razu (bez API).
	 *
	 * @throws \OsfSeo\Auth\AccessDenied
	 */
	public function saveExclusions(ProjectContext $context, string $text): ExclusionList
	{
		$context->assertCan(Capabilities::MANAGE_KEYWORD_DISCOVERY);
		$list = ExclusionList::parse($text);
		$this->settings->saveExclusions($context->projectId(), $list, $context->userId());
		$this->safeRefresh($context->projectId(), true);

		return $list;
	}

	/**
	 * @return array{gsc: list<array{seed: string, clicks: int, impressions: int, position: float}>, opportunity: list<array{seed: string, type: string, priority: int}>}
	 */
	public function suggestions(ProjectContext $context): array
	{
		try {
			return $this->suggester->suggest($context);
		} catch (Throwable $exception) {
			$this->logger->warning('Seed suggestions for project {project} unavailable: {message}', ['project' => $context->publicId(), 'message' => $exception->getMessage()]);

			return ['gsc' => [], 'opportunity' => []];
		}
	}

	/** Przeliczenie widoczności i priorytetu (bezpłatne; CLI `discovery:refresh`). */
	public function refresh(ProjectContext $context, bool $force = true): int
	{
		return $this->refresher->refresh($context->projectId(), $force);
	}

	/**
	 * Reset property GSC: kandydaci, źródła i stan pracy zostają; widoczność wymaga ponownej oceny po nowym imporcie.
	 */
	public function onPropertyReset(int $projectId): void
	{
		$this->candidates->markVisibilityUnknown($projectId);
		$this->settings->invalidate($projectId);
	}

	/** Przeliczenie, którego błąd nie przerywa wywołującego (np. krok w tle, zapis wykluczeń). */
	private function safeRefresh(int $projectId, bool $force): ?int
	{
		try {
			return $this->refresher->refresh($projectId, $force);
		} catch (Throwable $exception) {
			$this->logger->error('Refreshing discovered keywords of project {project} failed: {message}', ['project' => $projectId, 'message' => $exception->getMessage()]);

			return null;
		}
	}
}
