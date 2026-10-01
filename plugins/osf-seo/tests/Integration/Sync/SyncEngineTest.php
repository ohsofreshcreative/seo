<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Sync;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Google\ConnectionStatus;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Gsc\GscNotReady;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Projects\ProjectStatus;
use OsfSeo\Sync\RunStatus;
use OsfSeo\Sync\SyncRequestResult;
use OsfSeo\Sync\SyncRun;
use OsfSeo\Sync\TriggerType;

final class SyncEngineTest extends SyncTestCase
{
	public function test_selecting_property_starts_initial_sync_and_backfill_runs_newest_to_oldest(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();

		$queued = $this->allRuns($context);
		self::assertCount(1, $queued, 'Po wyborze property: najpierw sumy witryny (cała historia jednym zadaniem).');
		self::assertSame([Dataset::Site, TriggerType::Connect, '2024-09-15..2026-01-14'], [$queued[0]->dataset, $queued[0]->trigger, (string) $queued[0]->range]);

		$report = $this->drain();

		self::assertSame(0, $report->outcomes['failed'] + $report->outcomes['retrying']);
		$runs = $this->allRuns($context);
		self::assertSame([], array_filter($runs, static fn (SyncRun $run): bool => $run->status !== RunStatus::Success));

		// Pierwsze okno fraz to najnowsze dni, potem backfill coraz starszych okien.
		$queryRuns = array_values(array_filter($runs, static fn (SyncRun $run): bool => $run->dataset === Dataset::Query));
		self::assertSame('2026-01-06..2026-01-12', (string) $queryRuns[0]->range);
		$starts = array_map(static fn (SyncRun $run): string => $run->range->start, $queryRuns);
		$sorted = $starts;
		rsort($sorted);
		self::assertSame($sorted, $starts, 'Backfill od najnowszych do najstarszych.');
		self::assertSame(self::EARLIEST, end($starts));

		$status = $this->sync->status($context);
		self::assertSame('success', $status->overall);
		self::assertSame(self::LATEST, $status->latestDataDate);
		self::assertSame(100, $status->backfillProgress);
		self::assertSame([true, true, true], array_column($status->datasets, 'backfill_complete'));

		$days = \OsfSeo\Support\DateRange::diffDays(self::EARLIEST, self::LATEST) + 1;
		self::assertSame($days, self::rowCount('gsc_site_daily', $context->projectId()));
		self::assertSame($days * 2, self::rowCount('gsc_query_daily', $context->projectId()));
		self::assertSame($days * 2, self::rowCount('gsc_query_page_daily', $context->projectId()));
		self::assertNotNull($this->projects->reload($context->project())->lastSyncedAt);

		// Kolejne uruchomienie: nic do zrobienia, bez duplikatów.
		self::assertSame([], $this->planner->plan($context));
		self::assertSame(0, $this->drain()->processed());
	}

	public function test_planning_twice_does_not_duplicate_jobs(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$before = count($this->allRuns($context));

		for ($i = 0; $i < 5; $i++) {
			self::assertSame([], $this->planner->plan($context));
			$this->scheduler->planAll();
		}

		self::assertCount($before, $this->allRuns($context));
	}

	public function test_manual_sync_is_rate_limited_and_never_duplicated(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$this->drain();

		$first = $this->sync->requestSync($context);
		self::assertSame(SyncRequestResult::QUEUED, $first->outcome);
		self::assertGreaterThan(0, $first->jobs);

		$second = $this->sync->requestSync($context);
		self::assertSame(SyncRequestResult::RATE_LIMITED, $second->outcome);
		self::assertSame(300, $second->retryInSeconds);

		$this->clock->advance(301);
		self::assertSame(SyncRequestResult::ALREADY_QUEUED, $this->sync->requestSync($context)->outcome, 'Odświeżanie czeka w kolejce.');
		self::assertSame(1, count(array_filter($this->allRuns($context), static fn (SyncRun $run): bool => $run->trigger === TriggerType::Manual && $run->status === RunStatus::Queued && $run->dataset === Dataset::Site)));

		$this->drain();
		$this->clock->advance(301);
		self::assertSame(SyncRequestResult::QUEUED, $this->sync->requestSync($context)->outcome);
		self::assertSame(SyncRequestResult::ALREADY_QUEUED, $this->sync->requestSync($context, true)->outcome, 'Pominięcie limitu (CLI --force) nadal nie dubluje zadań.');
	}

	public function test_manual_sync_requires_manage_connections_and_ready_project(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$client = $this->createUser('osf_seo_client');
		$this->service->assignUser($context, $client, ProjectRole::Manager);
		$clientContext = $this->guard->authorize($context->publicId(), $client);

		try {
			$this->sync->requestSync($clientContext);
			self::fail('Klient zlecił synchronizację.');
		} catch (AccessDenied) {
		}

		self::assertSame('queued', $this->sync->status($clientContext)->overall, 'Klient widzi stan synchronizacji.');

		$noProperty = $this->connectedProject('other.pl');
		$this->expectExceptionObject(new GscNotReady(GscNotReady::NO_PROPERTY));
		$this->sync->requestSync($noProperty);
	}

	public function test_transient_errors_are_retried_with_backoff_then_failed_and_cooled_down(): void
	{
		$context = $this->readyProject();
		$this->google->always(self::queryUrl(), ['status' => 503, 'json' => ['error' => ['code' => 503, 'status' => 'UNAVAILABLE']]]);

		$delays = [];

		for ($attempt = 1; $attempt <= 5; $attempt++) {
			$report = $this->drain();
			self::assertSame(1, $report->processed(), "Próba {$attempt}");
			$run = $this->allRuns($context)[0];

			if ($attempt < 5) {
				self::assertSame(RunStatus::Retrying, $run->status);
				self::assertSame($attempt + 1, $run->attempt);
				self::assertSame('transient', $run->errorCode);
				$delays[] = strtotime($run->availableAt . ' UTC') - $this->clock->now()->getTimestamp();
				self::assertSame(0, $this->drain()->processed(), 'Przed upływem backoffu zadanie nie startuje.');
				$this->clock->advance(end($delays));
			}
		}

		self::assertSame([60, 300, 1800, 7200], $delays);
		$run = $this->allRuns($context)[0];
		self::assertSame(RunStatus::Failed, $run->status);
		$state = $this->states->get($context->projectId(), Dataset::Site);
		self::assertSame('failed', $state->status);
		self::assertSame(5, $state->consecutiveFailures);
		self::assertNotNull($state->retryAfter);

		self::assertSame([], $this->planner->plan($context), 'Przerwa po wyczerpaniu ponowień.');

		// Ręczne zlecenie zdejmuje przerwę.
		$this->mockGscData();
		self::assertSame(SyncRequestResult::QUEUED, $this->sync->requestSync($context)->outcome);
		$this->drain();
		self::assertSame('success', $this->sync->status($context)->overall);
	}

	public function test_permanent_error_fails_without_retry_loop(): void
	{
		$context = $this->readyProject();
		$this->google->always(self::queryUrl(), ['status' => 403, 'json' => ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'errors' => [['reason' => 'forbidden']]]]]);

		$this->drain();

		$runs = $this->allRuns($context);
		self::assertCount(1, $runs);
		self::assertSame([RunStatus::Failed, 'permission_denied', 1], [$runs[0]->status, $runs[0]->errorCode, $runs[0]->attempt]);
		self::assertSame('failed', $this->sync->status($context)->overall);
		self::assertSame([], $this->planner->plan($context));
		$this->clock->advance(23 * 3600);
		self::assertSame([], $this->planner->plan($context));
		$this->clock->advance(2 * 3600);
		self::assertCount(1, $this->planner->plan($context), 'Po 24 h jedna nowa próba.');
	}

	public function test_retry_after_header_extends_backoff(): void
	{
		$context = $this->readyProject();
		$this->google->always(self::queryUrl(), ['status' => 429, 'headers' => ['retry-after' => '900'], 'json' => ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']]]);

		$this->drain();

		$run = $this->allRuns($context)[0];
		self::assertSame([RunStatus::Retrying, 'rate_limited'], [$run->status, $run->errorCode]);
		self::assertSame(900, strtotime($run->availableAt . ' UTC') - $this->clock->now()->getTimestamp());
	}

	public function test_invalid_grant_marks_connection_and_stops_jobs_of_all_its_projects(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$this->drain(2);

		// Drugi projekt na tym samym połączeniu Google.
		$sibling = $this->service->create(['name' => 'Siostrzany', 'domain' => 'example.pl'], $context->project()->createdBy);
		$this->projects->setConnection($sibling->projectId(), (int) $context->project()->connectionId);
		$this->mockSites([['sc-domain:example.pl', 'siteOwner']]);
		$sibling = $this->properties->select($sibling->withProject($this->projects->reload($sibling->project())), 'sc-domain:example.pl');
		self::assertGreaterThan(0, $this->runs->pendingCount($context->projectId()));
		self::assertGreaterThan(0, $this->runs->pendingCount($sibling->projectId()));

		// Nowy proces (nowy access token) — Google odrzuca refresh token.
		$this->tokens->forget((int) $context->project()->connectionId);
		$this->google->always(self::TOKEN_URL, ['status' => 400, 'json' => ['error' => 'invalid_grant']]);
		$tokenRequests = count($this->google->requestsTo(self::TOKEN_URL));

		$report = $this->runner->run(120.0, 1);

		self::assertSame(1, $report->outcomes['failed']);
		self::assertSame(ConnectionStatus::NeedsReauth, $this->connections->find((int) $context->project()->connectionId)->status);
		self::assertSame(0, $this->runs->pendingCount($context->projectId()), 'Zadania anulowane od razu, bez kolejnych prób.');
		self::assertSame(0, $this->runs->pendingCount($sibling->projectId()), 'Także zadania innych projektów tego połączenia.');
		self::assertCount($tokenRequests + 1, $this->google->requestsTo(self::TOKEN_URL), 'Jedna próba odświeżenia tokenu, bez ponowień.');
		$failed = array_values(array_filter([...$this->allRuns($context), ...$this->allRuns($sibling)], static fn (SyncRun $run): bool => $run->status === RunStatus::Failed));
		self::assertSame(['needs_reauth'], array_values(array_unique(array_map(static fn (SyncRun $run): string => (string) $run->errorCode, $failed))));
		self::assertSame([], $this->planner->plan($context), 'Bez ponownej autoryzacji nic nie jest planowane.');
		self::assertSame('needs_reauth', $this->sync->status($context)->overall);
	}

	public function test_refresh_cycle_continues_window_by_window_until_latest_date(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$this->drain();

		// Bardzo gęste dane query_page (30 000 wierszy/dzień) → okno 1 dnia: cykl odświeżania = kilka zadań.
		self::db()->insert(self::db()->table('sync_runs'), [
			'project_id' => $context->projectId(), 'dataset' => 'query_page', 'trigger_type' => 'backfill', 'window_start' => '2024-01-01',
			'window_end' => '2024-01-01', 'status' => 'success', 'priority' => 50, 'attempt' => 1, 'rows_fetched' => 30000,
			'queued_at' => '2026-01-15 12:00:00', 'available_at' => '2026-01-15 12:00:00', 'property' => 'sc-domain:example.pl',
		]);
		$before = count($this->allRuns($context));
		$this->clock->advance(21 * 3600);
		$this->mockGscData('2026-01-13');
		self::assertNotSame([], $this->planner->plan($context));
		$this->drain();

		$windows = array_values(array_map(
			static fn (SyncRun $run): string => (string) $run->range,
			array_filter(array_slice($this->allRuns($context), $before), static fn (SyncRun $run): bool => $run->dataset === Dataset::QueryPage),
		));

		self::assertSame(['2026-01-07..2026-01-07', '2026-01-08..2026-01-13'], $windows, 'Kursor prowadzi cykl do ostatniej daty (drugie okno już z nowej gęstości).');
		self::assertNull($this->states->get($context->projectId(), Dataset::QueryPage)->refreshCursor);
	}

	public function test_daily_refresh_reimports_rolling_window_not_only_yesterday(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$this->drain();
		$before = count($this->allRuns($context));

		// Następny dzień: Google ma dane do 2026-01-13.
		$this->clock->advance(21 * 3600);
		$this->mockGscData('2026-01-13');
		delete_transient('osf_seo_sync_planned');
		$this->scheduler->tick();
		$this->drain();

		$new = array_slice($this->allRuns($context), $before);
		$windows = array_map(static fn (SyncRun $run): string => $run->dataset->value . ' ' . $run->trigger->value . ' ' . $run->range, $new);

		self::assertSame([
			'site schedule 2026-01-06..2026-01-15',
			'query schedule 2026-01-07..2026-01-13',
			'query_page schedule 2026-01-07..2026-01-13',
		], $windows, 'Okno kroczące 7 dni (szerokość okna z gęstości danych), historia nietknięta.');
		self::assertSame('2026-01-13', $this->states->get($context->projectId(), Dataset::Query)->newestDate);
		self::assertNull($this->states->get($context->projectId(), Dataset::Query)->refreshCursor);
		self::assertSame([], $this->planner->plan($context), 'Cykl odświeżania zakończony.');
	}

	public function test_interrupted_job_is_recovered_by_the_next_runner(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$run = $this->runs->claimNext(900);
		self::assertNotNull($run, 'Zadanie „w toku” — proces zginął.');

		$report = $this->drain(0);

		self::assertSame(1, $report->recovered);
		$recovered = $this->runs->find($run->id);
		self::assertSame([RunStatus::Retrying, 2, 'interrupted'], [$recovered->status, $recovered->attempt, $recovered->errorCode]);
	}

	public function test_only_one_runner_at_a_time(): void
	{
		$this->mockGscData();
		$this->readyProject();
		$db = self::db();
		$other = new \mysqli(...self::connectionArgs());
		$lock = $db->lockName('sync_runner');
		$other->query(sprintf("SELECT GET_LOCK('%s', 0)", $other->real_escape_string($lock)));

		try {
			$report = $this->drain();
			self::assertTrue($report->locked);
			self::assertSame(0, $report->processed());
		} finally {
			$other->query(sprintf("SELECT RELEASE_LOCK('%s')", $other->real_escape_string($lock)));
			$other->close();
		}

		self::assertFalse($this->drain()->locked);
	}

	public function test_property_reset_cancels_pending_jobs_and_old_jobs_cannot_write(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$this->drain(3);
		$pending = $this->runs->pendingCount($context->projectId());
		self::assertGreaterThan(0, $pending);

		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);
		$this->properties->select($context, 'https://www.example.pl/', true);

		$cancelled = array_filter($this->allRuns($context), static fn (SyncRun $run): bool => $run->status === RunStatus::Cancelled);
		self::assertCount($pending, $cancelled);
		self::assertSame(['property_reset'], array_values(array_unique(array_map(static fn (SyncRun $run): string => (string) $run->errorCode, $cancelled))));

		$newRuns = array_filter($this->allRuns($context), static fn (SyncRun $run): bool => $run->status === RunStatus::Queued);
		self::assertSame(['https://www.example.pl/'], array_values(array_unique(array_map(static fn (SyncRun $run): string => (string) $run->property, $newRuns))));
	}

	public function test_paused_project_jobs_are_skipped_and_not_planned(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$this->service->changeStatus($context, ProjectStatus::Paused);

		$report = $this->drain();

		self::assertSame(1, $report->outcomes['skipped']);
		self::assertSame([], $this->planner->plan($context));
	}

	public function test_queue_is_not_runnable_from_web_requests(): void
	{
		$context = $this->readyProject();
		remove_all_filters('wp_doing_cron');

		$this->expectException(AccessDenied::class);
		$this->sync->runNow($context, 5.0, 1);
	}

	public function test_maintenance_purges_old_runs_and_orphaned_staging(): void
	{
		$this->mockGscData();
		$context = $this->readyProject();
		$this->drain();
		$db = self::db();
		$db->execute("UPDATE `{$db->table('sync_runs')}` SET queued_at = '2025-01-01 00:00:00' WHERE project_id = %d", [$context->projectId()]);
		$db->insert($db->table('gsc_import_staging'), ['run_id' => 999999, 'date' => '2026-01-01', 'keyword_id' => 1, 'page_id' => 0, 'clicks' => 1, 'impressions' => 1, 'position_sum' => 1.0]);
		delete_option('osf_seo_sync_maintenance_at');

		$this->drain();

		self::assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('sync_runs')}` WHERE project_id = %d", [$context->projectId()]));
		self::assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('gsc_import_staging')}`"));
		self::assertNotFalse(get_option(\OsfSeo\Sync\SyncRunner::HEARTBEAT_OPTION), 'Heartbeat kolejki zapisany.');
	}

	/**
	 * @return array{0: string, 1: string, 2: string, 3: string, 4: ?int, 5: ?string}
	 */
	private static function connectionArgs(): array
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
