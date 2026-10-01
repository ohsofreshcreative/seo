<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Synchronizacja danych rynkowych fraz (DataForSEO przez `KeywordMetricsProvider`) — jedyne miejsce, które wysyła
 * płatne żądania. Kontrolery, widoki i raporty wyłącznie czytają zapisane metryki.
 *
 * Bezpieczniki kosztów (docs/ARCHITECTURE.md, sekcja 11.6):
 * - plan liczony bez API (dry-run, podgląd w panelu); ręczny przebieg wymaga potwierdzenia liczby fraz z podglądu,
 * - tylko frazy bez metryk lub z metrykami starszymi niż TTL (domyślnie 30 dni), bez wolumenu oczekującego na wynik,
 * - twardy limit płatnych zadań na przebieg, lokalne limity kosztów dzienny i miesięczny (sprawdzane przed każdym zadaniem),
 * - jedna synchronizacja naraz w całej instalacji (GET_LOCK), ręczna — najwyżej raz na 5 minut na projekt,
 * - automatyczne odświeżanie tylko dla projektów po pierwszej jawnej synchronizacji (wdrożenie niczego nie uruchamia),
 * - błąd konta (logowanie, środki) wstrzymuje automatykę na 24 h; błąd przerywa przebieg (bez ponawiania w pętli).
 *
 * Awaria dostawcy nie wpływa na GSC: synchronizacja GSC, raporty i szanse SEO nie zależą od tej klasy.
 */
final class MarketSyncService
{
	public const TRIGGER_MANUAL = 'manual';

	public const TRIGGER_CLI = 'cli';

	public const TRIGGER_AUTO = 'auto';

	/** Wstrzymanie automatyki po błędzie konta: ['until' => UTC, 'reason' => kategoria]. */
	public const PAUSE_OPTION = 'osf_seo_market_pause';

	private const LOCK = 'market_sync';

	private const COOLDOWN_TRANSIENT = 'osf_seo_market_manual_';

	private const BACKGROUND_TRANSIENT = 'osf_seo_market_checked';

	private const BACKGROUND_INTERVAL = 300;

	private const COLLECT_PER_RUN = 10;

	private const AUTO_PROJECTS_PER_RUN = 3;

	/** Odstępy kolejnych prób odbioru wyniku zadania Standard (s): realizacja zwykle 1–3 h. */
	private const POLL_DELAYS = [300, 600, 1200, 1800, 3600];

	public function __construct(
		private readonly KeywordMetricsProvider $provider,
		private readonly MarketMetricsRepository $metrics,
		private readonly MarketTaskRepository $tasks,
		private readonly MarketSyncStateRepository $states,
		private readonly MarketCandidateSelector $selector,
		private readonly MarketKeyBackfill $backfill,
		private readonly MarketDataConfig $config,
		private readonly ProjectGuard $guard,
		private readonly Connection $db,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	public function provider(): KeywordMetricsProvider
	{
		return $this->provider;
	}

	public function config(): MarketDataConfig
	{
		return $this->config;
	}

	/** Rynek projektu (kraj i język z ustawień projektu); null — rynek nieobsługiwany przez dostawcę. */
	public function market(ProjectContext $context): ?Market
	{
		$project = $context->project();

		return $this->provider->resolveMarket($project->country, $project->language);
	}

	/** Limity kosztów z rejestru zadań: doba i miesiąc kalendarzowy w UTC. */
	public function budget(): CostBudget
	{
		$now = $this->clock->now();

		return new CostBudget(
			$this->config->dailyCostLimit(),
			$this->config->monthlyCostLimit(),
			$this->tasks->spentSince($now->format('Y-m-d 00:00:00')),
			$this->tasks->spentSince($now->format('Y-m-01 00:00:00')),
			$this->config->maxTasksPerRun(),
		);
	}

	/**
	 * Plan bez wywołań API (dry-run, podgląd w panelu). Uzupełnia tylko lokalne klucze rynkowe fraz projektu.
	 */
	public function plan(ProjectContext $context, ?int $limit = null, bool $force = false): SyncPlan
	{
		$market = $this->market($context);

		if ($market === null) {
			return SyncPlan::skipped(null, 'unsupported_market', $this->budget()->toArray());
		}

		$this->backfill->fillProject($context->projectId());
		$selection = $this->selector->select(
			$context,
			$market,
			$this->provider,
			max(1, min($limit ?? $this->config->syncLimit(), 100000)),
			$this->config->minImpressions(),
			$this->config->windowDays(),
			$this->metrics->now(),
			$force,
		);

		return SyncPlan::build($market, $this->provider, $selection['candidates'], $this->budget(), $selection['window'], $selection['rejected'], $selection['duplicates']);
	}

	/**
	 * Jawna (ręczna/CLI) albo automatyczna synchronizacja projektu.
	 *
	 * @param int|null $expectedKeywords liczba fraz z podglądu potwierdzonego w panelu — większy plan nie zostanie wysłany
	 *
	 * @throws \OsfSeo\Auth\AccessDenied brak uprawnienia do płatnej synchronizacji
	 */
	public function sync(ProjectContext $context, string $trigger, ?int $limit = null, bool $force = false, ?int $expectedKeywords = null): MarketSyncResult
	{
		$context->assertCan(Capabilities::MANAGE_MARKET_DATA);

		if (! $this->provider->isConfigured()) {
			return new MarketSyncResult(MarketSyncResult::NOT_CONFIGURED, null, implode(', ', $this->provider->configurationProblems()));
		}

		if (! $this->db->acquireLock(self::LOCK, 0)) {
			return new MarketSyncResult(MarketSyncResult::ALREADY_RUNNING);
		}

		try {
			if ($trigger === self::TRIGGER_MANUAL && get_transient(self::COOLDOWN_TRANSIENT . $context->projectId()) !== false) {
				return new MarketSyncResult(MarketSyncResult::RATE_LIMITED);
			}

			$plan = $this->plan($context, $limit, $force);

			if ($plan->skipReason !== null) {
				return new MarketSyncResult(MarketSyncResult::SKIPPED, $plan, $plan->skipReason);
			}

			if ($expectedKeywords !== null && $plan->keywordsToSend() > $expectedKeywords) {
				return new MarketSyncResult(MarketSyncResult::PLAN_CHANGED, $plan);
			}

			if ($trigger === self::TRIGGER_MANUAL) {
				set_transient(self::COOLDOWN_TRANSIENT . $context->projectId(), '1', MarketDataConfig::MANUAL_COOLDOWN);
			}

			if ($trigger !== self::TRIGGER_AUTO) {
				// Pierwsza jawna synchronizacja włącza automatyczne odświeżanie projektu.
				$this->states->enable($context->projectId(), $this->provider->name(), $context->userId());
			}

			return $this->execute($context, $plan, $trigger);
		} finally {
			$this->db->releaseLock(self::LOCK);
		}
	}

	/**
	 * Odbiór wyników zadań Standard (bezpłatny `task_get`), najwyżej $limit zadań.
	 *
	 * @return array{checked: int, completed: int, pending: int, failed: int, expired: int}
	 */
	public function collect(int $limit = self::COLLECT_PER_RUN): array
	{
		$report = ['checked' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0, 'expired' => 0];

		if (! $this->provider->isConfigured()) {
			return $report;
		}

		foreach ($this->tasks->duePending($limit) as $task) {
			$report['checked']++;
			$id = (int) $task['id'];
			$market = $this->taskMarket($task);
			$keywords = MarketTaskRepository::keywords($task);
			$projectId = $task['project_id'] === null ? null : (int) $task['project_id'];

			try {
				$batch = $this->provider->fetchVolume((string) $task['provider_task_id']);
			} catch (ProviderException $exception) {
				if ($exception->isRetryable() || $exception->category()->isAccountLevel()) {
					$this->tasks->reschedule($id, $this->offset(1800), $exception->category()->value);
					$report['pending']++;

					if ($exception->category()->isAccountLevel()) {
						$this->pause($exception->category());
					}
				} else {
					$this->tasks->markFailed($id, $exception->category(), $exception->getMessage(), true);
					$this->metrics->releaseVolumePending($market, $keywords, $id);
					$report['failed']++;
				}

				if ($projectId !== null) {
					$this->states->recordError($projectId, $this->provider->name(), $exception->category()->value);
				}

				continue;
			}

			if ($batch === null) {
				if ((string) $task['created_at'] <= $this->offset(-MarketDataConfig::PENDING_HOURS * 3600)) {
					$this->tasks->markExpired($id);
					$this->metrics->releaseVolumePending($market, $keywords, $id);
					$report['expired']++;
				} else {
					$attempt = (int) $task['attempts'];
					$this->tasks->reschedule($id, $this->offset(self::POLL_DELAYS[min($attempt, count(self::POLL_DELAYS) - 1)]));
					$report['pending']++;
				}

				continue;
			}

			[, $unmatched] = $this->storeVolume($market, $keywords, $batch, $id);
			$this->tasks->markCompleted($id, count($batch->items));
			$report['completed']++;

			if ($unmatched > 0) {
				$this->logger->info('DataForSEO volume task {task}: {unmatched} result keywords did not match requested keywords.', ['task' => $id, 'unmatched' => $unmatched]);
			}

			if ($projectId !== null) {
				$this->states->recordSuccess($projectId, $this->provider->name());
			}
		}

		return $report;
	}

	/**
	 * Krok w tle po przebiegu kolejki synchronizacji (WP-Cron / `wp osf-seo sync:run`, co 5 minut): klucze rynkowe fraz,
	 * odbiór wyników zadań, a potem — tylko dla projektów po pierwszej jawnej synchronizacji — odświeżenie
	 * brakujących i nieaktualnych metryk w ramach limitów. Nigdy nie jest wywoływany przy renderowaniu strony.
	 *
	 * @return array<string, mixed>
	 */
	public function runBackground(float $budgetSeconds = 20.0, bool $ignoreInterval = false): array
	{
		$report = ['keys' => 0, 'collected' => null, 'projects' => []];

		if (! ProjectGuard::isSystemProcess()) {
			return $report;
		}

		if (! $ignoreInterval && get_transient(self::BACKGROUND_TRANSIENT) !== false) {
			return $report;
		}

		set_transient(self::BACKGROUND_TRANSIENT, '1', self::BACKGROUND_INTERVAL);
		$started = microtime(true);
		$report['keys'] = $this->backfill->fillAll();
		$this->tasks->maintenance();

		if (! $this->provider->isConfigured() || ! $this->db->acquireLock(self::LOCK, 0)) {
			return $report;
		}

		try {
			$report['collected'] = $this->collect();

			if (! $this->config->autoRefresh() || $this->paused() !== null) {
				return $report;
			}

			foreach ($this->states->dueForAuto($this->provider->name(), self::AUTO_PROJECTS_PER_RUN) as $projectId) {
				if (microtime(true) - $started >= $budgetSeconds) {
					break;
				}

				$publicId = (string) $this->db->fetchValue("SELECT public_id FROM `{$this->db->table('projects')}` WHERE id = %d", [$projectId]);

				try {
					$context = $this->guard->authorizeSystem($publicId);
				} catch (ProjectNotFound) {
					continue;
				}

				try {
					$plan = $this->plan($context);
					$result = $plan->skipReason !== null
						? new MarketSyncResult(MarketSyncResult::SKIPPED, $plan, $plan->skipReason)
						: $this->execute($context, $plan, self::TRIGGER_AUTO);
				} catch (Throwable $exception) {
					$this->logger->error('Automatic market data refresh of project {project} failed: {message}', ['project' => $publicId, 'message' => $exception->getMessage()]);
					$this->states->recordRun($projectId, $this->provider->name(), 'internal_error', $this->offset(MarketDataConfig::PAUSE_AFTER_TRANSIENT_ERROR));

					continue;
				}

				$report['projects'][$publicId] = $result->toArray();

				if (in_array($result->blockedBy, [CostBudget::DAILY_LIMIT, CostBudget::MONTHLY_LIMIT], true) || $result->error?->isAccountLevel()) {
					break;
				}
			}
		} finally {
			$this->db->releaseLock(self::LOCK);
		}

		return $report;
	}

	/**
	 * Wstrzymanie automatyki po błędzie konta (null — brak).
	 *
	 * @return array{until: string, reason: string}|null
	 */
	public function paused(): ?array
	{
		$pause = get_option(self::PAUSE_OPTION);

		if (! is_array($pause) || ! is_string($pause['until'] ?? null) || $pause['until'] <= $this->metrics->now()) {
			return null;
		}

		return ['until' => $pause['until'], 'reason' => (string) ($pause['reason'] ?? '')];
	}

	/**
	 * Stan dla CLI i panelu (bez sekretów): konfiguracja, rynek projektu, liczby metryk, zadania, koszty, limity.
	 *
	 * @return array<string, mixed>
	 */
	public function status(?ProjectContext $context = null): array
	{
		$now = $this->clock->now();
		$market = $context === null ? null : $this->market($context);
		$budget = $this->budget();
		$state = $context === null
			? $this->states->latest($this->provider->name())
			: $this->states->get($context->projectId(), $this->provider->name());

		return [
			'provider' => $this->provider->label(),
			'configured' => $this->provider->isConfigured(),
			'missing' => $this->provider->configurationProblems(),
			'market' => $market?->label(),
			'location_code' => $market?->locationCode,
			'language_code' => $market?->languageCode,
			'metrics' => $market === null ? null : $this->metrics->counts($market),
			'pending_tasks' => $this->tasks->pendingCount($context?->projectId()),
			'usage_24h' => $this->tasks->usage($now->modify('-1 day')->format('Y-m-d H:i:s'), $context?->projectId()),
			'usage_30d' => $this->tasks->usage($now->modify('-30 days')->format('Y-m-d H:i:s'), $context?->projectId()),
			'budget' => $budget->toArray(),
			'paused' => $this->paused(),
			'auto_refresh' => $this->config->autoRefresh(),
			'state' => $state,
			'settings' => $this->config->effective(),
		];
	}

	private function execute(ProjectContext $context, SyncPlan $plan, string $trigger): MarketSyncResult
	{
		$result = new MarketSyncResult(MarketSyncResult::SUCCESS, $plan);
		$market = $plan->market;
		$budget = $this->budget();
		$projectId = $context->projectId();

		if ($market === null) {
			return $result->finish();
		}

		foreach ($plan->tasks as $task) {
			$blocked = $task->isAllowed() ? $budget->check($task->estimatedCost) : $task->blockedBy;

			if ($blocked !== CostBudget::OK) {
				$result->blockedBy = $blocked;

				break;
			}

			$taskId = $this->tasks->create($this->provider->name(), $task->endpoint, $trigger, $projectId, $market, $task->keywords, $task->estimatedCost);
			$budget->spend($task->estimatedCost);

			try {
				$task->type === PlannedTask::VOLUME
					? $this->submitVolume($market, $task, $taskId, $result)
					: $this->fetchDifficulty($market, $task, $taskId, $result);
			} catch (ProviderException $exception) {
				$category = $exception->category();
				// Sieć, 5xx, uszkodzona odpowiedź: nie wiadomo, czy dostawca wykonał (i opłacił) zadanie — koszt szacowany zostaje.
				$charged = in_array($category, [ProviderErrorCategory::Network, ProviderErrorCategory::Transient, ProviderErrorCategory::MalformedResponse], true);
				$this->tasks->markFailed($taskId, $category, $exception->getMessage(), $charged);
				$result->error = $category;
				$result->errorMessage = $exception->getMessage();

				if ($charged) {
					$result->cost += $task->estimatedCost;
				}

				if ($category->isAccountLevel()) {
					$this->pause($category);
				}

				$this->logger->warning('Market data sync of project {project} stopped: {category}.', ['project' => $context->publicId(), 'category' => $category->value]);

				break;
			}
		}

		$result->finish();
		$nextAuto = match (true) {
			$result->error?->isAccountLevel() === true => MarketDataConfig::PAUSE_AFTER_ACCOUNT_ERROR,
			$result->error !== null => MarketDataConfig::PAUSE_AFTER_TRANSIENT_ERROR,
			// Więcej fraz niż limit zadań przebiegu — kontynuacja w kolejnym przebiegu.
			$result->blockedBy === CostBudget::TASK_LIMIT => 3600,
			default => MarketDataConfig::AUTO_INTERVAL_HOURS * 3600,
		};
		$this->states->recordRun($projectId, $this->provider->name(), $result->error?->value, $this->offset($nextAuto));

		if ($result->error === null && $result->tasks() > 0 && $trigger !== self::TRIGGER_AUTO) {
			delete_option(self::PAUSE_OPTION);
		}

		$this->logger->info('Market data sync of project {project} ({trigger}): {status}, {tasks} tasks, cost {cost}.', [
			'project' => $context->publicId(),
			'trigger' => $trigger,
			'status' => $result->status,
			'tasks' => $result->tasks(),
			'cost' => round($result->cost, 4),
		]);

		return $result;
	}

	private function submitVolume(Market $market, PlannedTask $task, int $taskId, MarketSyncResult $result): void
	{
		$submission = $this->provider->submitVolume($market, $task->keywords);
		$result->volumeTasks++;
		$result->cost += $submission->cost ?? $task->estimatedCost;

		if ($submission->results !== null) {
			[$stored, $unmatched] = $this->storeVolume($market, $task->keywords, $submission->results, $taskId);
			$result->volumeKeywords += $stored;
			$result->unmatched += $unmatched;
			$this->tasks->markCompleted($taskId, count($submission->results->items), $submission->cost);

			return;
		}

		if ($submission->taskId === null) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'The provider returned neither a task id nor results.');
		}

		$this->tasks->markSubmitted($taskId, $submission->taskId, $submission->cost, $this->offset(self::POLL_DELAYS[0]));
		$ids = $this->metrics->ensure($market, $task->keywords);
		$this->metrics->markVolumePending(array_values($ids), $this->offset(MarketDataConfig::PENDING_HOURS * 3600), $taskId);
		$result->volumeKeywords += count($task->keywords);
	}

	private function fetchDifficulty(Market $market, PlannedTask $task, int $taskId, MarketSyncResult $result): void
	{
		$batch = $this->provider->difficulty($market, $task->keywords);
		[$mapped, $unmatched] = ResultMapper::map($task->keywords, array_column($batch->items, 'keyword'));
		$values = [];

		foreach ($mapped as $index => $keyword) {
			$values[$keyword] = $batch->items[$index]['difficulty'];
		}

		$result->difficultyKeywords += $this->metrics->storeDifficulty($market, $task->keywords, $values, $taskId, $this->config->difficultyTtlDays());
		$result->difficultyTasks++;
		$result->unmatched += $unmatched;
		$result->cost += $batch->cost ?? $task->estimatedCost;
		$this->tasks->markCompleted($taskId, count($batch->items), $batch->cost);
	}

	/**
	 * @param list<string> $keywords frazy wysłane w zadaniu
	 * @return array{0: int, 1: int} zapisane frazy, niedopasowane wyniki
	 */
	private function storeVolume(Market $market, array $keywords, VolumeBatch $batch, int $taskId): array
	{
		[$mapped, $unmatched] = ResultMapper::map($keywords, array_map(static fn (VolumeMetrics $item): string => $item->keyword, $batch->items));
		$byKeyword = [];

		foreach ($mapped as $index => $keyword) {
			$byKeyword[$keyword] = $batch->items[$index];
		}

		return [$this->metrics->storeVolume($market, $keywords, $byKeyword, $taskId, $this->config->volumeTtlDays()), $unmatched];
	}

	private function pause(ProviderErrorCategory $category): void
	{
		update_option(self::PAUSE_OPTION, ['until' => $this->offset(MarketDataConfig::PAUSE_AFTER_ACCOUNT_ERROR), 'reason' => $category->value], false);
	}

	/**
	 * Rynek zadania z rejestru (identyfikatory dostawcy; etykiety z katalogu, gdy rynek jest znany).
	 *
	 * @param array<string, string|null> $task
	 */
	private function taskMarket(array $task): Market
	{
		foreach ($this->provider->markets() as $market) {
			if ($market->locationCode === (int) $task['location_code'] && $market->languageCode === (string) $task['language_code']) {
				return $market;
			}
		}

		return new Market((string) $task['provider'], '', '', (int) $task['location_code'], (string) $task['language_code'], (string) $task['location_code'], (string) $task['language_code']);
	}

	private function offset(int $seconds): string
	{
		return $this->clock->now()->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('Y-m-d H:i:s');
	}
}
