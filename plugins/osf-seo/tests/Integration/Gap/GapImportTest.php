<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Gap;

use OsfSeo\Gap\GapConfig;
use OsfSeo\Gap\GapPlan;
use OsfSeo\Gap\GapRun;
use OsfSeo\Gap\GapService;
use OsfSeo\Gap\GapStartResult;
use OsfSeo\Gap\PlannedTarget;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Tests\Support\DataForSeoFakes;
use WP_Error;

final class GapImportTest extends GapTestCase
{
	/**
	 * @return list<array<string, mixed>>
	 */
	private static function rows(string $domain, int $count, int $startVolume = 5000, int $rank = 5): array
	{
		$rows = [];

		for ($i = 0; $i < $count; $i++) {
			$rows[] = self::ranked($domain, sprintf('fraza %s %04d', explode('.', $domain)[0], $i), max(10, $startVolume - $i), $rank);
		}

		return $rows;
	}

	public function test_plan_makes_no_request_and_shows_competitor_baseline_and_maximum_cost(): void
	{
		$context = $this->gapProject();

		$plan = $this->gaps->plan($context, $this->gaps->request($context, []));

		self::assertNull($plan->skipReason);
		self::assertSame([['konkurent.pl', 'competitor', 30, 10, 10000], ['example.pl', 'project', 100, 10, 10000]], array_map(
			static fn (PlannedTarget $target): array => [$target->domain, $target->role, $target->coverage->maxRank, $target->coverage->minVolume, $target->coverage->maxRows],
			$plan->targets,
		), 'Domyślnie: TOP30, wolumen ≥ 10, 10 000 fraz; punkt odniesienia projektu TOP100.');
		self::assertSame(20, $plan->requests());
		self::assertEqualsWithDelta(2.64, $plan->estimatedCost(), 1e-6, '2 × (10 × 0,012 + 10 000 × 0,00012).');
		self::assertSame(1.0, $plan->dailyLimit, 'Globalne limity bez zmian.');
		self::assertTrue($plan->spansDays());
		self::assertSame('monthly_limit', (new GapPlan($plan->market, null, $plan->request, $plan->targets, [], 0.012, 0.00012, 1.0, 0.0, 2.0, 0.0))->blockedBy());
		self::assertSame(0, $plan->toArray()['api_requests']);
		self::assertSame([], $this->dataForSeoRequests(), 'Plan nie wysyła żadnego żądania.');
		self::assertSame(0, self::tableCount('gap_domains'), 'Plan niczego nie zapisuje.');
	}

	public function test_import_stores_dataset_metrics_urls_titles_and_cost_registry(): void
	{
		$context = $this->gapProject();
		$core = self::ranked('konkurent.pl', 'Projektowanie Stron Internetowych', 720, 4, '/strony-www/', 31, 'commercial', 4.5, 'projektowanie stron', false, 'Strony WWW — Konkurent');
		$this->setRanked('konkurent.pl', [$core, self::ranked('konkurent.pl', 'sklep internetowy', 2400, 12), self::ranked('konkurent.pl', 'website design', 90, 7, null, 20, 'informational', 1.0, null, true)]);
		$this->setRanked('example.pl', [self::ranked('example.pl', 'sklep internetowy', 2400, 25, '/sklepy/')]);

		$run = $this->gapRun($context);

		self::assertSame(GapRun::COMPLETED, $run->status);
		self::assertSame(2, $run->requestsDone);
		self::assertEqualsWithDelta(round(0.012 + 3 * 0.00012, 6) + round(0.012 + 0.00012, 6), $run->cost, 1e-6, 'Koszt zgłoszony przez API.');
		$bodies = $this->rankedBodies();
		self::assertSame(['konkurent.pl', 'example.pl'], array_column($bodies, 'target'));
		self::assertSame(['organic'], $bodies[0]['item_types']);
		self::assertSame([['ranked_serp_element.serp_item.rank_group', '<=', 30], 'and', ['keyword_data.keyword_info.search_volume', '>=', 10]], $bodies[0]['filters']);
		self::assertSame(100, $bodies[1]['filters'][0][2], 'Punkt odniesienia projektu: TOP100.');

		$dataset = $this->gapDomains->find($this->gaps->market($context), 'konkurent.pl');
		self::assertSame('ready', $dataset->status);
		self::assertTrue($dataset->complete);
		self::assertSame(10, $dataset->coveredMinVolume);
		self::assertSame(3, $dataset->rowsPresent);
		self::assertSame('2026-09-20 00:00:00', $dataset->labsUpdatedAt);
		self::assertSame('2026-02-14 12:00:00', $dataset->staleAfter, 'Świeży przez 30 dni.');

		$row = $this->datasetRow('konkurent.pl', 'projektowanie stron internetowych');
		self::assertSame('4', $row['rank_group']);
		self::assertSame('https://konkurent.pl/strony-www/', $row['url']);
		self::assertSame('2026-09-20', $row['serp_on']);
		$market = $this->marketRow('projektowanie stron internetowych');
		self::assertSame(['720', '31', 'commercial', md5('projektowanie stron')], [$market['search_volume'], $market['keyword_difficulty'], $market['search_intent'], bin2hex((string) $market['core_key'])]);
		self::assertSame('1', $this->marketRow('website design')['other_language']);
		$db = self::db();
		self::assertSame('Strony WWW — Konkurent', $db->fetchValue("SELECT title FROM `{$db->table('gap_domain_pages')}` p JOIN `{$db->table('serp_urls')}` u ON u.id = p.url_id WHERE u.url = %s", ['https://konkurent.pl/strony-www/']));

		$tasks = $db->fetchAll("SELECT endpoint, mode, trigger_type, status, project_id, cost FROM `{$db->table('market_tasks')}` ORDER BY id");
		self::assertSame([['labs_ranked_keywords', 'live', 'gap', 'completed', (string) $context->projectId()], ['labs_ranked_keywords', 'live', 'gap', 'completed', (string) $context->projectId()]], array_map(static fn (array $task): array => [$task['endpoint'], $task['mode'], $task['trigger_type'], $task['status'], $task['project_id']], $tasks));
		self::assertEqualsWithDelta($run->cost, $this->tasks->usageByPurpose('2026-01-01 00:00:00')['gap']['cost'], 1e-6, 'Dane rynkowe: osobna kategoria „Luki SEO”.');
		self::assertSame(0, self::tableCount('gap_domain_events'), 'Pierwszy import to stan wyjściowy — bez zdarzeń.');
	}

	public function test_pagination_follows_offsets_until_the_last_page(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', self::rows('konkurent.pl', 2500));

		$run = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame(GapRun::COMPLETED, $run->status);
		self::assertSame([0, 1000, 2000], array_column($this->rankedBodies(), 'offset'));
		self::assertSame([1000, 1000, 1000], array_column($this->rankedBodies(), 'limit'));
		$dataset = $this->gapDomains->find($this->gaps->market($context), 'konkurent.pl');
		self::assertTrue($dataset->complete);
		self::assertSame(2500, $dataset->rowsPresent);
		self::assertSame(2500, $dataset->totalCount);
	}

	public function test_row_limit_truncates_by_volume_and_limits_reliable_absence(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', self::rows('konkurent.pl', 1500, 3000));

		$run = $this->gapRun($context, ['baseline' => '0', 'max_rows' => '1000']);

		self::assertSame(1, $run->requestsDone);
		$dataset = $this->gapDomains->find($this->gaps->market($context), 'konkurent.pl');
		self::assertFalse($dataset->complete);
		self::assertSame(1000, $dataset->rowsPresent);
		self::assertSame(1500, $dataset->totalCount);
		self::assertSame(2002, $dataset->coveredMinVolume, 'Nieobecność wiarygodna tylko powyżej wolumenu ostatniej pobranej frazy (2001).');
	}

	public function test_shared_fresh_dataset_is_reused_free_by_another_project_and_force_reimports(): void
	{
		$first = $this->gapProject();
		$this->setRanked('konkurent.pl', self::rows('konkurent.pl', 10));
		$this->gapRun($first, ['baseline' => '0']);
		$requests = count($this->rankedBodies());

		$second = $this->gapProject(['konkurent.pl' => 'Ten sam konkurent'], 'drugi-projekt.pl');
		$plan = $this->gaps->plan($second, $this->gaps->request($second, ['baseline' => '0']));

		self::assertSame(PlannedTarget::CACHED, $plan->targets[0]->state);
		self::assertSame(0, $plan->requests());
		self::assertSame(GapStartResult::NOTHING_TO_DO, $this->gaps->start($second, $plan->request, GapService::TRIGGER_CLI)->status);
		self::assertCount($requests, $this->rankedBodies(), 'Zbiór z pamięci — bez opłaty.');
		self::assertNotNull($this->gap($second, 'fraza konkurent 0000'), 'Luki drugiego projektu przeliczone ze wspólnego zbioru.');

		$narrow = $this->gaps->plan($second, $this->gaps->request($second, ['baseline' => '0', 'preset' => 'quick']));
		self::assertSame(PlannedTarget::CACHED, $narrow->targets[0]->state, 'Węższy zakres jest objęty świeżym zbiorem.');
		$wider = $this->gaps->plan($second, $this->gaps->request($second, ['baseline' => '0', 'max_rank' => '50']));
		self::assertSame(PlannedTarget::IMPORT, $wider->targets[0]->state);
		self::assertNull($wider->targets[0]->knownTotal, 'Inne filtry — liczba fraz nieznana, plan zakłada maksimum.');
		self::assertSame(10, $wider->targets[0]->expectedRequests);
		$same = $this->gaps->plan($second, $this->gaps->request($second, ['baseline' => '0', 'force' => '1']));
		self::assertSame(10, $same->targets[0]->knownTotal);
		self::assertSame(1, $same->targets[0]->expectedRequests, 'Te same filtry — oczekiwana liczba stron z ostatniego importu.');

		$forced = $this->gapRun($second, ['baseline' => '0', 'force' => '1']);
		self::assertSame(GapRun::COMPLETED, $forced->status);
		self::assertCount($requests + 1, $this->rankedBodies());
	}

	public function test_daily_budget_pauses_import_keeps_pages_and_resumes_next_day(): void
	{
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=0.2');
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', self::rows('konkurent.pl', 2500));

		$run = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame(GapRun::PAUSED, $run->status);
		self::assertSame('daily_limit', $run->blockedBy);
		self::assertSame(1, $run->requestsDone, 'Druga strona przekroczyłaby dzienny limit 0,20 USD.');
		self::assertSame(1000, self::tableCount('gap_domain_keywords'), 'Pobrane strony zostają.');

		add_filter('wp_doing_cron', '__return_true');
		$this->gaps->runBackground(60.0);
		self::assertSame(1, $this->gapRuns->findById($run->id)->requestsDone, 'Tego samego dnia bez kolejnych żądań.');

		$this->clock->advance(86400);
		$this->gaps->runBackground(60.0);
		$run = $this->gapRuns->findById($run->id);
		self::assertSame(GapRun::PAUSED, $run->status, 'Następnego dnia jedna strona (limit 0,20 USD) i znów pauza.');
		self::assertSame(2, $run->requestsDone);

		$this->clock->advance(86400);
		$this->gaps->runBackground(60.0);
		$run = $this->gapRuns->findById($run->id);
		self::assertSame(GapRun::COMPLETED, $run->status, 'Wznowione automatycznie, gdy limit się odnowił.');
		self::assertSame([0, 1000, 2000], array_column($this->rankedBodies(), 'offset'));
		self::assertTrue($this->gapDomains->find($this->gaps->market($context), 'konkurent.pl')->complete);
	}

	public function test_schedule_is_off_by_default_and_refreshes_stale_datasets_within_budget(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', self::rows('konkurent.pl', 5));
		$this->setRanked('example.pl', [self::ranked('example.pl', 'fraza example', 100, 3)]);
		add_filter('wp_doing_cron', '__return_true');

		$this->gaps->runBackground(60.0);
		self::assertSame([], $this->rankedBodies(), 'Harmonogram domyślnie wyłączony — wdrożenie niczego nie pobiera.');

		$this->gapRun($context);
		$requests = count($this->rankedBodies());
		$this->gaps->setSchedule($context, true, true);
		self::assertSame('fresh', $this->gaps->runBackground(60.0)['scheduled'][$context->projectId()], 'Świeże zbiory — bez żądań.');
		self::assertCount($requests, $this->rankedBodies());

		$this->clock->advance(31 * 86400);
		self::assertSame(GapStartResult::QUEUED, $this->gaps->runBackground(60.0)['scheduled'][$context->projectId()]);
		$this->gaps->runBackground(60.0);
		$run = $this->gapRuns->recent($context->projectId(), 1)[0];
		self::assertSame([GapRun::COMPLETED, GapService::TRIGGER_SCHEDULE], [$run->status, $run->trigger]);
		self::assertCount($requests + 2, $this->rankedBodies(), 'Odświeżenie obu zbiorów (oczekiwany koszt z poprzedniego importu).');

		$this->clock->advance(31 * 86400);
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=0.001');
		self::assertSame('over_budget', $this->gaps->runBackground(60.0)['scheduled'][$context->projectId()]);
		self::assertSame('daily_limit', $this->gapSettings->get($context->projectId())->lastSkipReason);
		self::assertCount($requests + 2, $this->rankedBodies(), 'Oczekiwany koszt ponad limit — odświeżenie pominięte z powodem.');

		putenv(MarketDataConfig::DAILY_COST_LIMIT);
		self::db()->update(self::db()->table('projects'), ['status' => 'paused'], ['id' => $context->projectId()]);
		$this->clock->advance(86400);
		self::assertSame('skipped', $this->gaps->runBackground(60.0)['scheduled'][$context->projectId()]);
		self::assertSame('project_inactive', $this->gapSettings->get($context->projectId())->lastSkipReason, 'Projekt wstrzymany — bez płatnych odświeżeń.');
		self::assertCount($requests + 2, $this->rankedBodies());
	}

	public function test_monthly_budget_blocks_start_and_nothing_is_queued(): void
	{
		putenv(MarketDataConfig::MONTHLY_COST_LIMIT . '=1');
		$context = $this->gapProject();

		$result = $this->gaps->start($context, $this->gaps->request($context, []), GapService::TRIGGER_CLI);

		self::assertSame(GapStartResult::OVER_BUDGET, $result->status);
		self::assertSame('monthly_limit', $result->reason);
		self::assertSame(0, self::tableCount('gap_runs'));
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_uncertain_failure_is_never_retried_and_keeps_fetched_pages(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', self::rows('konkurent.pl', 2500));
		$first = array_slice($this->ranked['konkurent.pl'], 0, 1000);
		$this->rankedOverrides['konkurent.pl'] = [
			['status' => 200, 'json' => DataForSeoFakes::rankedResult('konkurent.pl', $first, 2500, 0.132)],
			new WP_Error('http_request_failed', 'Operation timed out'),
		];

		$run = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame(GapRun::PARTIAL, $run->status);
		self::assertCount(2, $this->rankedBodies(), 'Strona po timeoucie nie jest ponawiana (mogła zostać opłacona).');
		self::assertEqualsWithDelta(0.132 + 0.132, $run->cost, 1e-6, 'Niepewne żądanie liczy się kosztem szacowanym.');
		$dataset = $this->gapDomains->find($this->gaps->market($context), 'konkurent.pl');
		self::assertSame('partial', $dataset->status);
		self::assertFalse($dataset->complete);
		self::assertSame(1000, $dataset->rowsPresent);
		self::assertSame(4002, $dataset->coveredMinVolume, 'Pierwszy niepełny import: wiarygodny tylko zakres powyżej ostatniej pobranej frazy (4001).');
		self::assertSame('partial', self::db()->fetchValue("SELECT status FROM `" . self::db()->table('gap_run_targets') . "` WHERE run_id = %d", [$run->id]));
		self::assertSame('failed', self::db()->fetchValue("SELECT status FROM `" . self::db()->table('market_tasks') . "` ORDER BY id DESC LIMIT 1"));
	}

	public function test_rate_limit_is_retried_later_and_account_error_pauses_shared_paid_calls(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', self::rows('konkurent.pl', 5));
		$this->rankedOverrides['konkurent.pl'] = [['status' => 200, 'json' => DataForSeoFakes::envelope(['status_code' => 40202, 'status_message' => 'Rate limit.'], 20000)]];

		$run = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame(GapRun::RUNNING, $run->status, 'Limit żądań: strona wraca do kolejki.');
		self::assertSame('0.000000', self::db()->fetchValue("SELECT cost FROM `" . self::db()->table('gap_run_targets') . "` WHERE run_id = %d", [$run->id]));
		$this->clock->advance(GapConfig::RATE_LIMIT_RETRY + 1);
		$this->gaps->execute($context, $run);
		self::assertSame(GapRun::COMPLETED, $this->gapRuns->findById($run->id)->status);

		$other = $this->gapProject(['inny.pl' => 'Inny'], 'trzeci.pl');
		$this->rankedOverrides['inny.pl'] = [['status' => 200, 'json' => DataForSeoFakes::envelope(['status_code' => 40200, 'status_message' => 'Payment Required.'], 20000)]];
		$paused = $this->gapRun($other, ['baseline' => '0']);

		self::assertSame(GapRun::PAUSED, $paused->status);
		self::assertNotNull($this->market->paused(), 'Pauza wspólna z danymi rynkowymi.');
		self::assertSame(GapStartResult::PAUSED, $this->gaps->start($this->gapProject(['jeszcze.pl' => 'X'], 'czwarty.pl'), $this->gaps->request($other, ['baseline' => '0']), GapService::TRIGGER_CLI)->status);
	}

	public function test_cancel_stops_further_pages_and_closes_dataset_import(): void
	{
		putenv(GapConfig::MAX_REQUESTS_PER_TICK . '=1');
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', self::rows('konkurent.pl', 2500));
		$result = $this->gaps->start($context, $this->gaps->request($context, ['baseline' => '0']), GapService::TRIGGER_CLI);
		add_filter('wp_doing_cron', '__return_true');
		$this->gaps->runBackground(60.0);

		self::assertCount(1, $this->rankedBodies());
		self::assertTrue($this->gaps->cancel($context, $result->run->publicId));
		$this->gaps->runBackground(60.0);
		$this->gaps->runBackground(60.0);

		self::assertCount(1, $this->rankedBodies(), 'Po anulowaniu żadnych kolejnych żądań.');
		self::assertSame(GapRun::CANCELLED, $this->gapRuns->findById($result->run->id)->status);
		self::assertSame('partial', $this->gapDomains->find($this->gaps->market($context), 'konkurent.pl')->status);
		self::assertSame(1000, self::tableCount('gap_domain_keywords'));
	}

	public function test_interrupted_request_is_closed_without_retry(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', self::rows('konkurent.pl', 5));
		$result = $this->gaps->start($context, $this->gaps->request($context, ['baseline' => '0']), GapService::TRIGGER_CLI);
		$db = self::db();
		// Proces padł w trakcie żądania: wiersz kosztu i znacznik żądania w locie, brak odpowiedzi.
		$taskId = $this->tasks->create('dataforseo', $this->rankedProvider->endpoint(), 'gap', $context->projectId(), $this->gaps->market($context), ['konkurent.pl'], 0.012);
		$db->execute("UPDATE `{$db->table('gap_run_targets')}` SET status = 'running', inflight_task_id = %d WHERE run_id = %d", [$taskId, $result->run->id]);
		$this->clock->advance(2 * 3600);
		add_filter('wp_doing_cron', '__return_true');

		$report = $this->gaps->runBackground(60.0);

		self::assertSame(1, $report['maintenance']['interrupted']);
		self::assertSame([], $this->rankedBodies(), 'Bez automatycznego ponowienia.');
		self::assertSame(GapRun::FAILED, $this->gapRuns->findById($result->run->id)->status);
		self::assertEqualsWithDelta(0.012, $this->gapRuns->findById($result->run->id)->cost, 1e-6);
	}

	public function test_one_active_run_per_project_manual_cooldown_and_only_configured_competitors(): void
	{
		$context = $this->gapProject(['konkurent.pl' => 'Aktywny', 'nieaktywny.pl' => 'Nieaktywny', 'example.pl' => 'Własna domena']);
		$inactive = $this->competitor($context, 'nieaktywny.pl');
		$this->competitorRepository->update($inactive, $inactive->name, $inactive->domain, 'inactive', null);

		$plan = $this->gaps->plan($context, $this->gaps->request($context, []));
		self::assertSame(['konkurent.pl', 'example.pl'], array_map(static fn (PlannedTarget $target): string => $target->domain, $plan->targets));
		self::assertSame([['domain' => 'example.pl', 'label' => 'Własna domena', 'reason' => 'project_domain']], $plan->skipped);

		self::assertSame(GapStartResult::QUEUED, $this->gaps->start($context, $plan->request, GapService::TRIGGER_MANUAL)->status);
		self::assertSame(GapStartResult::ALREADY_RUNNING, $this->gaps->start($context, $plan->request, GapService::TRIGGER_MANUAL)->status);
		self::assertSame([], $this->dataForSeoRequests(), 'Uruchomienie z panelu tylko kolejkuje.');

		$this->gaps->cancel($context, $this->gaps->activeRun($context)->publicId);
		self::assertSame(GapStartResult::RATE_LIMITED, $this->gaps->start($context, $plan->request, GapService::TRIGGER_MANUAL)->status);
		self::assertSame(GapStartResult::PLAN_CHANGED, $this->gaps->start($context, $plan->request, GapService::TRIGGER_CLI, 1, 0.01)->status);

		$this->competitorRepository->update($this->competitor($context, 'konkurent.pl'), 'Aktywny', 'konkurent.pl', 'inactive', null);
		self::assertSame(GapStartResult::NO_COMPETITORS, $this->gaps->start($context, $plan->request, GapService::TRIGGER_CLI)->status);
	}

	public function test_background_never_runs_outside_system_process_and_reads_make_no_requests(): void
	{
		$context = $this->gapProject();
		$this->gaps->start($context, $this->gaps->request($context, []), GapService::TRIGGER_CLI);

		self::assertSame(['maintenance' => null, 'processed' => null, 'scheduled' => [], 'refreshed' => []], $this->gaps->runBackground(60.0));
		$this->gaps->keywords($context, \OsfSeo\Gap\GapFilters::fromInput([]));
		$this->gaps->counts($context);
		$this->gaps->datasets($context);
		self::assertSame([], $this->dataForSeoRequests());
		self::assertSame(MarketSyncService::LOCK, 'market_sync');
	}
}
