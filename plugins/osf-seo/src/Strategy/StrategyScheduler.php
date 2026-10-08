<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Projects\ProjectStatus;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use OsfSeo\Sync\SyncRunRepository;
use Throwable;

/**
 * Krok Strategii w tle (faza E — docs/ARCHITECTURE.md, sekcja 15.15): ostatni krok po kolejce synchronizacji (`SyncScheduler::onAfterRun`
 * — WP-Cron albo `wp osf-seo sync:run`) we wspólnym limicie czasu ticka (D63). Wyłącznie lokalne przeliczenie zapisanych danych:
 * żadnego żądania do API, żadnych zleceń SERP, Labs, metryk ani rozszerzania monitorowanych fraz (D78).
 *
 * 1. Odzyskanie porzuconych zadań (status `running` bez trzymanej blokady projektu).
 * 2. Wykrywanie zmian (najwyżej co CHECK_INTERVAL): klucz danych aktywnego projektu ≠ klucz ostatniego przeliczenia → zmiana zauważona;
 *    zlecenie dopiero, gdy klucz jest stabilny przez QUIET i projekt nie ma oczekujących zadań importu GSC (jeden przebieg na import
 *    zamiast przeliczenia po każdym zadaniu) — najpóźniej po MAX_DEFER. Wyczerpane próby na tych samych danych → bez zlecenia.
 * 3. Zadania gotowe do wykonania (najpierw ręczne) w pozostałym czasie ticka — co najmniej jedno na tick.
 *
 * Projekt rozwiązywany z identyfikatora publicznego zapisanego w bazie przez `ProjectGuard::authorizeSystem` — nigdy z wejścia HTTP.
 */
final class StrategyScheduler
{
	/** Wykrywanie zmian danych najwyżej co tyle sekund (klucz danych: kilkanaście tanich zapytań na projekt). */
	public const CHECK_INTERVAL = 60;

	/** Klucz danych musi być stabilny przez tyle sekund (koniec serii zapisów importu) przed automatycznym zleceniem. */
	public const QUIET = 120;

	/** Najdłużej tyle sekund zlecenie czeka na koniec importu GSC (długi backfill, ponawiane zadania). */
	public const MAX_DEFER = 3600;

	/** Wyczerpane próby bez klucza danych (nie dało się go policzyć) — kolejne automatyczne zlecenie najwcześniej po tylu sekundach. */
	public const FAILED_COOLDOWN = 21600;

	public const MAX_JOBS_PER_RUN = 10;

	public const HEARTBEAT_OPTION = 'osf_seo_strategy_heartbeat';

	private const CHECK_TRANSIENT = 'osf_seo_strategy_checked';

	public function __construct(
		private readonly StrategyRefresher $refresher,
		private readonly StrategyRefreshRunner $runner,
		private readonly StrategyRefreshQueue $queue,
		private readonly StrategySettingsRepository $settings,
		private readonly SyncRunRepository $runs,
		private readonly ProjectGuard $guard,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @return array{recovered: array<int, ?string>, detected: array<string, int>, jobs: array<int, string>, skipped_budget: int}
	 */
	public function runBackground(float $budgetSeconds = 20.0, bool $ignoreInterval = false): array
	{
		$report = ['recovered' => [], 'detected' => [], 'jobs' => [], 'skipped_budget' => 0];

		if (! ProjectGuard::isSystemProcess()) {
			return $report;
		}

		$started = microtime(true);
		update_option(self::HEARTBEAT_OPTION, $this->clock->now()->format('Y-m-d H:i:s'), false);

		foreach ($this->queue->running() as $projectId) {
			$status = $this->safely(fn (): ?string => $this->runner->recover($projectId), 'Recovering strategy refresh of project {project} failed: {message}', $projectId);

			if ($status !== null) {
				$report['recovered'][$projectId] = $status;
			}
		}

		if ($ignoreInterval || get_transient(self::CHECK_TRANSIENT) === false) {
			set_transient(self::CHECK_TRANSIENT, '1', self::CHECK_INTERVAL);
			$report['detected'] = $this->detect($started, $budgetSeconds);
		}

		$jobs = $this->queue->dueWithProjects(self::MAX_JOBS_PER_RUN);

		if ($jobs !== [] && function_exists('wp_raise_memory_limit')) {
			// Przeliczenie dużego projektu (5 000 fraz) potrzebuje ok. 60 MB ponad WordPress — jak kolejka GSC (SyncRunner).
			wp_raise_memory_limit('admin');
		}

		foreach ($jobs as $job) {
			// Co najmniej jedno zadanie na tick (zlecenie ręczne nie czeka na koniec długiego importu); kolejne w pozostałym czasie ticka.
			if ($report['jobs'] !== [] && microtime(true) - $started >= $budgetSeconds) {
				$report['skipped_budget']++;

				continue;
			}

			$report['jobs'][$job['project_id']] = $this->runJob($job);
		}

		return $report;
	}

	/**
	 * Wykrywanie zmian danych aktywnych projektów (bez zapisu, gdy nic się nie zmieniło).
	 *
	 * @return array<string, int> wynik → liczba projektów
	 */
	public function detect(float $started, float $budgetSeconds): array
	{
		$counts = [];

		foreach ($this->queue->detectionCandidates() as $row) {
			if (microtime(true) - $started >= $budgetSeconds) {
				$counts['skipped_budget'] = ($counts['skipped_budget'] ?? 0) + 1;

				continue;
			}

			$result = $this->safely(fn (): string => $this->detectProject($row['project_id'], $row['public_id']), 'Detecting strategy changes of project {project} failed: {message}', $row['project_id']) ?? 'error';
			$counts[$result] = ($counts[$result] ?? 0) + 1;
		}

		return $counts;
	}

	/**
	 * Wykrywanie zmian jednego projektu: `current`, `seen` (nowy klucz zauważony), `waiting` (okno ciszy albo trwa import GSC),
	 * `queued`, `busy` (zadanie oczekuje albo trwa), `failed_same_data`, `failed_cooldown`, `unsupported`, `not_found`.
	 */
	public function detectProject(int $projectId, string $publicId): string
	{
		try {
			$context = $this->guard->authorizeSystem($publicId);
		} catch (ProjectNotFound) {
			return 'not_found';
		}

		if ($context->projectId() !== $projectId || $context->project()->status !== ProjectStatus::Active) {
			return 'not_found';
		}

		$settings = $this->settings->get($projectId);

		if (in_array($settings->refreshStatus, [StrategyRefreshQueue::STATUS_QUEUED, StrategyRefreshQueue::STATUS_RUNNING], true)) {
			return 'busy';
		}

		$key = $this->refresher->currentKey($projectId);

		if ($key === null) {
			return 'unsupported';
		}

		if ($settings->dataKey === $key) {
			if ($settings->refreshSeenKey !== null) {
				$this->queue->clearSeen($projectId);
			}

			return 'current';
		}

		$now = $this->clock->now()->getTimestamp();

		if ($settings->refreshStatus === StrategyRefreshQueue::STATUS_FAILED) {
			if ($settings->refreshFailedKey === $key) {
				return 'failed_same_data';
			}

			if ($settings->refreshFailedKey === null && $now - self::timestamp($settings->refreshFinishedAt) < self::FAILED_COOLDOWN) {
				return 'failed_cooldown';
			}
		}

		// Dane nieaktualne dłużej niż MAX_DEFER (długi import, stale zmieniający się klucz) — zlecenie bez czekania na ciszę.
		$overdue = $settings->refreshDirtySince !== null && $now - self::timestamp($settings->refreshDirtySince) >= self::MAX_DEFER;

		if (! $overdue) {
			if ($settings->refreshSeenKey !== $key) {
				$this->queue->markSeen($projectId, $key);

				return 'seen';
			}

			if ($now - self::timestamp($settings->refreshSeenAt) < self::QUIET || $this->runs->pendingCount($projectId) > 0) {
				return 'waiting';
			}
		}

		if (! $this->queue->enqueue($projectId)) {
			return 'busy';
		}

		$this->logger->info('Strategy refresh of project {project} queued after data change.', ['project' => $publicId]);

		return 'queued';
	}

	/**
	 * Diagnostyka kolejki (CLI `strategy:queue`): liczniki, najstarsze oczekujące zadanie, przekroczone dzierżawy, ostatni krok w tle
	 * i projekty z zadaniem albo błędem.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics(): array
	{
		return $this->queue->summary() + [
			'heartbeat' => get_option(self::HEARTBEAT_OPTION, null) ?: null,
			'sync_heartbeat' => get_option(\OsfSeo\Sync\SyncRunner::HEARTBEAT_OPTION, null) ?: null,
			'projects' => $this->queue->diagnosticRows(),
		];
	}

	/**
	 * @param array{project_id: int, public_id: string, source: string} $job
	 */
	private function runJob(array $job): string
	{
		$projectId = $job['project_id'];

		try {
			$context = $this->guard->authorizeSystem($job['public_id']);
		} catch (ProjectNotFound) {
			$context = null;
		}

		if ($context === null || $context->projectId() !== $projectId || $context->project()->status === ProjectStatus::Archived
			|| ($job['source'] !== StrategyRefreshQueue::SOURCE_MANUAL && $context->project()->status !== ProjectStatus::Active)) {
			// Projekt usunięty albo zarchiwizowany (a automatycznie — także wstrzymany): zadanie bez wykonania.
			$this->queue->dismiss($projectId, StrategyRefreshQueue::ERROR_NO_PROJECT);

			return 'dismissed';
		}

		$result = $this->safely(fn (): array => $this->runner->runQueued($context->projectId()), 'Strategy refresh job of project {project} failed: {message}', $projectId);

		return (string) ($result['outcome'] ?? 'error');
	}

	/**
	 * @template T
	 * @param \Closure(): T $step
	 * @return T|null
	 */
	private function safely(\Closure $step, string $message, int $projectId): mixed
	{
		try {
			return $step();
		} catch (Throwable $exception) {
			$this->logger->error($message, ['project' => $projectId, 'message' => $exception->getMessage()]);

			return null;
		}
	}

	private static function timestamp(?string $datetime): int
	{
		return $datetime === null ? 0 : (int) strtotime($datetime . ' UTC');
	}
}
