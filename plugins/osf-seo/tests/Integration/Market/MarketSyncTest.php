<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Market;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Market\CostBudget;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketSyncResult;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\PlannedTask;
use OsfSeo\Tests\Support\DataForSeoFakes;

final class MarketSyncTest extends MarketTestCase
{
	private const SELECTED = ['buty damskie', 'żółte buty', 'kurs c++', 'sklep internetowy'];

	public function test_dry_run_plan_makes_zero_external_requests(): void
	{
		$context = $this->projectWithKeywords();
		$requestsBefore = count($this->google->requests);

		$plan = $this->market->plan($context);

		self::assertSame($requestsBefore, count($this->google->requests), 'Dry-run nie wysyła żadnego żądania.');
		self::assertSame([], $this->dataForSeoRequests());
		self::assertSame(self::SELECTED, array_map(static fn ($c) => $c->keyword, $plan->candidates), 'Kolejność wg wyświetleń; warianty scalone; ? odrzucony; < 50 wyświetleń pominięte.');
		self::assertSame(1, $plan->rejected);
		self::assertSame(1, $plan->duplicates);
		self::assertSame([PlannedTask::VOLUME, PlannedTask::DIFFICULTY], array_map(static fn (PlannedTask $t): string => $t->type, $plan->tasks));
		self::assertEqualsWithDelta(0.06 + 0.012 + 4 * 0.00012, $plan->estimatedCost(), 1e-9);
		$data = $plan->toArray();
		self::assertSame(['Polska / polski', 2616, 'pl'], [$data['market'], $data['location_code'], $data['language_code']]);
		self::assertSame(['keywords_data/google_ads/search_volume/task_post', 'standard'], [$data['volume']['path'], $data['volume']['mode']]);
		self::assertSame(['dataforseo_labs/google/bulk_keyword_difficulty/live', 'live'], [$data['difficulty']['path'], $data['difficulty']['mode']]);
		self::assertSame(0, self::tableCount('market_tasks'));
		self::assertSame(0, self::tableCount('market_keywords'));
	}

	public function test_standard_volume_lifecycle_and_live_difficulty(): void
	{
		$context = $this->projectWithKeywords();
		$taskId = DataForSeoFakes::taskId();
		$this->mockVolumePost($taskId, 0.06);
		$this->mockDifficulty(['buty damskie' => 35, 'żółte buty' => null, 'kurs c++' => 61], 0.01248);

		$result = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		self::assertSame(MarketSyncResult::SUCCESS, $result->status);
		self::assertSame([1, 4, 1, 4], [$result->volumeTasks, $result->volumeKeywords, $result->difficultyTasks, $result->difficultyKeywords]);
		self::assertEqualsWithDelta(0.06 + 0.01248, $result->cost, 1e-9);
		$requests = $this->dataForSeoRequests();
		self::assertCount(2, $requests);
		self::assertSame([self::VOLUME_POST, self::DIFFICULTY], array_column($requests, 'url'));
		self::assertSame(self::SELECTED, self::requestKeywords($requests[0]), 'Postać znormalizowana, jedna paczka.');
		self::assertSame(['location_code' => 2616, 'language_code' => 'pl'], array_intersect_key(json_decode($requests[0]['body'], true)[0], ['location_code' => 1, 'language_code' => 1]));
		self::assertSame('Basic ' . base64_encode($this->login . ':' . $this->password), $requests[0]['headers']['Authorization']);

		// Trudność zapisana od razu (Live); brak wyniku = pobrane bez danych (NULL, nie 0).
		self::assertSame('35', $this->marketRow('buty damskie')['keyword_difficulty']);
		self::assertNull($this->marketRow('żółte buty')['keyword_difficulty']);
		self::assertNotNull($this->marketRow('sklep internetowy')['difficulty_fetched_at']);
		self::assertNull($this->marketRow('sklep internetowy')['keyword_difficulty']);
		// Wolumen czeka na wynik zadania Standard.
		self::assertNull($this->marketRow('buty damskie')['volume_fetched_at']);
		self::assertSame('2026-01-17 12:00:00', $this->marketRow('buty damskie')['volume_pending_until']);
		self::assertSame(1, $this->tasks->pendingCount($context->projectId()));
		self::assertNotNull($this->states->get($context->projectId(), 'dataforseo')['enabled_at'], 'Jawna synchronizacja włącza automatyczne odświeżanie.');

		// Zadanie w kolejce — bez zapisu, kolejna próba później.
		$this->clock->advance(301);
		$this->google->json(self::VOLUME_GET . $taskId, 200, DataForSeoFakes::taskInQueue($taskId));
		self::assertSame(['checked' => 1, 'completed' => 0, 'pending' => 1, 'failed' => 0, 'expired' => 0], $this->market->collect());
		self::assertNull($this->marketRow('buty damskie')['volume_fetched_at']);

		// Wynik: fraza zwrócona wielkimi literami jest dopasowana; brak wyniku = brak danych.
		$this->clock->advance(601);
		$this->google->json(self::VOLUME_GET . $taskId, 200, DataForSeoFakes::volumeResult($taskId, [
			DataForSeoFakes::volumeItem('BUTY DAMSKIE', 2400, 0.95, 'HIGH', 88),
			DataForSeoFakes::volumeItem('żółte buty', null, null, null, null),
			DataForSeoFakes::volumeItem('kurs c++', 590, 2.1, 'LOW', 12),
		]));
		self::assertSame(1, $this->market->collect()['completed']);

		$buty = $this->marketRow('buty damskie');
		self::assertSame(['2400', '0.9500', 'high', '88', null], [$buty['search_volume'], $buty['cpc'], $buty['competition_level'], $buty['competition_index'], $buty['volume_pending_until']]);
		self::assertSame('2026-02-14 12:15:02', $buty['volume_stale_after'], 'TTL 30 dni.');
		self::assertNull($this->marketRow('żółte buty')['search_volume']);
		self::assertNotNull($this->marketRow('żółte buty')['volume_fetched_at']);
		self::assertNull($this->marketRow('sklep internetowy')['search_volume']);
		self::assertSame(4, self::tableCount('market_keyword_monthly'), 'Historia: 2 miesiące × 2 frazy z danymi.');
		self::assertSame(0, $this->tasks->pendingCount());

		// Świeże metryki nie są pobierane ponownie.
		$before = count($this->dataForSeoRequests());
		$again = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);
		self::assertSame(MarketSyncResult::NOTHING_TO_DO, $again->status);
		self::assertSame($before, count($this->dataForSeoRequests()));

		// Po TTL — nieaktualne, wybierane ponownie (wolumen i trudność).
		$this->clock->advance(31 * 86400);
		$stale = $this->market->plan($context);
		self::assertSame(self::SELECTED, array_map(static fn ($c) => $c->keyword, $stale->candidates));
		self::assertCount(2, $stale->tasks);
	}

	public function test_history_refresh_overwrites_overlapping_months_instead_of_duplicating(): void
	{
		$context = $this->projectWithKeywords(keywords: [['buty damskie', 500, 40]]);
		$market = $this->market->market($context);
		$monthly = [['year' => 2026, 'month' => 7, 'search_volume' => 100], ['year' => 2026, 'month' => 8, 'search_volume' => 200]];

		foreach ([[$monthly, 1], [[['year' => 2026, 'month' => 8, 'search_volume' => 250], ['year' => 2026, 'month' => 9, 'search_volume' => 300]], 2]] as [$months, $taskId]) {
			$items = \OsfSeo\DataForSeo\DataForSeoProvider::parseVolume([DataForSeoFakes::volumeItem('buty damskie', 300, 1.0, 'LOW', 5, $months)]);
			$this->marketMetrics->storeVolume($market, ['buty damskie'], ['buty damskie' => $items[0]], $taskId, 30);
		}

		$id = (int) $this->marketRow('buty damskie')['id'];
		self::assertSame([
			['month' => '2026-07-01', 'search_volume' => 100],
			['month' => '2026-08-01', 'search_volume' => 250],
			['month' => '2026-09-01', 'search_volume' => 300],
		], $this->marketMetrics->history([$id])[$id]);
	}

	public function test_pending_volume_is_never_requested_twice_even_with_force(): void
	{
		$context = $this->projectWithKeywords();
		$this->mockVolumePost(DataForSeoFakes::taskId());
		$this->mockDifficulty(['buty damskie' => 10]);
		$this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		self::assertSame(MarketSyncResult::NOTHING_TO_DO, $this->market->sync($context, MarketSyncService::TRIGGER_CLI)->status);

		$this->mockDifficulty(['buty damskie' => 11]);
		$forced = $this->market->sync($context, MarketSyncService::TRIGGER_CLI, null, true);
		self::assertSame([0, 1], [$forced->volumeTasks, $forced->difficultyTasks], '--force odświeża trudność, ale nie dubluje oczekującego wolumenu.');
		self::assertSame(1, count(array_filter($this->dataForSeoRequests(), static fn (array $r): bool => $r['url'] === self::VOLUME_POST)));
	}

	public function test_expired_standard_task_releases_keywords_for_a_later_run(): void
	{
		$context = $this->projectWithKeywords();
		$taskId = DataForSeoFakes::taskId();
		$this->mockVolumePost($taskId);
		$this->mockDifficulty([]);
		$this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		$this->clock->advance(49 * 3600);
		$this->google->json(self::VOLUME_GET . $taskId, 200, DataForSeoFakes::taskInQueue($taskId));

		self::assertSame(1, $this->market->collect()['expired']);
		self::assertNull($this->marketRow('buty damskie')['volume_pending_until']);
		self::assertSame('expired', self::db()->fetchValue('SELECT status FROM `' . self::db()->table('market_tasks') . "` WHERE endpoint = 'google_ads_search_volume'"));
		self::assertSame(PlannedTask::VOLUME, $this->market->plan($context)->tasks[0]->type);
	}

	public function test_cost_and_usage_are_logged_per_task(): void
	{
		$context = $this->projectWithKeywords();
		$this->mockVolumePost(DataForSeoFakes::taskId(), 0.06);
		$this->mockDifficulty(['buty damskie' => 30, 'kurs c++' => 40], 0.01224);
		$this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		$rows = self::db()->fetchAll('SELECT endpoint, mode, trigger_type, project_id, location_code, language_code, status, keywords_count, results_count, estimated_cost, cost FROM `' . self::db()->table('market_tasks') . '` ORDER BY id');
		self::assertSame([
			['endpoint' => 'google_ads_search_volume', 'mode' => 'standard', 'trigger_type' => 'cli', 'project_id' => (string) $context->projectId(), 'location_code' => '2616', 'language_code' => 'pl', 'status' => 'pending', 'keywords_count' => '4', 'results_count' => '0', 'estimated_cost' => '0.060000', 'cost' => '0.060000'],
			['endpoint' => 'labs_bulk_keyword_difficulty', 'mode' => 'live', 'trigger_type' => 'cli', 'project_id' => (string) $context->projectId(), 'location_code' => '2616', 'language_code' => 'pl', 'status' => 'completed', 'keywords_count' => '4', 'results_count' => '2', 'estimated_cost' => '0.012480', 'cost' => '0.012240'],
		], $rows);
		$usage = $this->tasks->usage('2026-01-15 00:00:00', $context->projectId());
		self::assertSame([2, 0, 8], [$usage['tasks'], $usage['failed'], $usage['keywords']]);
		self::assertEqualsWithDelta(0.07224, $usage['reported_cost'], 1e-9);
		self::assertEqualsWithDelta(0.07224, $this->market->budget()->spentToday(), 1e-9);
	}

	public function test_daily_safety_budget_stops_paid_sync_before_any_request(): void
	{
		$context = $this->projectWithKeywords();
		$this->insertSpentTask('2026-01-15 01:00:00', 0.98);

		$result = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		self::assertSame(MarketSyncResult::SKIPPED, $result->status);
		self::assertSame(CostBudget::DAILY_LIMIT, $result->blockedBy);
		self::assertSame([], $this->dataForSeoRequests());
		self::assertSame(1, self::tableCount('market_tasks'), 'Zablokowane zadanie nie jest zapisywane ani wysyłane.');

		// Następna doba (UTC) — limit dzienny odnowiony.
		$this->clock->advance(86400);
		self::assertNull($this->market->plan($context)->blockedBy());
	}

	public function test_monthly_safety_budget_stops_paid_sync(): void
	{
		putenv(MarketDataConfig::MONTHLY_COST_LIMIT . '=0.5');
		$context = $this->projectWithKeywords();
		$this->insertSpentTask('2026-01-05 10:00:00', 0.45);

		$result = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		self::assertSame(CostBudget::MONTHLY_LIMIT, $result->blockedBy);
		self::assertSame([], $this->dataForSeoRequests());
		self::assertSame(CostBudget::OK, $this->market->budget()->status(), 'Limit nie jest jeszcze wyczerpany — blokuje tylko zadanie, które by go przekroczyło.');
		$this->insertSpentTask('2026-01-06 10:00:00', 0.05);
		self::assertSame(CostBudget::MONTHLY_LIMIT, $this->market->budget()->status());
	}

	public function test_hard_task_limit_per_run(): void
	{
		putenv(MarketDataConfig::MAX_TASKS_PER_RUN . '=1');
		$context = $this->projectWithKeywords();
		$this->mockVolumePost(DataForSeoFakes::taskId());

		$result = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		self::assertSame(MarketSyncResult::PARTIAL, $result->status);
		self::assertSame(CostBudget::TASK_LIMIT, $result->blockedBy);
		self::assertCount(1, $this->dataForSeoRequests());
		self::assertSame('2026-01-15 13:00:00', $this->states->get($context->projectId(), 'dataforseo')['next_auto_at'], 'Reszta w kolejnym przebiegu (za godzinę).');
	}

	public function test_invalid_credentials_stop_without_retry_and_pause_automation(): void
	{
		$context = $this->projectWithKeywords();
		$this->google->on(self::VOLUME_POST, ['status' => 401, 'json' => ['status_code' => 40100, 'status_message' => 'You are not authorized to access this resource.']]);

		$result = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		self::assertSame(MarketSyncResult::FAILED, $result->status);
		self::assertSame('authentication', $result->error?->value);
		self::assertCount(1, $this->dataForSeoRequests(), 'Bez ponowień i bez kolejnych zadań.');
		self::assertSame(['status' => 'failed', 'error_code' => 'authentication', 'estimated_cost' => '0.000000'], self::db()->fetchRow('SELECT status, error_code, estimated_cost FROM `' . self::db()->table('market_tasks') . '`'));
		self::assertSame('authentication', $this->market->paused()['reason']);
		self::assertSame('authentication', $this->states->get($context->projectId(), 'dataforseo')['last_error']);
		self::assertNull($this->marketRow('buty damskie'), 'Nic nie zostało oznaczone jako oczekujące.');
	}

	public function test_insufficient_balance_is_a_billing_error_without_retry(): void
	{
		$context = $this->projectWithKeywords();
		$this->google->on(self::VOLUME_POST, ['status' => 200, 'json' => DataForSeoFakes::envelope(['status_code' => 40210, 'status_message' => 'Insufficient Funds.'])]);

		$result = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		self::assertSame('billing', $result->error?->value);
		self::assertCount(1, $this->dataForSeoRequests());
		self::assertSame('billing', $this->market->paused()['reason']);
	}

	public function test_transient_error_is_recorded_and_keywords_stay_eligible(): void
	{
		$context = $this->projectWithKeywords();
		$this->google->on(self::VOLUME_POST, ['status' => 503, 'json' => ['status_code' => 50000, 'status_message' => 'Internal Error.']]);

		$result = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		self::assertSame('transient', $result->error?->value);
		self::assertCount(1, $this->dataForSeoRequests(), 'Płatne zlecenie nie jest ponawiane w żądaniu.');
		self::assertNull($this->market->paused(), 'Błąd przejściowy nie wstrzymuje całego konta.');
		self::assertSame('0.060000', self::db()->fetchValue('SELECT estimated_cost FROM `' . self::db()->table('market_tasks') . '`'), 'Nieznany wynik — koszt szacowany zostaje w limicie.');
		self::assertSame(4, $this->market->plan($context)->keywordCount());
	}

	public function test_task_level_error_on_collect_fails_the_task_and_releases_keywords(): void
	{
		$context = $this->projectWithKeywords();
		$taskId = DataForSeoFakes::taskId();
		$this->mockVolumePost($taskId);
		$this->mockDifficulty([]);
		$this->market->sync($context, MarketSyncService::TRIGGER_CLI);
		$this->clock->advance(301);
		$this->google->json(self::VOLUME_GET . $taskId, 200, DataForSeoFakes::envelope(['id' => $taskId, 'status_code' => 40401, 'status_message' => 'Task Not Found.']));

		self::assertSame(1, $this->market->collect()['failed']);
		self::assertNull($this->marketRow('buty damskie')['volume_pending_until']);
		self::assertSame('task_error', self::db()->fetchValue('SELECT error_code FROM `' . self::db()->table('market_tasks') . "` WHERE endpoint = 'google_ads_search_volume'"));
	}

	public function test_manual_sync_requires_market_data_capability(): void
	{
		$context = $this->projectWithKeywords();
		$client = $this->createUser('osf_seo_client');
		$this->repositoryAssign($context, $client);
		$clientContext = $this->guard->authorize($context->publicId(), $client);

		self::assertFalse($clientContext->can(Capabilities::MANAGE_MARKET_DATA));

		try {
			$this->market->sync($clientContext, MarketSyncService::TRIGGER_MANUAL);
			self::fail('Klient nie może uruchomić płatnej synchronizacji.');
		} catch (AccessDenied) {
			self::assertSame([], $this->dataForSeoRequests());
		}

		$admin = $this->createUser('osf_seo_admin');
		self::assertTrue($this->guard->authorize($context->publicId(), $admin)->can(Capabilities::MANAGE_MARKET_DATA));
	}

	public function test_duplicate_manual_sync_cannot_double_charge(): void
	{
		$context = $this->projectWithKeywords();
		$admin = $this->guard->authorize($context->publicId(), $this->createUser('administrator'));
		$this->mockVolumePost(DataForSeoFakes::taskId());
		$this->mockDifficulty(['buty damskie' => 20]);
		$expected = $this->market->plan($admin)->keywordsToSend();

		self::assertSame(MarketSyncResult::SUCCESS, $this->market->sync($admin, MarketSyncService::TRIGGER_MANUAL, null, false, $expected)->status);
		self::assertSame(MarketSyncResult::RATE_LIMITED, $this->market->sync($admin, MarketSyncService::TRIGGER_MANUAL, null, false, $expected)->status);
		self::assertCount(2, $this->dataForSeoRequests(), 'Drugie kliknięcie niczego nie wysyła.');

		// Trwająca synchronizacja (blokada) — kolejna od razu kończy się bez żądań.
		$other = new \mysqli(...self::mysqliArgs());
		$other->query("SELECT GET_LOCK('" . $other->real_escape_string(self::db()->lockName('market_sync')) . "', 0)");

		try {
			self::assertSame(MarketSyncResult::ALREADY_RUNNING, $this->market->sync($admin, MarketSyncService::TRIGGER_CLI)->status);
		} finally {
			$other->close();
		}
	}

	public function test_manual_sync_refuses_a_plan_larger_than_the_confirmed_preview(): void
	{
		$context = $this->projectWithKeywords();
		$admin = $this->guard->authorize($context->publicId(), $this->createUser('administrator'));

		$result = $this->market->sync($admin, MarketSyncService::TRIGGER_MANUAL, null, false, 2);

		self::assertSame(MarketSyncResult::PLAN_CHANGED, $result->status);
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_missing_credentials_mean_no_requests(): void
	{
		$context = $this->projectWithKeywords();
		DataForSeoFakes::clear();

		$result = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		self::assertSame(MarketSyncResult::NOT_CONFIGURED, $result->status);
		self::assertSame('OSF_SEO_DATAFORSEO_LOGIN, OSF_SEO_DATAFORSEO_PASSWORD', $result->reason);
		self::assertSame([], $this->dataForSeoRequests());
		self::assertFalse($this->market->status($context)['configured']);
	}

	public function test_background_refresh_runs_only_for_explicitly_enabled_projects(): void
	{
		$context = $this->projectWithKeywords();
		$this->systemContext($context);

		// Wdrożenie: projekt nigdy nie był jawnie synchronizowany — tło niczego nie wysyła (tylko klucze rynkowe fraz).
		$report = $this->market->runBackground(20.0, true);
		self::assertSame([], $report['projects']);
		self::assertSame([], $this->dataForSeoRequests());
		self::assertSame(0, self::tableCount('keywords', 'market_key IS NULL'), 'Klucze rynkowe uzupełnione w tle.');

		$this->mockVolumePost($taskId = DataForSeoFakes::taskId());
		$this->mockDifficulty(['buty damskie' => 50]);
		$this->market->sync($context, MarketSyncService::TRIGGER_CLI);
		$this->google->json(self::VOLUME_GET . $taskId, 200, DataForSeoFakes::volumeResult($taskId, [DataForSeoFakes::volumeItem('buty damskie', 1000)]));
		$this->clock->advance(301);
		self::assertSame(1, $this->market->runBackground(20.0, true)['collected']['completed']);

		// Po TTL i terminie automatyki — odświeżenie w tle w ramach limitów.
		$this->clock->advance(31 * 86400);
		$this->mockVolumePost(DataForSeoFakes::taskId());
		$this->mockDifficulty(['buty damskie' => 52]);
		$report = $this->market->runBackground(20.0, true);
		self::assertSame(MarketSyncResult::SUCCESS, $report['projects'][$context->publicId()]['status']);
		self::assertSame('auto', self::db()->fetchValue('SELECT trigger_type FROM `' . self::db()->table('market_tasks') . '` ORDER BY id DESC LIMIT 1'));

		// Wyłączona automatyka (stała) — tło tylko odbiera wyniki.
		putenv(MarketDataConfig::AUTO_REFRESH . '=0');
		$this->clock->advance(31 * 86400);
		$before = count($this->dataForSeoRequests());
		$this->market->runBackground(20.0, true);
		self::assertSame([], array_filter(array_slice($this->dataForSeoRequests(), $before), static fn (array $r): bool => $r['method'] === 'POST'));
	}

	public function test_background_step_never_throws_when_dataforseo_is_down(): void
	{
		$context = $this->projectWithKeywords();
		$this->systemContext($context);
		$this->mockVolumePost($taskId = DataForSeoFakes::taskId());
		$this->mockDifficulty([]);
		$this->market->sync($context, MarketSyncService::TRIGGER_CLI);
		$this->clock->advance(301);
		$this->google->on(self::VOLUME_GET . $taskId, new \WP_Error('http_request_failed', 'Connection timed out'));
		$this->google->on(self::VOLUME_GET . $taskId, new \WP_Error('http_request_failed', 'Connection timed out'));
		$this->google->on(self::VOLUME_GET . $taskId, new \WP_Error('http_request_failed', 'Connection timed out'));

		$report = $this->market->runBackground(20.0, true);

		self::assertSame(1, $report['collected']['pending'], 'Błąd sieci — zadanie czeka na kolejną próbę.');
		self::assertSame('network', $this->states->get($context->projectId(), 'dataforseo')['last_error']);
	}

	public function test_credentials_never_reach_logs_errors_or_database(): void
	{
		$context = $this->projectWithKeywords();
		$this->google->on(self::VOLUME_POST, ['status' => 401, 'json' => ['status_code' => 40100, 'status_message' => 'You are not authorized to access this resource.']]);
		$result = $this->market->sync($context, MarketSyncService::TRIGGER_CLI);
		$this->google->on(self::VOLUME_POST, ['status' => 503, 'json' => ['status_code' => 50000, 'status_message' => 'Internal Error.']]);
		$this->market->sync($context, MarketSyncService::TRIGGER_CLI);

		$basic = base64_encode($this->login . ':' . $this->password);
		$haystack = implode("\n", [...$this->logLines, (string) json_encode($result->toArray()), (string) json_encode($this->market->status($context))]);

		foreach ([$this->password, $this->login, $basic] as $secret) {
			self::assertStringNotContainsString($secret, $haystack);
			self::assertSame([], self::databaseOccurrences($secret), 'Dane logowania nie trafiają do bazy.');
		}

		self::assertNotEmpty($this->logLines);
	}

	private function insertSpentTask(string $createdAt, float $cost): void
	{
		$db = self::db();
		$db->insert($db->table('market_tasks'), [
			'provider' => 'dataforseo',
			'endpoint' => 'google_ads_search_volume',
			'mode' => 'standard',
			'trigger_type' => 'cli',
			'project_id' => null,
			'location_code' => 2616,
			'language_code' => 'pl',
			'status' => 'completed',
			'keywords_count' => 1000,
			'estimated_cost' => $cost,
			'cost' => $cost,
			'created_at' => $createdAt,
			'updated_at' => $createdAt,
		]);
	}

	private function repositoryAssign(\OsfSeo\Auth\ProjectContext $context, int $userId): void
	{
		$this->projects->assign($context->projectId(), $userId, \OsfSeo\Projects\ProjectRole::Viewer);
	}

	/**
	 * @return array{0: string, 1: string, 2: string, 3: string, 4: ?int, 5: ?string}
	 */
	private static function mysqliArgs(): array
	{
		$host = DB_HOST;
		$port = null;
		$socket = null;

		if (str_contains($host, ':')) {
			[$host, $suffix] = explode(':', $host, 2);
			is_numeric($suffix) ? $port = (int) $suffix : $socket = $suffix;
		}

		return [$host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket];
	}
}
