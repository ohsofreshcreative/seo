<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Market;

use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoMarkets;
use OsfSeo\DataForSeo\DataForSeoProvider;
use OsfSeo\Market\CostBudget;
use OsfSeo\Market\MarketCandidate;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\PlannedTask;
use OsfSeo\Market\ResultMapper;
use OsfSeo\Market\SyncPlan;
use OsfSeo\Support\Logger;
use OsfSeo\Tests\Support\FakeHttpTransport;
use OsfSeo\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class CostControlsTest extends TestCase
{
	private FakeHttpTransport $http;

	private DataForSeoProvider $provider;

	protected function setUp(): void
	{
		$this->http = new FakeHttpTransport();
		$config = new DataForSeoConfig();
		$this->provider = new DataForSeoProvider(new DataForSeoClient($config, $this->http, new RecordingSleeper(), new Logger(Logger::ERROR, static function (): void {
		})), $config);
	}

	protected function tearDown(): void
	{
		foreach ([MarketDataConfig::MAX_TASKS_PER_RUN, MarketDataConfig::DAILY_COST_LIMIT, MarketDataConfig::VOLUME_TTL_DAYS, MarketDataConfig::AUTO_REFRESH, MarketDataConfig::MIN_IMPRESSIONS] as $name) {
			putenv($name);
		}
	}

	/**
	 * @return list<MarketCandidate>
	 */
	private static function candidates(int $count, bool $volume = true, bool $difficulty = true): array
	{
		return array_map(static fn (int $i): MarketCandidate => new MarketCandidate('fraza ' . $i, 'Fraza ' . $i, 1000 - $i, 10, $volume, $difficulty), range(1, $count));
	}

	public function test_config_defaults_are_conservative_and_values_are_clamped(): void
	{
		$config = new MarketDataConfig();
		self::assertSame([30, 30, 4, 1.0, 10.0, 50, 90, 1000, true], [
			$config->volumeTtlDays(), $config->difficultyTtlDays(), $config->maxTasksPerRun(), $config->dailyCostLimit(),
			$config->monthlyCostLimit(), $config->minImpressions(), $config->windowDays(), $config->syncLimit(), $config->autoRefresh(),
		]);

		putenv(MarketDataConfig::MAX_TASKS_PER_RUN . '=5000');
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=abc');
		putenv(MarketDataConfig::VOLUME_TTL_DAYS . '=1');
		putenv(MarketDataConfig::AUTO_REFRESH . '=off');
		putenv(MarketDataConfig::MIN_IMPRESSIONS . '=200');

		self::assertSame([50, 1.0, 7, false, 200], [$config->maxTasksPerRun(), $config->dailyCostLimit(), $config->volumeTtlDays(), $config->autoRefresh(), $config->minImpressions()]);
	}

	public function test_budget_blocks_tasks_over_daily_monthly_and_task_limits(): void
	{
		$budget = new CostBudget(1.0, 10.0, 0.95, 2.0, 4);
		self::assertSame(CostBudget::OK, $budget->check(0.05));
		self::assertSame(CostBudget::DAILY_LIMIT, $budget->check(0.06));

		$monthly = new CostBudget(5.0, 10.0, 0.0, 9.99, 4);
		self::assertSame(CostBudget::MONTHLY_LIMIT, $monthly->check(0.06));
		self::assertSame(CostBudget::MONTHLY_LIMIT, (new CostBudget(5.0, 10.0, 0.0, 10.0, 4))->status());

		$tasks = new CostBudget(100.0, 100.0, 0.0, 0.0, 2);
		$tasks->spend(0.06);
		$tasks->spend(0.06);
		self::assertSame(CostBudget::TASK_LIMIT, $tasks->check(0.0));
		self::assertSame(2, $tasks->tasks());

		self::assertSame(CostBudget::DAILY_LIMIT, (new CostBudget(0.0, 10.0, 0.0, 0.0, 4))->check(0.01), 'Limit 0 blokuje płatne wywołania.');
	}

	public function test_plan_batches_keywords_at_the_maximum_task_size_and_interleaves_endpoints(): void
	{
		$plan = SyncPlan::build(DataForSeoMarkets::resolve('pl', 'pl'), $this->provider, self::candidates(2500), new CostBudget(100.0, 100.0, 0.0, 0.0, 50));

		self::assertSame(
			[[PlannedTask::VOLUME, 1000], [PlannedTask::DIFFICULTY, 1000], [PlannedTask::VOLUME, 1000], [PlannedTask::DIFFICULTY, 1000], [PlannedTask::VOLUME, 500], [PlannedTask::DIFFICULTY, 500]],
			array_map(static fn (PlannedTask $task): array => [$task->type, count($task->keywords)], $plan->tasks),
		);
		self::assertSame(2500, $plan->keywordsToSend());
		self::assertEqualsWithDelta(3 * 0.06 + 3 * 0.012 + 2500 * 0.00012, $plan->estimatedCost(), 1e-9);
		self::assertNull($plan->blockedBy());
		self::assertSame([], $this->http->requests, 'Plan nie wysyła żadnych żądań.');
	}

	public function test_plan_respects_hard_task_limit_and_cost_budget(): void
	{
		$limited = SyncPlan::build(DataForSeoMarkets::resolve('pl', 'pl'), $this->provider, self::candidates(2500), new CostBudget(100.0, 100.0, 0.0, 0.0, 3));
		self::assertSame(3, count($limited->allowedTasks()));
		self::assertSame(CostBudget::TASK_LIMIT, $limited->blockedBy());
		self::assertSame(2000, $limited->keywordsToSend(), 'Wolumen ×2 i trudność ×1: 2000 różnych fraz w tym przebiegu.');

		$budget = SyncPlan::build(DataForSeoMarkets::resolve('pl', 'pl'), $this->provider, self::candidates(10), new CostBudget(0.07, 100.0, 0.0, 0.0, 10));
		self::assertSame([true, false], array_map(static fn (PlannedTask $task): bool => $task->isAllowed(), $budget->tasks), 'Wolumen 0,06 mieści się w 0,07; trudność już nie.');
		self::assertSame(CostBudget::DAILY_LIMIT, $budget->blockedBy());
	}

	public function test_plan_only_requests_missing_metrics(): void
	{
		$candidates = [
			new MarketCandidate('tylko wolumen', 'tylko wolumen', 100, 1, true, false),
			new MarketCandidate('tylko trudność', 'tylko trudność', 90, 1, false, true),
		];
		$plan = SyncPlan::build(DataForSeoMarkets::resolve('pl', 'pl'), $this->provider, $candidates, new CostBudget(10.0, 10.0, 0.0, 0.0, 4));

		self::assertSame([['tylko wolumen'], ['tylko trudność']], array_map(static fn (PlannedTask $task): array => $task->keywords, $plan->tasks));
	}

	public function test_result_mapping_handles_case_whitespace_and_modified_punctuation(): void
	{
		[$mapped, $unmatched] = ResultMapper::map(
			['żółte buty', 'wp-config.php', 'kurs c++', 'buty damskie'],
			['ŻÓŁTE  BUTY', 'wp config php', 'nieznana fraza', 'buty damskie', 'buty damskie'],
		);

		self::assertSame([0 => 'żółte buty', 1 => 'wp-config.php', 3 => 'buty damskie'], $mapped);
		self::assertSame(2, $unmatched, 'Nieznana fraza i duplikat wyniku nie są przypisywane.');

		[$ambiguous] = ResultMapper::map(['a-b', 'a.b'], ['a b']);
		self::assertSame([], $ambiguous, 'Niejednoznaczna postać luźna nie jest dopasowywana.');
	}
}
