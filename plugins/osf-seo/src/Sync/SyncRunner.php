<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Database\Connection;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\ReauthorizationRequired;
use OsfSeo\Google\VaultException;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Gsc\GscApiException;
use OsfSeo\Gsc\GscImporter;
use OsfSeo\Gsc\ImportAborted;
use OsfSeo\Gsc\ImportResult;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Projects\ProjectStatus;
use OsfSeo\Support\DateRange;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Wykonuje zadania z kolejki `sync_runs` — jedno naraz, w ramach budżetu czasu i limitu zadań
 * (bez nieskończonej pętli). Tylko jeden runner w całej instalacji (GET_LOCK) — WP-Cron, cron systemowy
 * i WP-CLI mogą się nakładać bez podwójnego wykonania.
 *
 * Wynik zadania:
 * - sukces → stan datasetu (pokrycie, kursor odświeżania), następne zadania z planisty,
 * - błąd przejściowy (429, 5xx, sieć, uszkodzona odpowiedź, baza) → ponowienie z backoffem
 *   (1 min, 5 min, 30 min, 2 h; Retry-After), po 5. próbie `failed` + przerwa 6 h,
 * - błąd trwały (403, 404, 400, klucz szyfrowania) → `failed` + przerwa 24 h (bez pętli ponowień),
 * - `invalid_grant` → połączenie `needs_reauth`, anulowanie oczekujących zadań projektów tego połączenia,
 * - zmiana property / projekt wstrzymany → zadanie anulowane / pominięte.
 */
final class SyncRunner
{
	private const LOCK = 'sync_runner';

	private const MAINTENANCE_OPTION = 'osf_seo_sync_maintenance_at';

	public const HEARTBEAT_OPTION = 'osf_seo_sync_heartbeat';

	public function __construct(
		private readonly Connection $db,
		private readonly SyncRunRepository $runs,
		private readonly SyncStateRepository $states,
		private readonly SyncPlanner $planner,
		private readonly GscImporter $importer,
		private readonly ProjectGuard $guard,
		private readonly ProjectRepository $projects,
		private readonly ConnectionRepository $connections,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @param int|null $projectId tylko zadania jednego projektu (WP-CLI)
	 * @param int $lockWait sekundy oczekiwania na blokadę (0 = nie czekaj)
	 */
	public function run(float $budgetSeconds, int $maxJobs, ?int $projectId = null, int $lockWait = 0): RunnerReport
	{
		if (! $this->db->acquireLock(self::LOCK, $lockWait)) {
			return new RunnerReport(true);
		}

		$started = microtime(true);
		$report = new RunnerReport();

		try {
			update_option(self::HEARTBEAT_OPTION, gmdate('Y-m-d H:i:s'), false);
			$this->prepareProcess($budgetSeconds);
			$report->recovered = $this->recoverOrphans();
			$this->maintenance();

			while ($report->processed() < $maxJobs && microtime(true) - $started < $budgetSeconds) {
				$run = $this->runs->claimNext(SyncConfig::JOB_LEASE, $projectId);

				if ($run === null) {
					break;
				}

				$report->record($this->execute($run));
			}
		} finally {
			$this->db->releaseLock(self::LOCK);
			$report->seconds = microtime(true) - $started;
		}

		return $report;
	}

	private function execute(SyncRun $run): RunStatus
	{
		$publicId = (string) $this->db->fetchValue("SELECT public_id FROM `{$this->db->table('projects')}` WHERE id = %d", [$run->projectId]);

		try {
			$context = $this->guard->authorizeSystem($publicId);
		} catch (ProjectNotFound) {
			$this->runs->markFinal($run->id, RunStatus::Cancelled, 'project_missing', 'Project no longer exists.');

			return RunStatus::Cancelled;
		}

		$project = $context->project();

		if ($project->status !== ProjectStatus::Active) {
			$this->runs->markFinal($run->id, RunStatus::Skipped, 'project_inactive', 'Project is paused or archived.');

			return RunStatus::Skipped;
		}

		if ($run->property === null || $project->gscProperty !== $run->property || $project->gscDataProperty !== $run->property) {
			$this->runs->markFinal($run->id, RunStatus::Cancelled, 'property_changed', 'The project property changed after the job was queued.');

			return RunStatus::Cancelled;
		}

		$connection = $project->connectionId === null ? null : $this->connections->find($project->connectionId);

		if ($connection === null || ! $connection->isActive()) {
			$this->runs->markFinal($run->id, RunStatus::Cancelled, 'needs_reauth', 'The Google connection is missing or needs re-authorization.');
			$this->states->update($run->projectId, $run->dataset, ['status' => 'failed', 'last_error' => 'needs_reauth']);

			return RunStatus::Cancelled;
		}

		$this->states->update($run->projectId, $run->dataset, ['status' => RunStatus::Running->value, 'last_attempt_at' => $this->states->now()]);

		try {
			$result = $this->importer->import($context, $connection, $run->dataset, $run->range, $run->id, $run->property);
		} catch (ImportAborted $exception) {
			$this->runs->markFinal($run->id, RunStatus::Cancelled, $exception->reason(), $exception->getMessage());

			return RunStatus::Cancelled;
		} catch (ReauthorizationRequired $exception) {
			$this->runs->markFinal($run->id, RunStatus::Failed, 'needs_reauth', 'Google rejected the refresh token (invalid_grant); reconnect the Google account.');
			$cancelled = $this->runs->cancelPendingForConnection($connection->id, 'needs_reauth');
			$this->states->update($run->projectId, $run->dataset, ['status' => 'failed', 'last_error' => 'needs_reauth']);
			$this->logger->warning('Sync stopped: Google connection {connection} needs re-authorization ({cancelled} queued jobs cancelled).', [
				'connection' => $connection->id,
				'cancelled' => $cancelled,
			]);

			return RunStatus::Failed;
		} catch (GscApiException $exception) {
			return $this->fail($run, $exception->category()->value, $exception->getMessage(), $exception->isRetryable(), $exception->retryAfter());
		} catch (VaultException $exception) {
			return $this->fail($run, 'configuration', 'Cannot decrypt the Google refresh token (' . $exception->reason() . ').', false);
		} catch (Throwable $exception) {
			// Błędy bazy i nieprzewidziane — ponawiane (ograniczona liczba prób).
			$this->logger->error('GSC sync job {run} failed unexpectedly: {message}', ['run' => $run->id, 'message' => $exception->getMessage()]);

			return $this->fail($run, 'internal_error', $exception::class . ': ' . $exception->getMessage(), true);
		}

		$this->runs->markSuccess($run->id, $result);
		$this->recordSuccess($run, $result);
		$this->projects->touchLastSynced($run->projectId);

		// Następne okno (łańcuch backfillu, kolejne okna odświeżania, frazy po sumach witryny).
		$this->planner->plan($context);

		return RunStatus::Success;
	}

	private function recordSuccess(SyncRun $run, ImportResult $result): void
	{
		$state = $this->states->get($run->projectId, $run->dataset);
		$now = $this->states->now();
		$fields = [
			'status' => 'idle',
			'consecutive_failures' => 0,
			'last_success_at' => $now,
			'retry_after' => null,
			'last_error' => null,
		];

		if ($run->dataset === Dataset::Site) {
			// Pokrycie sum = daty, dla których Google zwrócił dane (ostatnia data final, początek historii).
			if ($result->maxDate !== null) {
				$fields['newest_date'] = max($state->newestDate ?? $result->maxDate, $result->maxDate);
				$fields['oldest_date'] = min($state->oldestDate ?? (string) $result->minDate, (string) $result->minDate);
			}

			$fields['last_refresh_at'] = $now;
			$this->states->update($run->projectId, $run->dataset, $fields);

			return;
		}

		$range = $run->range;

		if ($run->isBackfill()) {
			// Pokrycie rośnie tylko w sposób ciągły (okno przylega do najstarszej daty).
			if ($state->oldestDate === null || $range->end >= DateRange::shift($state->oldestDate, -1)) {
				$fields['oldest_date'] = min($state->oldestDate ?? $range->start, $range->start);
			}
		} elseif ($state->newestDate === null) {
			$fields['newest_date'] = $range->end;
			$fields['oldest_date'] = $range->start;
			$fields['refresh_cursor'] = null;
			$fields['last_refresh_at'] = $now;
		} else {
			if ($range->start <= DateRange::shift($state->newestDate, 1)) {
				$fields['newest_date'] = max($state->newestDate, $range->end);
			}

			$siteLatest = $this->states->get($run->projectId, Dataset::Site)->newestDate ?? $range->end;
			$next = DateRange::shift($range->end, 1);

			if ($next > $siteLatest) {
				$fields['refresh_cursor'] = null;
				$fields['last_refresh_at'] = $now;
			} else {
				$fields['refresh_cursor'] = $next;
			}
		}

		$this->states->update($run->projectId, $run->dataset, $fields);
	}

	private function fail(SyncRun $run, string $code, string $message, bool $retryable, ?int $retryAfter = null): RunStatus
	{
		$this->states->incrementFailures($run->projectId, $run->dataset);
		$delay = $retryable ? SyncConfig::retryDelay($run->attempt, $retryAfter) : null;

		if ($delay !== null) {
			$this->runs->markRetry($run->id, $run->attempt + 1, $delay, $code, $message);
			$this->states->update($run->projectId, $run->dataset, ['status' => RunStatus::Retrying->value, 'last_error' => $code]);

			return RunStatus::Retrying;
		}

		$cooldown = $retryable ? SyncConfig::COOLDOWN_AFTER_RETRIES : SyncConfig::COOLDOWN_AFTER_PERMANENT_ERROR;
		$this->runs->markFinal($run->id, RunStatus::Failed, $code, $message);
		$this->states->update($run->projectId, $run->dataset, [
			'status' => RunStatus::Failed->value,
			'last_error' => $code,
			'retry_after' => gmdate('Y-m-d H:i:s', strtotime($this->states->now() . ' UTC') + $cooldown),
		]);
		$this->logger->warning('GSC sync job {run} ({dataset}) failed permanently: {code}.', ['run' => $run->id, 'dataset' => $run->dataset->value, 'code' => $code]);

		return RunStatus::Failed;
	}

	/** Zadania „running” po przerwanym procesie: ponowienie (licznik prób rośnie) albo failed. */
	private function recoverOrphans(): int
	{
		$recovered = 0;

		foreach ($this->runs->orphanedRuns() as $run) {
			$this->fail($run, 'interrupted', 'The worker process stopped before the job finished.', true);
			$recovered++;
		}

		return $recovered;
	}

	/** Raz na dobę: retencja historii zadań i osierocony staging. */
	private function maintenance(): void
	{
		$last = (string) get_option(self::MAINTENANCE_OPTION, '');
		$now = $this->states->now();

		if ($last !== '' && strtotime($now . ' UTC') - strtotime($last . ' UTC') < 86400) {
			return;
		}

		update_option(self::MAINTENANCE_OPTION, $now, false);
		$this->runs->purgeFinishedBefore(gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - SyncConfig::RUN_RETENTION_DAYS * 86400));
		$this->runs->purgeOrphanedStaging();
	}

	private function prepareProcess(float $budgetSeconds): void
	{
		if (function_exists('wp_raise_memory_limit')) {
			wp_raise_memory_limit('osf_seo_sync');
		}

		// Pojedyncze zadanie może trwać dłużej niż budżet (kilka stron API) — budżet sprawdzamy między zadaniami.
		if (function_exists('set_time_limit') && (int) ini_get('max_execution_time') !== 0) {
			set_time_limit((int) max(120, $budgetSeconds * 4));
		}
	}
}
