<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Gsc\GscNotReady;
use OsfSeo\Plugin;
use OsfSeo\Sync\RunnerReport;
use OsfSeo\Sync\SyncRun;
use OsfSeo\Sync\SyncScheduler;
use OsfSeo\Sync\SyncService;
use WP_CLI;

/**
 * `wp osf-seo gsc:sync|gsc:backfill|gsc:status` (projekt) i `wp osf-seo sync:run` (kolejka — dla crona systemowego).
 */
final class SyncCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$run = ['type' => 'flag', 'name' => 'run', 'description' => 'Execute the queued jobs of this project now (instead of waiting for WP-Cron).', 'optional' => true];
		$timeLimit = ['type' => 'assoc', 'name' => 'time-limit', 'description' => 'Seconds to keep executing jobs with --run (default 300).', 'optional' => true];

		WP_CLI::add_command('osf-seo gsc:sync', [$command, 'sync'], [
			'shortdesc' => 'Queue a refresh of the latest Search Console data (same as "Synchronizuj teraz").',
			'synopsis' => [$project, $run, $timeLimit, ['type' => 'flag', 'name' => 'force', 'description' => 'Ignore the 5-minute manual sync cooldown.', 'optional' => true]],
		]);

		WP_CLI::add_command('osf-seo gsc:backfill', [$command, 'backfill'], [
			'shortdesc' => 'Queue the next historical windows (newest → oldest) and optionally execute them.',
			'synopsis' => [$project, $run, $timeLimit],
		]);

		WP_CLI::add_command('osf-seo gsc:status', [$command, 'status'], [
			'shortdesc' => 'Show sync state, data coverage, backfill progress and recent jobs of a project.',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']]],
		]);

		WP_CLI::add_command('osf-seo sync:run', [$command, 'runQueue'], [
			'shortdesc' => 'Plan due work for all projects and execute queued sync jobs (for system cron).',
			'synopsis' => [
				['type' => 'assoc', 'name' => 'time-limit', 'description' => 'Seconds to keep executing jobs (default 50).', 'optional' => true],
				['type' => 'assoc', 'name' => 'max-jobs', 'description' => 'Maximum jobs to execute (default 50).', 'optional' => true],
				['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']],
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function sync(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_CONNECTIONS);

		try {
			$result = $this->service()->requestSync($context, isset($assocArgs['force']));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (GscNotReady $exception) {
			WP_CLI::error(self::notReady($exception));
		}

		WP_CLI::log(sprintf('Sync request: %s (%d job(s) queued).', $result->outcome, $result->jobs));

		if ($result->outcome === 'rate_limited') {
			WP_CLI::warning(sprintf('A manual sync was queued less than 5 minutes ago; retry in %d s or use --force.', $result->retryInSeconds));
		}

		$this->maybeRun($context, $assocArgs);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function backfill(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_CONNECTIONS);

		try {
			$jobs = $this->service()->continueBackfill($context);
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (GscNotReady $exception) {
			WP_CLI::error(self::notReady($exception));
		}

		WP_CLI::log(sprintf('%d job(s) queued (already queued jobs are kept, never duplicated).', count($jobs)));
		$this->maybeRun($context, $assocArgs);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function status(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$status = $this->service()->status($context);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			$data = $status->toArray();
			$data['project'] = $context->publicId();
			$data['property'] = $context->project()->gscProperty;
			$data['recent_runs'] = array_map(static fn (SyncRun $run): array => self::runRow($run), $status->recentRuns);
			WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

			return;
		}

		\WP_CLI\Utils\format_items('table', [
			['field' => 'project', 'value' => $context->publicId() . ' (' . $context->project()->name . ')'],
			['field' => 'property', 'value' => $context->project()->gscProperty ?? '-'],
			['field' => 'status', 'value' => $status->overall . ($status->notReadyReason !== null ? ' (' . $status->notReadyReason . ')' : '')],
			['field' => 'latest_gsc_date', 'value' => ($status->latestDataDate ?? '-') . ' (site totals), ' . ($status->keywordsDataDate ?? '-') . ' (queries)'],
			['field' => 'last_success_at', 'value' => ($status->lastSuccessAt ?? '-') . ' UTC'],
			['field' => 'last_attempt_at', 'value' => ($status->lastAttemptAt ?? '-') . ' UTC'],
			['field' => 'pending_jobs', 'value' => (string) $status->pendingJobs],
			['field' => 'backfill_progress', 'value' => $status->backfillProgress . '%'],
			['field' => 'queue_heartbeat', 'value' => (string) get_option(\OsfSeo\Sync\SyncRunner::HEARTBEAT_OPTION, 'never') . ' UTC'],
		], ['field', 'value']);

		\WP_CLI\Utils\format_items('table', array_map(static fn (array $row): array => [
			'dataset' => $row['dataset'],
			'status' => $row['status'],
			'coverage' => ($row['oldest_date'] ?? '-') . ' .. ' . ($row['newest_date'] ?? '-'),
			'progress' => $row['progress'] . '% (' . $row['covered_days'] . '/' . $row['total_days'] . ' days)',
			'backfill' => $row['backfill_complete'] ? 'complete' : 'in progress',
			'pending' => (string) $row['pending'],
			'last_error' => $row['last_error'] ?? '-',
			'retry_after' => $row['retry_after'] ?? '-',
		], $status->datasets), ['dataset', 'status', 'coverage', 'progress', 'backfill', 'pending', 'last_error', 'retry_after']);

		if ($status->recentRuns !== []) {
			WP_CLI::log('Recent jobs:');
			\WP_CLI\Utils\format_items('table', array_map(static fn (SyncRun $run): array => self::runRow($run), $status->recentRuns), ['id', 'dataset', 'trigger', 'window', 'status', 'attempt', 'rows_fetched', 'rows_written', 'error', 'queued_at']);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function runQueue(array $args, array $assocArgs): void
	{
		$scheduler = $this->plugin->get(SyncScheduler::class);
		$planned = $scheduler->planAll();
		$report = $this->plugin->get(\OsfSeo\Sync\SyncRunner::class)->run(
			(float) max(5, (int) ($assocArgs['time-limit'] ?? 50)),
			max(1, (int) ($assocArgs['max-jobs'] ?? 50)),
		);

		$this->printReport($report, $planned, $assocArgs['format'] ?? 'table');
	}

	/**
	 * @param array<string, string> $assocArgs
	 */
	private function maybeRun(ProjectContext $context, array $assocArgs): void
	{
		if (! isset($assocArgs['run'])) {
			WP_CLI::log('Jobs will be executed by WP-Cron (or: wp osf-seo sync:run). Progress: wp osf-seo gsc:status --project=' . $context->publicId());

			return;
		}

		$report = $this->service()->runNow($context, (float) max(5, (int) ($assocArgs['time-limit'] ?? 300)), 1000);
		$this->printReport($report, 0, 'table');
	}

	private function printReport(RunnerReport $report, int $planned, string $format): void
	{
		$data = ['planned' => $planned] + $report->toArray();

		if ($format === 'json') {
			WP_CLI::line((string) wp_json_encode($data));

			return;
		}

		if ($report->locked) {
			WP_CLI::warning('Another sync runner is active; nothing executed. Try again later.');

			return;
		}

		WP_CLI::success(sprintf(
			'%d job(s) executed in %.1f s: %s; %d interrupted job(s) recovered.',
			$report->processed(),
			$report->seconds,
			implode(', ', array_map(static fn (string $status, int $count): string => $status . ' ' . $count, array_keys($report->outcomes), $report->outcomes)),
			$report->recovered,
		));
	}

	/**
	 * @return array<string, string|int>
	 */
	private static function runRow(SyncRun $run): array
	{
		return [
			'id' => $run->id,
			'dataset' => $run->dataset->value,
			'trigger' => $run->trigger->value,
			'window' => (string) $run->range,
			'status' => $run->status->value,
			'attempt' => $run->attempt,
			'rows_fetched' => $run->rowsFetched,
			'rows_written' => $run->rowsWritten,
			'error' => $run->errorCode ?? '',
			'queued_at' => $run->queuedAt,
		];
	}

	private static function notReady(GscNotReady $exception): string
	{
		return match ($exception->reason()) {
			GscNotReady::NO_CONNECTION => 'The project is not connected to a Google account.',
			GscNotReady::CONNECTION_INACTIVE => 'The Google connection needs re-authorization (reconnect in the panel).',
			GscNotReady::PROJECT_INACTIVE => 'The project is paused or archived.',
			default => 'No Search Console property selected (wp osf-seo gsc:select-property).',
		};
	}

	private function service(): SyncService
	{
		return $this->plugin->get(SyncService::class);
	}
}
