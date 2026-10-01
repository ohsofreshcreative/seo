<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Serp;

use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\MarketTaskRepository;
use OsfSeo\Serp\SerpRun;
use OsfSeo\Serp\SerpStartResult;
use OsfSeo\Serp\SerpTrackingService;
use OsfSeo\Tests\Support\DataForSeoFakes;
use WP_Error;

/**
 * Bezpieczniki kosztów pozycji SERP: wspólne limity z danymi rynkowymi i wyszukiwaniem fraz (rezerwacja przy
 * zakolejkowaniu), cały pomiar albo wcale, harmonogram i pominięcia, nakładanie się crona i pomiarów ręcznych,
 * odstęp pomiaru ręcznego, błąd konta, odrzucone zadania i zlecenie o nieznanym wyniku (bez ponawiania).
 */
final class SerpCostControlsTest extends SerpTestCase
{
	public function test_manual_check_only_queues_and_reserves_cost_in_the_shared_budget(): void
	{
		$context = $this->trackedProject(['buty damskie', 'żółte buty']);
		$result = $this->serp->start($context, 2, 2 * self::TOP100_COST);

		self::assertSame(SerpStartResult::QUEUED, $result->status);
		self::assertSame([], $this->dataForSeoRequests(), 'Pomiar ręczny w żądaniu panelu tylko kolejkuje — wysyła tło.');
		self::assertSame(SerpRun::QUEUED, $result->run->status);
		$reservation = self::db()->fetchRow('SELECT * FROM `' . self::db()->table('market_tasks') . '`');
		self::assertSame('pending', $reservation['status']);
		self::assertSame('serp_manual', $reservation['trigger_type']);
		self::assertEqualsWithDelta(2 * self::TOP100_COST, (float) $reservation['estimated_cost'], 1e-9);
		self::assertEqualsWithDelta(2 * self::TOP100_COST, $this->market->budget()->spentToday(), 1e-9, 'Rezerwacja liczy się od razu do wspólnego limitu.');

		$this->tasks->maintenance();
		self::assertSame('pending', self::db()->fetchValue('SELECT status FROM `' . self::db()->table('market_tasks') . '`'));
		$this->clock->advance(7200);
		$this->tasks->maintenance();
		self::assertSame('pending', self::db()->fetchValue('SELECT status FROM `' . self::db()->table('market_tasks') . '`'), 'Utrzymanie danych rynkowych nie unieważnia rezerwacji SERP.');
	}

	public function test_check_over_the_daily_limit_is_rejected_whole_without_partial_measurement(): void
	{
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=0.01');
		$this->buildServices();
		$context = $this->trackedProject(['jeden', 'dwa', 'trzy']);
		$plan = $this->serp->plan($context);

		self::assertSame('daily_limit', $plan->blockedBy(), 'Koszt pełnego pomiaru 0,01395 > limit 0,01.');
		$result = $this->serp->start($context);
		self::assertSame(SerpStartResult::OVER_BUDGET, $result->status);
		self::assertSame([], $this->dataForSeoRequests());
		self::assertSame(0, self::tableCount('market_tasks'));
		self::assertSame(0, self::tableCount('serp_snapshots'));
		self::assertSame(3, $this->serp->plan($context)->tasks(), 'Frazy nie zostały zajęte.');
	}

	public function test_monthly_limit_blocks_the_check(): void
	{
		putenv(MarketDataConfig::MONTHLY_COST_LIMIT . '=0.005');
		$this->buildServices();
		$context = $this->trackedProject(['jeden', 'dwa']);

		$result = $this->serp->start($context);
		self::assertSame(SerpStartResult::OVER_BUDGET, $result->status);
		self::assertSame('monthly_limit', $result->reason);
		self::assertSame(0, self::tableCount('serp_runs'));
	}

	public function test_budget_is_shared_with_market_data_and_discovery(): void
	{
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=0.1');
		$this->buildServices();
		$context = $this->trackedProject(['buty damskie']);
		// Wcześniejsze zadania innych modułów tego dnia: 0,098 z 0,1.
		$this->tasks->markCompleted($this->tasks->create('dataforseo', $this->provider->volumeEndpoint(), 'manual', $context->projectId(), $this->serp->market($context), ['x'], 0.05), 1, 0.05);
		$this->tasks->markCompleted($this->tasks->create('dataforseo', $this->provider->difficultyEndpoint(), MarketTaskRepository::TRIGGER_DISCOVERY, $context->projectId(), $this->serp->market($context), ['y'], 0.048), 1, 0.048);

		self::assertSame(SerpStartResult::OVER_BUDGET, $this->serp->start($context)->status, '0,098 + 0,00465 > 0,1.');

		$this->clock->advance(86400);
		self::assertSame(SerpStartResult::QUEUED, $this->serp->start($context)->status, 'Następnego dnia limit dzienny jest odnowiony.');

		$usage = $this->tasks->usageByPurpose('2026-01-01 00:00:00', $context->projectId());
		self::assertSame(1, $usage['serp']['tasks']);
		self::assertEqualsWithDelta(self::TOP100_COST, $usage['serp']['cost'], 1e-9);
		self::assertSame(1, $usage['enrichment']['tasks']);
		self::assertSame(1, $usage['discovery']['tasks']);
		self::assertSame(3, $usage['total']['tasks']);
		self::assertEqualsWithDelta(0.05 + 0.048 + self::TOP100_COST, $usage['total']['cost'], 1e-9);
	}

	public function test_scheduler_respects_frequency_and_disabled_tracking(): void
	{
		add_filter('wp_doing_cron', '__return_true');
		$context = $this->trackedProject(['buty damskie']);
		$this->mockSerpPost();

		self::assertSame([], $this->serp->schedule(), 'Śledzenie wyłączone domyślnie — brak płatnych pomiarów.');
		self::assertSame(0, $this->serp->runBackground()['submitted']['posts']);
		self::assertSame([], $this->google->requestsTo(self::SERP_POST));

		$this->enableTracking($context, 'every_3_days');
		$report = $this->serp->runBackground();
		self::assertSame([$context->publicId() => 'queued'], $report['scheduled']);
		self::assertSame(1, $report['submitted']['tasks']);
		$settings = $this->serp->settings($context);
		self::assertSame('2026-01-18 12:00:00', $settings->nextRunAt);

		$this->clock->advance(2 * 86400);
		self::assertSame([], $this->serp->schedule(), 'Przed terminem nic się nie dzieje.');
		$this->clock->advance(86400);
		self::assertSame([$context->publicId() => 'queued'], $this->serp->schedule());
		self::assertSame('2026-01-21 12:00:00', $this->serp->settings($context)->nextRunAt);

		$this->serp->saveSettings($context, ['enabled' => '0', 'frequency' => 'daily', 'device' => 'desktop', 'depth' => '100']);
		$this->clock->advance(10 * 86400);
		self::assertSame([], $this->serp->schedule());
	}

	public function test_daily_and_weekly_frequencies(): void
	{
		add_filter('wp_doing_cron', '__return_true');
		$daily = $this->trackedProject(['buty damskie'], 'dzienny.pl');
		$weekly = $this->trackedProject(['buty damskie'], 'tygodniowy.pl');
		$this->mockSerpPost();
		$this->enableTracking($daily, 'daily');
		$this->enableTracking($weekly, 'weekly');

		$this->serp->schedule();
		self::assertSame('2026-01-16 12:00:00', $this->serp->settings($daily)->nextRunAt);
		self::assertSame('2026-01-22 12:00:00', $this->serp->settings($weekly)->nextRunAt);
		self::assertSame(2, self::tableCount('serp_runs', "trigger_type = 'schedule'"));
		self::assertSame(1, self::tableCount('serp_contexts'), 'Ten sam rynek i ustawienia — wspólny kontekst, osobne pomiary projektów.');
	}

	public function test_scheduled_check_over_budget_is_skipped_with_reason_and_retried_after_reset(): void
	{
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=0.005');
		$this->buildServices();
		add_filter('wp_doing_cron', '__return_true');
		$context = $this->trackedProject(['jeden', 'dwa']);
		$this->mockSerpPost();
		$this->enableTracking($context, 'weekly');

		self::assertSame([$context->publicId() => 'budget:daily_limit'], $this->serp->schedule());
		$skipped = $this->serpRuns->recent($context->projectId())[0];
		self::assertSame(SerpRun::SKIPPED, $skipped->status);
		self::assertSame('daily_limit', $skipped->skipReason);
		self::assertSame(2, $skipped->keywordsPlanned);
		self::assertEqualsWithDelta(2 * self::TOP100_COST, $skipped->estimatedCost, 1e-9);
		self::assertSame('2026-01-16 00:05:00', $this->serp->settings($context)->retryAfter);
		self::assertSame('daily_limit', $this->serp->settings($context)->lastSkipReason);
		self::assertSame(0, self::tableCount('market_tasks'), 'Pominięty pomiar nic nie rezerwuje i nic nie kosztuje.');

		$this->clock->advance(3600);
		self::assertSame([], $this->serp->schedule(), 'Bez ponawiania przed odnowieniem limitu.');

		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=1');
		$this->buildServices();
		$this->clock->advance(12 * 3600);
		self::assertSame([$context->publicId() => 'queued'], $this->serp->schedule());
		self::assertSame([], $this->google->requestsTo(self::SERP_POST), 'schedule() tylko kolejkuje.');
	}

	public function test_overlapping_cron_processes_create_one_run_per_slot(): void
	{
		add_filter('wp_doing_cron', '__return_true');
		$context = $this->trackedProject(['buty damskie']);
		$this->enableTracking($context);
		$slot = $this->serp->settings($context)->nextRunAt;
		self::assertSame([$context->publicId() => 'queued'], $this->serp->schedule());

		// Drugi proces z tym samym terminem (odczytał ustawienia przed zapisem pierwszego) i z wolnymi frazami.
		$db = self::db();
		$db->execute("UPDATE `{$db->table('serp_settings')}` SET next_run_at = %s, last_run_at = NULL", [$slot]);
		$db->execute("UPDATE `{$db->table('serp_tracked_keywords')}` SET last_requested_at = NULL");

		self::assertSame([$context->publicId() => 'slot_taken'], $this->serp->schedule());
		self::assertSame(1, self::tableCount('serp_runs'));
		self::assertSame(1, self::tableCount('serp_snapshots'));
		self::assertSame(1, self::tableCount('market_tasks'), 'Bez podwójnej rezerwacji.');
	}

	public function test_manual_check_and_cron_do_not_order_the_same_keyword_twice(): void
	{
		add_filter('wp_doing_cron', '__return_true');
		$context = $this->trackedProject(['buty damskie', 'żółte buty']);
		$this->mockSerpPost();
		self::assertSame(SerpStartResult::QUEUED, $this->serp->start($context)->status);
		$this->enableTracking($context);

		self::assertSame([$context->publicId() => 'nothing_to_do'], $this->serp->schedule(), 'Frazy zlecone ręcznie przed chwilą nie są zlecane ponownie.');
		$this->serp->runBackground();
		self::assertSame(2, count(array_merge(...$this->serpPostBodies())));
		self::assertSame(2, self::tableCount('serp_snapshots'));
	}

	public function test_manual_check_has_cooldown_but_cli_does_not(): void
	{
		$context = $this->trackedProject(['buty damskie', 'żółte buty']);
		$first = $this->trackedIds($context);

		self::assertSame(SerpStartResult::QUEUED, $this->serp->start($context, null, null, [$first[0]])->status);
		self::assertSame(SerpStartResult::RATE_LIMITED, $this->serp->start($context, null, null, [$first[1]])->status);
		self::assertSame(SerpStartResult::QUEUED, $this->serp->start($context, null, null, [$first[1]], SerpTrackingService::TRIGGER_CLI)->status);
		self::assertSame(SerpStartResult::NOTHING_TO_DO, $this->serp->start($context, null, null, null, SerpTrackingService::TRIGGER_CLI)->status, 'Okno ponownego sprawdzenia chroni przed powtórnym zleceniem.');
	}

	public function test_plan_larger_than_confirmed_preview_is_not_queued(): void
	{
		$context = $this->trackedProject(['jeden', 'dwa']);

		self::assertSame(SerpStartResult::PLAN_CHANGED, $this->serp->start($context, 1, null)->status);
		self::assertSame(SerpStartResult::PLAN_CHANGED, $this->serp->start($context, 2, self::TOP100_COST)->status);
		self::assertSame(0, self::tableCount('serp_runs'));
	}

	public function test_rejected_tasks_fail_individually_and_cost_only_accepted_ones(): void
	{
		$context = $this->trackedProject(['dobra fraza', 'zła fraza']);
		$this->mockSerpPost(['zła fraza' => 40501]);
		$run = $this->queueAndSubmit($context);

		self::assertSame(2, $run->tasksSubmitted, 'Wysłane w zleceniu.');
		self::assertSame(1, $run->tasksFailed, 'Odrzucone przez dostawcę.');
		self::assertSame('task_40501', self::db()->fetchValue('SELECT error_code FROM `' . self::db()->table('serp_snapshots') . "` WHERE status = 'failed'"));
		$task = self::db()->fetchRow('SELECT * FROM `' . self::db()->table('market_tasks') . '`');
		self::assertSame('completed', $task['status']);
		self::assertEqualsWithDelta(self::TOP100_COST, (float) $task['cost'], 1e-9, 'Koszt zgłoszony przez dostawcę: tylko przyjęte zadanie.');

		$this->clock->advance(600);
		$this->mockSerpResults(['dobra fraza' => DataForSeoFakes::serpTop([4 => 'example.pl'])]);
		$this->serp->collect(60.0);
		self::assertSame(SerpRun::PARTIAL, $this->serpRuns->findById($run->id)->status);
	}

	public function test_account_error_pauses_paid_calls_and_requeues_the_batch(): void
	{
		add_filter('wp_doing_cron', '__return_true');
		$context = $this->trackedProject(['buty damskie']);
		$this->google->json(self::SERP_POST, 200, ['version' => '0.1', 'status_code' => 40200, 'status_message' => 'Payment Required.', 'cost' => 0, 'tasks_count' => 0, 'tasks_error' => 0, 'tasks' => null]);
		$queued = $this->serp->start($context);
		$report = $this->serp->runBackground();

		self::assertSame('billing', $report['submitted']['stopped']);
		self::assertNotNull($this->market->paused());
		self::assertSame('queued', self::db()->fetchValue('SELECT status FROM `' . self::db()->table('serp_snapshots') . '`'), 'Dostawca nic nie wykonał — paczka czeka.');
		self::assertSame('pending', self::db()->fetchValue('SELECT status FROM `' . self::db()->table('market_tasks') . '`'));
		self::assertSame('billing', $this->serpRuns->findById($queued->run->id)->errorCode);

		$this->mockSerpPost();
		$this->serp->runBackground();
		self::assertCount(1, $this->google->requestsTo(self::SERP_POST), 'Wstrzymanie: bez kolejnych płatnych żądań.');
		$this->serp->addKeywords($context, 'manual', ['żółte buty']);
		self::assertSame(SerpStartResult::PAUSED, $this->serp->start($context, null, null, null, SerpTrackingService::TRIGGER_CLI)->status);

		delete_option(MarketSyncService::PAUSE_OPTION);
		$this->serp->runBackground();
		self::assertCount(2, $this->google->requestsTo(self::SERP_POST));
		self::assertSame('submitted', self::db()->fetchValue('SELECT status FROM `' . self::db()->table('serp_snapshots') . '`'));
	}

	public function test_post_with_unknown_outcome_is_not_retried_and_is_recovered_by_tag(): void
	{
		$keywords = array_map(static fn (int $i): string => 'fraza ' . $i, range(1, 120));
		$context = $this->trackedProject($keywords);
		$this->google->on(self::SERP_POST, new WP_Error('http_request_failed', 'Operation timed out'));
		$run = $this->serp->start($context, null, null, null, SerpTrackingService::TRIGGER_CLI)->run;
		$report = $this->serp->execute($context, $run);

		self::assertSame(100, $report['uncertain']);
		self::assertSame('network', $report['stopped']);
		self::assertCount(1, $this->google->requestsTo(self::SERP_POST), 'Płatne zlecenie o nieznanym wyniku nie jest ponawiane.');
		self::assertSame(100, self::tableCount('serp_snapshots', "status = 'uncertain'"));
		self::assertSame(20, self::tableCount('serp_snapshots', "status = 'cancelled'"), 'Reszta przebiegu anulowana.');
		$tasks = self::db()->fetchAll('SELECT status, error_code, estimated_cost FROM `' . self::db()->table('market_tasks') . '` ORDER BY id');
		self::assertSame(['failed', 'network'], [$tasks[0]['status'], $tasks[0]['error_code']]);
		self::assertEqualsWithDelta(100 * self::TOP100_COST, (float) $tasks[0]['estimated_cost'], 1e-6, 'Koszt szacowany zostaje w limicie (mogło zostać opłacone).');
		self::assertSame(['failed', 'cancelled'], [$tasks[1]['status'], $tasks[1]['error_code']]);
		self::assertSame(0.0, (float) $tasks[1]['estimated_cost'], 'Rezerwacja niewysłanej paczki zwolniona.');
		self::assertSame(20, $this->serp->plan($context)->tasks(), 'Anulowane frazy znów do sprawdzenia; niepewne — nie.');

		// Dostawca jednak utworzył zadania: lista gotowych zadań zwraca je z tagami.
		$uncertain = self::db()->fetchAll('SELECT public_id FROM `' . self::db()->table('serp_snapshots') . "` WHERE status = 'uncertain' ORDER BY id LIMIT 2");
		$ready = [];

		foreach ($uncertain as $index => $row) {
			$id = DataForSeoFakes::taskId();
			$ready[] = [$id, $row['public_id']];
			$this->serpTasks[$row['public_id']] = ['id' => $id, 'keyword' => 'fraza ' . ($index + 1)];
		}

		$this->clock->advance(900);
		$this->google->json(self::SERP_READY, 200, DataForSeoFakes::serpTasksReady($ready));
		$this->mockSerpResults(['fraza 1' => DataForSeoFakes::serpTop([3 => 'example.pl']), 'fraza 2' => DataForSeoFakes::serpTop([])]);
		$collected = $this->serp->collect(60.0);

		self::assertSame(2, $collected['recovered']);
		self::assertSame(2, $collected['completed']);
		self::assertSame(3, $this->row($context, 'fraza 1')->rank);
		self::assertCount(1, $this->google->requestsTo(self::SERP_POST));

		// Niepewne zlecenia, których lista nie zwróciła przez 72 h, kończą się bez ponawiania.
		$this->clock->advance(73 * 3600);
		$this->google->json(self::SERP_READY, 200, DataForSeoFakes::serpTasksReady([]));
		self::assertSame(98, $this->serp->collect(60.0)['interrupted']);
		self::assertSame(98, self::tableCount('serp_snapshots', "status = 'failed' AND error_code = 'interrupted'"));
		self::assertCount(1, $this->google->requestsTo(self::SERP_POST));
	}

	public function test_cancelling_a_queued_check_releases_reservation_and_keywords(): void
	{
		$context = $this->trackedProject(['buty damskie', 'żółte buty']);
		$run = $this->serp->start($context)->run;

		self::assertTrue($this->serp->cancel($context, $run->publicId));
		self::assertSame(SerpRun::CANCELLED, $this->serpRuns->findById($run->id)->status);
		self::assertSame(0.0, $this->market->budget()->spentToday());
		self::assertSame(2, $this->serp->plan($context)->tasks());
		self::assertFalse($this->serp->cancel($context, $run->publicId));
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_paid_submission_and_collection_wait_for_locks_held_by_other_processes(): void
	{
		add_filter('wp_doing_cron', '__return_true');
		$context = $this->trackedProject(['buty damskie']);
		$this->mockSerpPost();
		$run = $this->serp->start($context)->run;

		// Inny proces wysyła płatne żądania (dane rynkowe, wyszukiwanie fraz albo SERP) — wspólna blokada.
		$other = $this->holdLock(MarketSyncService::LOCK);

		try {
			self::assertSame(['collected', 'scheduled', 'submitted'], array_keys($this->serp->runBackground()));
			self::assertNull($this->serp->runBackground()['submitted']);
			self::assertSame('locked', $this->serp->execute($context, $run)['status']);
		} finally {
			$this->releaseHeldLock($other, MarketSyncService::LOCK);
		}

		self::assertSame([], $this->google->requestsTo(self::SERP_POST));
		self::assertSame(1, $this->serp->runBackground()['submitted']['tasks']);

		// Odbiór trwa w innym procesie — drugi nie pobiera tych samych wyników.
		$this->clock->advance(600);
		$this->mockSerpResults(['buty damskie' => DataForSeoFakes::serpTop([1 => 'example.pl'])]);
		$other = $this->holdLock('serp_collect');

		try {
			self::assertSame(0, $this->serp->collect(60.0)['checked']);
		} finally {
			$this->releaseHeldLock($other, 'serp_collect');
		}

		self::assertSame(1, $this->serp->collect(60.0)['completed']);
		self::assertSame(0, $this->serp->collect(60.0)['checked'], 'Wynik zapisany raz.');
		self::assertSame(100, self::tableCount('serp_results'));
	}

	public function test_background_step_runs_only_in_system_process(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->enableTracking($context);
		$this->mockSerpPost();

		self::assertSame(['collected' => null, 'scheduled' => [], 'submitted' => null], $this->serp->runBackground(), 'Nigdy przy renderowaniu strony.');
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_market_collection_ignores_serp_reservations(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->mockSerpPost();
		$this->queueAndSubmit($context);
		$this->clock->advance(3600);

		self::assertSame(0, $this->market->collect()['checked'], 'Odbiór wolumenu (STEP 12) nie dotyka zleceń SERP.');
		self::assertSame(0, $this->tasks->pendingCount($context->projectId()));
	}

	/**
	 * @return list<string>
	 */
	private function trackedIds(\OsfSeo\Auth\ProjectContext $context): array
	{
		return array_map(static fn ($row): string => $row->publicId, $this->serp->positions($context, \OsfSeo\Serp\PositionsFilters::fromInput(['sort' => 'keyword']))['rows']);
	}
}
