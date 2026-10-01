<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Database\Connection;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Gsc\GscCalendar;
use OsfSeo\Gsc\GscNotReady;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Projects\ProjectStatus;
use OsfSeo\Support\DateRange;

/**
 * Synchronizacja projektu dla panelu i CLI: „Synchronizuj teraz”, backfill, stan i postęp.
 * Zlecanie wymaga `osf_seo_manage_connections`; odczyt stanu — dostępu do projektu.
 */
final class SyncService
{
	public function __construct(
		private readonly Connection $db,
		private readonly ProjectRepository $projects,
		private readonly ConnectionRepository $connections,
		private readonly SyncPlanner $planner,
		private readonly SyncRunner $runner,
		private readonly SyncRunRepository $runs,
		private readonly SyncStateRepository $states,
		private readonly GscCalendar $calendar,
		private readonly SyncConfig $config,
	) {
	}

	/**
	 * Ręczna synchronizacja: odświeżenie najnowszych danych (sumy witryny → frazy). Odporna na wielokrotne
	 * kliknięcia: brak nowych zadań, gdy odświeżanie już czeka/trwa, i limit jednego zlecenia na 5 minut.
	 *
	 * @throws AccessDenied
	 * @throws GscNotReady
	 */
	public function requestSync(ProjectContext $context, bool $bypassCooldown = false): SyncRequestResult
	{
		$context->assertCan(Capabilities::MANAGE_CONNECTIONS);

		return $this->db->transaction(function () use ($context, $bypassCooldown): SyncRequestResult {
			// Blokada wiersza projektu serializuje równoległe kliknięcia (dwie karty, podwójne wysłanie formularza).
			$project = $this->projects->lockForUpdate($context->projectId());
			$this->assertReady($project);

			$lastManual = $this->runs->lastManualQueuedAt($context->projectId());

			if (! $bypassCooldown && $lastManual !== null) {
				$elapsed = strtotime($this->states->now() . ' UTC') - strtotime($lastManual . ' UTC');

				if ($elapsed < SyncConfig::MANUAL_COOLDOWN) {
					return new SyncRequestResult(SyncRequestResult::RATE_LIMITED, 0, SyncConfig::MANUAL_COOLDOWN - $elapsed);
				}
			}

			foreach (array_keys($this->runs->pendingKinds($context->projectId())) as $kind) {
				if (str_ends_with($kind, ':refresh')) {
					return new SyncRequestResult(SyncRequestResult::ALREADY_QUEUED);
				}
			}

			$this->states->clearCooldown($context->projectId());
			$jobs = $this->planner->plan($context, TriggerType::Manual, true);

			return $jobs === []
				? new SyncRequestResult(SyncRequestResult::ALREADY_QUEUED)
				: new SyncRequestResult(SyncRequestResult::QUEUED, count($jobs));
		});
	}

	/**
	 * Zleca kolejne okna backfillu (i ewentualne odświeżenie) — bez wymuszania odświeżenia.
	 *
	 * @return list<int>
	 * @throws AccessDenied
	 * @throws GscNotReady
	 */
	public function continueBackfill(ProjectContext $context): array
	{
		$context->assertCan(Capabilities::MANAGE_CONNECTIONS);

		return $this->db->transaction(function () use ($context): array {
			$this->assertReady($this->projects->lockForUpdate($context->projectId()));
			$this->states->clearCooldown($context->projectId());

			return $this->planner->plan($context, TriggerType::Backfill);
		});
	}

	/**
	 * Wykonanie kolejki tylko dla projektu (WP-CLI). Zwykle zadania wykonuje WP-Cron / `wp osf-seo sync:run`.
	 *
	 * @throws AccessDenied poza WP-CLI i WP-Cron
	 */
	public function runNow(ProjectContext $context, float $budgetSeconds, int $maxJobs, int $lockWait = 30): RunnerReport
	{
		if (! ProjectGuard::isSystemProcess()) {
			throw new AccessDenied('The sync queue can only be run from WP-CLI or WP-Cron.');
		}

		$context->assertCan(Capabilities::MANAGE_CONNECTIONS);

		return $this->runner->run($budgetSeconds, $maxJobs, $context->projectId(), $lockWait);
	}

	public function status(ProjectContext $context): SyncStatus
	{
		$project = $this->projects->reload($context->project());
		$projectId = $project->internalId;
		$states = $this->states->forProject($projectId);
		$recent = $this->runs->recent($projectId, 10);
		$pendingByDataset = [];
		$pendingStatus = [];

		foreach ($this->db->fetchAll(
			"SELECT dataset, status, COUNT(*) AS total FROM `{$this->db->table('sync_runs')}` WHERE project_id = %d AND status IN ('queued', 'running', 'retrying') GROUP BY dataset, status",
			[$projectId],
		) as $row) {
			$pendingByDataset[(string) $row['dataset']] = ($pendingByDataset[(string) $row['dataset']] ?? 0) + (int) $row['total'];
			$pendingStatus[(string) $row['dataset']][(string) $row['status']] = true;
		}

		$today = $this->calendar->today();
		$historyStart = WindowPlanner::historyStart($today, $this->config->historyMonths());
		$site = $states[Dataset::Site->value];
		$latest = $site->newestDate;
		$lower = $latest === null ? null : max($historyStart, $site->oldestDate ?? $latest);
		$datasets = [];
		$coveredTotal = 0;
		$daysTotal = 0;

		foreach (Dataset::cases() as $dataset) {
			$state = $states[$dataset->value];
			$total = $lower === null ? 0 : DateRange::diffDays($lower, $latest) + 1;
			$covered = 0;

			if ($lower !== null && $state->oldestDate !== null && $state->newestDate !== null) {
				$from = max($lower, $state->oldestDate);
				$to = min($latest, $state->newestDate);
				$covered = $from <= $to ? DateRange::diffDays($from, $to) + 1 : 0;
			}

			$coveredTotal += $covered;
			$daysTotal += $total;
			$status = match (true) {
				isset($pendingStatus[$dataset->value]['running']) => 'running',
				isset($pendingStatus[$dataset->value]['retrying']) => 'retrying',
				isset($pendingStatus[$dataset->value]['queued']) => 'queued',
				$state->status === 'failed' => 'failed',
				$state->lastSuccessAt === null => 'never',
				default => 'success',
			};

			$datasets[] = [
				'dataset' => $dataset->value,
				'label' => $dataset->label(),
				'status' => $status,
				'newest_date' => $state->newestDate,
				'oldest_date' => $state->oldestDate,
				'covered_days' => $covered,
				'total_days' => $total,
				'progress' => $total > 0 ? (int) floor($covered * 100 / $total) : 0,
				'backfill_complete' => $dataset === Dataset::Site ? $latest !== null : WindowPlanner::backfillComplete($state, $site, $today, $this->config->historyMonths()),
				'last_success_at' => $state->lastSuccessAt,
				'last_attempt_at' => $state->lastAttemptAt,
				'last_error' => $state->lastError,
				'retry_after' => $state->retryAfter,
				'pending' => $pendingByDataset[$dataset->value] ?? 0,
			];
		}

		$times = $this->runs->lastTimes($projectId);

		return new SyncStatus(
			overall: $this->overall($project, $datasets),
			notReadyReason: $this->notReadyReason($project),
			datasets: $datasets,
			latestDataDate: $latest,
			keywordsDataDate: $states[Dataset::Query->value]->newestDate,
			lastSuccessAt: $times['last_success'],
			lastAttemptAt: $times['last_attempt'],
			pendingJobs: array_sum($pendingByDataset),
			backfillProgress: $daysTotal > 0 ? (int) floor($coveredTotal * 100 / $daysTotal) : 0,
			recentRuns: $recent,
		);
	}

	/**
	 * @throws GscNotReady
	 */
	private function assertReady(?\OsfSeo\Projects\Project $project): void
	{
		$reason = $project === null ? GscNotReady::NO_PROPERTY : $this->notReadyReason($project);

		if ($reason !== null) {
			throw new GscNotReady($reason);
		}
	}

	private function notReadyReason(\OsfSeo\Projects\Project $project): ?string
	{
		$connection = $project->connectionId === null ? null : $this->connections->find($project->connectionId);

		return match (true) {
			$connection === null => GscNotReady::NO_CONNECTION,
			! $connection->isActive() => GscNotReady::CONNECTION_INACTIVE,
			$project->gscProperty === null => GscNotReady::NO_PROPERTY,
			$project->status !== ProjectStatus::Active => GscNotReady::PROJECT_INACTIVE,
			default => null,
		};
	}

	/**
	 * @param list<array{status: string}> $datasets
	 */
	private function overall(\OsfSeo\Projects\Project $project, array $datasets): string
	{
		$reason = $this->notReadyReason($project);

		if ($reason === GscNotReady::CONNECTION_INACTIVE) {
			return 'needs_reauth';
		}

		$statuses = array_column($datasets, 'status');

		foreach (['running', 'retrying', 'queued'] as $active) {
			if (in_array($active, $statuses, true)) {
				return $active;
			}
		}

		if ($reason !== null) {
			return 'not_ready';
		}

		$failed = count(array_keys($statuses, 'failed', true));
		$success = count(array_keys($statuses, 'success', true));

		return match (true) {
			$failed > 0 && $success > 0 => 'partial',
			$failed > 0 => 'failed',
			$success === 0 => 'never',
			default => 'success',
		};
	}
}
