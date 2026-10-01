<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Sync;

use OsfSeo\Gsc\Dataset;
use OsfSeo\Sync\PlanInput;
use OsfSeo\Sync\PlannedJob;
use OsfSeo\Sync\SyncConfig;
use OsfSeo\Sync\SyncState;
use OsfSeo\Sync\TriggerType;
use OsfSeo\Sync\WindowPlanner;
use PHPUnit\Framework\TestCase;

final class WindowPlannerTest extends TestCase
{
	private const TODAY = '2026-10-01';

	private const NOW = '2026-10-01 12:00:00';

	/**
	 * @param array<string, array<string, mixed>> $states dataset → pola SyncState
	 * @param array<string, true> $pending
	 * @param array<string, float|null> $density
	 * @return array<string, string> "dataset:trigger" → "start..end@priority"
	 */
	private static function plan(array $states = [], array $pending = [], array $density = [], bool $force = false, TriggerType $trigger = TriggerType::Schedule): array
	{
		$objects = [];

		foreach ($states as $dataset => $fields) {
			$objects[$dataset] = new SyncState(1, Dataset::from($dataset), ...$fields);
		}

		$jobs = WindowPlanner::plan(new PlanInput(1, self::TODAY, self::NOW, $objects, $pending, $density, 16, 7, $force, $trigger));
		$result = [];

		foreach ($jobs as $job) {
			self::assertInstanceOf(PlannedJob::class, $job);
			$result[$job->dataset->value . ':' . $job->trigger->value] = $job->range . '@' . $job->priority;
		}

		return $result;
	}

	/** Stan po pełnym imporcie sum witryny (najnowsza data final: 2026-09-28). */
	private static function site(string $lastRefreshAt = '2026-10-01 06:00:00', string $oldest = '2025-06-01', string $newest = '2026-09-28'): array
	{
		return ['site' => ['newestDate' => $newest, 'oldestDate' => $oldest, 'lastSuccessAt' => $lastRefreshAt, 'lastRefreshAt' => $lastRefreshAt]];
	}

	public function test_new_project_first_imports_whole_history_of_site_totals(): void
	{
		self::assertSame(['site:connect' => '2025-06-01..2026-09-30@10'], self::plan());
		self::assertSame('2025-06-01', WindowPlanner::historyStart(self::TODAY, 16));
	}

	public function test_queries_start_with_the_newest_window_after_site_totals(): void
	{
		self::assertSame([
			'query:connect' => '2026-09-22..2026-09-28@20',
			'query_page:connect' => '2026-09-26..2026-09-28@25',
		], self::plan(self::site()));
	}

	public function test_backfill_goes_from_newest_to_oldest_with_priorities(): void
	{
		$states = self::site() + [
			'query' => ['newestDate' => '2026-09-28', 'oldestDate' => '2026-09-22', 'lastRefreshAt' => '2026-10-01 07:00:00'],
			'query_page' => ['newestDate' => '2026-09-28', 'oldestDate' => '2026-03-01', 'lastRefreshAt' => '2026-10-01 07:00:00'],
		];

		self::assertSame([
			'query:backfill' => '2026-09-15..2026-09-21@30',
			'query_page:backfill' => '2026-02-26..2026-02-28@50',
		], self::plan($states));

		$states['query']['oldestDate'] = '2026-05-01';
		self::assertSame('2026-04-24..2026-04-30@40', self::plan($states)['query:backfill'], 'Starsza historia — niższy priorytet.');
	}

	public function test_backfill_stops_at_the_start_of_available_history(): void
	{
		$states = self::site() + ['query' => ['newestDate' => '2026-09-28', 'oldestDate' => '2025-06-04', 'lastRefreshAt' => '2026-10-01 07:00:00']];
		self::assertSame('2025-06-01..2025-06-03@40', self::plan($states)['query:backfill']);

		$states['query']['oldestDate'] = '2025-06-01';
		self::assertArrayNotHasKey('query:backfill', self::plan($states));

		// Dane witryny zaczynają się później niż 16 miesięcy temu → granica z danych, nie z kalendarza.
		$states = self::site(oldest: '2026-01-10') + ['query' => ['newestDate' => '2026-09-28', 'oldestDate' => '2026-01-12', 'lastRefreshAt' => '2026-10-01 07:00:00']];
		self::assertSame('2026-01-10..2026-01-11@40', self::plan($states)['query:backfill']);

		// Dane starsze niż okno historii nie są pobierane ponownie.
		$states = self::site(oldest: '2024-01-01') + ['query' => ['newestDate' => '2026-09-28', 'oldestDate' => '2025-06-01', 'lastRefreshAt' => '2026-10-01 07:00:00']];
		self::assertArrayNotHasKey('query:backfill', self::plan($states));
	}

	public function test_site_totals_are_refreshed_daily_with_a_rolling_window(): void
	{
		self::assertSame([], array_filter(self::plan(self::site('2026-09-30 17:00:00')), static fn (string $k): bool => str_starts_with($k, 'site'), ARRAY_FILTER_USE_KEY), '19 h — jeszcze nie.');
		self::assertSame('2026-09-22..2026-09-30@10', self::plan(self::site('2026-09-30 15:00:00'))['site:schedule'], '21 h: ostatnie 7 dni + nowe dni.');
	}

	public function test_queries_are_refreshed_after_site_refresh_over_the_last_seven_days(): void
	{
		$states = self::site('2026-10-01 06:00:00', newest: '2026-09-29') + [
			'query' => ['newestDate' => '2026-09-28', 'oldestDate' => '2025-06-01', 'lastRefreshAt' => '2026-09-30 07:00:00'],
		];

		self::assertSame('2026-09-23..2026-09-29@20', self::plan($states)['query:schedule'], 'Nie tylko „wczoraj” — całe okno kroczące.');
	}

	public function test_refresh_continues_from_cursor_and_catches_up_after_outage(): void
	{
		$states = self::site() + ['query' => ['newestDate' => '2026-09-28', 'oldestDate' => '2025-06-01', 'refreshCursor' => '2026-09-26', 'lastRefreshAt' => '2026-09-30 07:00:00']];
		self::assertSame('2026-09-26..2026-09-28@20', self::plan($states)['query:schedule']);

		$states = self::site() + ['query' => ['newestDate' => '2026-08-01', 'oldestDate' => '2025-06-01', 'lastRefreshAt' => '2026-08-02 07:00:00']];
		self::assertSame('2026-08-02..2026-08-08@20', self::plan($states)['query:schedule'], 'Luka po przerwie — od najnowszej zaimportowanej daty.');
	}

	public function test_up_to_date_dataset_gets_no_refresh(): void
	{
		$states = self::site('2026-10-01 06:00:00') + ['query' => ['newestDate' => '2026-09-28', 'oldestDate' => '2025-06-01', 'lastRefreshAt' => '2026-10-01 07:00:00']];

		self::assertArrayNotHasKey('query:schedule', self::plan($states));
	}

	public function test_pending_jobs_are_never_duplicated(): void
	{
		$states = self::site('2026-09-30 01:00:00') + ['query' => ['newestDate' => '2026-09-28', 'oldestDate' => '2026-09-01', 'lastRefreshAt' => '2026-09-29 07:00:00']];

		$jobs = self::plan($states, ['site:refresh' => true, 'query:backfill' => true, 'query_page:refresh' => true]);

		self::assertArrayNotHasKey('site:schedule', $jobs);
		self::assertArrayNotHasKey('query:backfill', $jobs);
		self::assertArrayNotHasKey('query_page:connect', $jobs);
		self::assertArrayHasKey('query:schedule', $jobs, 'Odświeżanie i backfill to osobne rodzaje.');
	}

	public function test_cooldown_after_failures_is_respected_unless_forced(): void
	{
		$states = self::site() + ['query' => ['newestDate' => null, 'retryAfter' => '2026-10-01 18:00:00']];

		self::assertArrayNotHasKey('query:connect', self::plan($states));
		self::assertSame('2026-09-22..2026-09-28@15', self::plan($states, force: true, trigger: TriggerType::Manual)['query:manual']);
	}

	public function test_manual_sync_forces_site_refresh_with_higher_priority(): void
	{
		self::assertSame('2026-09-22..2026-09-30@5', self::plan(self::site(), force: true, trigger: TriggerType::Manual)['site:manual']);
	}

	public function test_window_width_adapts_to_data_density(): void
	{
		self::assertSame('2026-09-27..2026-09-28@20', self::plan(self::site(), density: ['query' => 25000.0])['query:connect']);
		self::assertSame('2026-08-29..2026-09-28@20', self::plan(self::site(), density: ['query' => 100.0])['query:connect']);
		self::assertSame(1, SyncConfig::windowDays(Dataset::QueryPage, 900000.0));
		self::assertSame(14, SyncConfig::windowDays(Dataset::QueryPage, 1.0));
		self::assertSame(7, SyncConfig::windowDays(Dataset::Query, null));
	}

	public function test_retry_backoff_is_bounded(): void
	{
		self::assertSame([60, 300, 1800, 7200, null], array_map(static fn (int $attempt): ?int => SyncConfig::retryDelay($attempt), [1, 2, 3, 4, 5]));
		self::assertSame(900, SyncConfig::retryDelay(1, 900), 'Retry-After dłuższy niż backoff.');
		self::assertSame(300, SyncConfig::retryDelay(2, 30), 'Krótszy Retry-After nie skraca backoffu.');
		self::assertSame(SyncConfig::COOLDOWN_AFTER_RETRIES, SyncConfig::retryDelay(1, 999999));
		self::assertSame(5, SyncConfig::maxAttempts());
	}
}
