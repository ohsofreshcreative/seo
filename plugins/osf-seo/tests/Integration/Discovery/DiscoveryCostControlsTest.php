<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Discovery;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Roles;
use OsfSeo\Discovery\DiscoveryConfig;
use OsfSeo\Discovery\DiscoveryRun;
use OsfSeo\Discovery\DiscoveryService;
use OsfSeo\Discovery\DiscoveryStartResult;
use OsfSeo\Market\CostBudget;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Bezpieczniki kosztów wyszukiwania: wspólne limity z danymi rynkowymi, potwierdzenie planu, jeden przebieg naraz,
 * cache seedów (TTL) i wymuszone odświeżenie, awarie dostawcy i wyniki częściowe.
 */
final class DiscoveryCostControlsTest extends DiscoveryTestCase
{
	public function test_daily_and_monthly_budgets_are_shared_with_market_data(): void
	{
		$context = $this->projectWithKeywords();
		// Wzbogacanie fraz (STEP 12) zużyło już 0,99 USD dzisiaj.
		$this->spend(0.99);

		$plan = $this->discovery->plan($context, $this->request('strony internetowe'));
		self::assertSame(CostBudget::DAILY_LIMIT, $plan->blockedBy());
		self::assertSame(0.01, $plan->remainingToday());

		$result = $this->discovery->start($context, $this->request('strony internetowe'), DiscoveryService::TRIGGER_MANUAL);
		self::assertSame([DiscoveryStartResult::OVER_BUDGET, CostBudget::DAILY_LIMIT], [$result->status, $result->reason]);
		self::assertSame(0, self::tableCount('discovery_runs'), 'Plan ponad limit nie jest kolejkowany.');

		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=50');
		putenv(MarketDataConfig::MONTHLY_COST_LIMIT . '=1');
		self::assertSame(CostBudget::MONTHLY_LIMIT, $this->discovery->plan($context, $this->request('strony internetowe'))->blockedBy());
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_budget_is_checked_before_every_request_and_a_blocked_run_waits(): void
	{
		$context = $this->projectWithKeywords();
		$result = $this->discovery->start($context, $this->request("seo\nsem"), DiscoveryService::TRIGGER_MANUAL);
		self::assertTrue($result->isQueued());
		// Inne płatne żądania (np. automatyczne odświeżenie danych rynkowych) zużyły limit po zakolejkowaniu przebiegu.
		$this->spend(0.995);

		$report = $this->discovery->execute($context, $result->run);

		self::assertSame([0, CostBudget::DAILY_LIMIT], [$report['requests'], $report['blocked_by']]);
		self::assertSame([], $this->dataForSeoRequests(), 'Żądanie, które przekroczyłoby limit, nie zostaje wysłane.');
		$run = $this->runs->findById($result->run->id);
		self::assertSame([DiscoveryRun::QUEUED, CostBudget::DAILY_LIMIT], [$run->status, $run->blockedBy], 'Przebieg czeka i wznowi się w ramach limitu.');

		$this->clock->advance(86400);
		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('pozycjonowanie stron', 900), 1]]);
		$this->mockRelated('sem', []);
		$this->discovery->execute($context, $run);
		self::assertSame(DiscoveryRun::COMPLETED, $this->runs->findById($run->id)->status);
	}

	public function test_confirmation_single_active_run_and_manual_cooldown_prevent_duplicate_paid_work(): void
	{
		$context = $this->projectWithKeywords();
		$plan = $this->discovery->plan($context, $this->request("seo\nsem"));

		$changed = $this->discovery->start($context, $this->request("seo\nsem\nsxo"), DiscoveryService::TRIGGER_MANUAL, $plan->requests(), $plan->estimatedCost());
		self::assertSame(DiscoveryStartResult::PLAN_CHANGED, $changed->status, 'Większy plan niż potwierdzony podgląd nie jest kolejkowany.');

		$first = $this->discovery->start($context, $this->request("seo\nsem"), DiscoveryService::TRIGGER_MANUAL, $plan->requests(), $plan->estimatedCost());
		self::assertSame(DiscoveryStartResult::QUEUED, $first->status);
		$second = $this->discovery->start($context, $this->request('sxo'), DiscoveryService::TRIGGER_MANUAL);
		self::assertSame([DiscoveryStartResult::ALREADY_RUNNING, $first->run?->publicId], [$second->status, $second->run?->publicId], 'Jeden aktywny przebieg na projekt.');

		$this->discovery->cancel($context, $first->run->publicId);
		self::assertSame(DiscoveryRun::CANCELLED, $this->runs->findById($first->run->id)->status);
		self::assertSame(['cancelled', 'cancelled'], array_column($this->runs->seeds($first->run->id), 'status'));
		self::assertSame(DiscoveryStartResult::RATE_LIMITED, $this->discovery->start($context, $this->request('sxo'), DiscoveryService::TRIGGER_MANUAL)->status, 'Ręczne uruchomienie najwyżej raz na minutę.');
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_recently_fetched_seeds_are_cached_for_the_ttl_and_force_refetches_them(): void
	{
		$context = $this->projectWithKeywords();
		$this->mockRelated('strony internetowe', [[DataForSeoFakes::labsKeyword('strony www', 1000, 30), 1]]);
		$this->discover($context, 'strony internetowe');

		$cachedPlan = $this->discovery->plan($context, $this->request("strony internetowe\nwoocommerce"));
		self::assertSame([0, 1, 1], [$cachedPlan->seeds[0]->requests, $cachedPlan->seeds[1]->requests, $cachedPlan->cachedSeeds()]);
		self::assertSame(DiscoveryStartResult::NOTHING_TO_DO, $this->discovery->start($context, $this->request('strony internetowe'), DiscoveryService::TRIGGER_CLI)->status);
		self::assertSame(0, $this->discovery->plan($context, $this->request('strony internetowe', ['max_candidates' => '100']))->requests(), 'Węższe parametry (mniejszy limit) — wynik z cache wystarcza.');
		self::assertSame(1, $this->discovery->plan($context, $this->request('strony internetowe', ['max_candidates' => '500', 'depth' => '3']))->requests(), 'Szersze parametry (większa głębokość) — ponowne pobranie.');
		self::assertSame(1, $this->discovery->plan($context, $this->request('strony internetowe', ['method' => 'suggestions']))->requests(), 'Inna metoda — osobny cache.');
		self::assertSame(1, $this->discovery->plan($context, $this->request('strony internetowe', ['min_volume' => '0']))->requests(), 'Niższy min. wolumen — wynik z cache jest niepełny.');

		$this->resetDiscoveryOptions();
		$this->mockRelated('strony internetowe', [[DataForSeoFakes::labsKeyword('strony www', 1000, 30), 1]]);
		$forced = $this->discover($context, 'strony internetowe', ['force' => '1']);
		self::assertTrue($forced->forced);
		self::assertCount(2, $this->dataForSeoRequests(), 'Wymuszone odświeżenie pobiera seed ponownie.');
		self::assertSame(0, $forced->candidatesNew, 'Te same frazy — bez nowych kandydatów (tylko ostatnie wykrycie).');

		$this->clock->advance(31 * 86400);
		self::assertSame(1, $this->discovery->plan($context, $this->request('strony internetowe'))->requests(), 'Po TTL (30 dni) seed jest pobierany ponownie.');
		putenv(DiscoveryConfig::TTL_DAYS . '=60');
		self::assertSame(0, $this->discovery->plan($context, $this->request('strony internetowe'))->requests());
	}

	public function test_paid_discovery_and_force_require_the_capability_while_client_can_read(): void
	{
		$context = $this->projectWithKeywords();
		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('pozycjonowanie', 900), 1]]);
		$this->discover($context, 'seo');
		self::assertTrue(user_can($this->createUser(Roles::ADMIN), 'osf_seo_manage_keyword_discovery'));
		$client = $this->createUser(Roles::CLIENT);
		self::assertFalse(user_can($client, 'osf_seo_manage_keyword_discovery'));
		$this->projects->assign($context->projectId(), $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);

		foreach ([
			fn () => $this->discovery->plan($clientContext, $this->request('seo')),
			fn () => $this->discovery->start($clientContext, $this->request('seo', ['force' => '1']), DiscoveryService::TRIGGER_MANUAL),
			fn () => $this->discovery->saveExclusions($clientContext, 'praca'),
		] as $call) {
			try {
				$call();
				self::fail('Klient nie może planować ani uruchamiać płatnego wyszukiwania.');
			} catch (AccessDenied) {
			}
		}

		self::assertSame(['pozycjonowanie'], array_values(array_diff($this->listed($clientContext, ['visibility' => 'all']), ['seo'])), 'Klient widzi listę (tylko odczyt).');
		self::assertNull($this->discovery->status($clientContext)['budget'], 'Klient nie widzi kosztów i limitów.');
		self::assertArrayNotHasKey('cost', $this->discovery->progress($this->runs->recent($context->projectId())[0], false));
		self::assertCount(1, $this->dataForSeoRequests());
	}

	public function test_provider_failures_partial_results_and_account_pause(): void
	{
		$context = $this->projectWithKeywords();
		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('pozycjonowanie', 900), 1]], null, 0.0122);
		$this->google->json(self::RELATED, 500, ['status_code' => 50000, 'status_message' => 'Internal Error.']);
		$this->google->json(self::RELATED, 200, DataForSeoFakes::envelope(['result' => ['broken' => true]]));

		$run = $this->discover($context, "seo\nsem\nsxo");

		self::assertSame(DiscoveryRun::PARTIAL, $run->status, 'Jeden seed pobrany, dwa z błędem — wynik częściowy.');
		self::assertSame(['done', 'failed', 'failed'], array_column($this->runs->seeds($run->id), 'status'));
		self::assertSame(['transient', 'malformed_response'], array_values(array_filter(array_column($this->runs->seeds($run->id), 'error_code'))));
		self::assertCount(3, $this->dataForSeoRequests(), 'Płatne żądanie po błędzie 5xx nie jest ponawiane (mogło zostać opłacone).');
		self::assertEqualsWithDelta(0.0122 + 2 * $this->discoveryProvider->estimateCost(72), $run->cost, 1e-6, 'Niepewne błędy liczą się do limitu kosztem szacowanym.');
		self::assertSame(['pozycjonowanie'], $this->sortedListed($context), 'Wyniki udanego seeda zostają.');

		// Błąd konta (brak środków): wstrzymanie płatnych żądań (wspólne z danymi rynkowymi), przebieg czeka.
		$this->resetDiscoveryOptions();
		$this->google->json(self::RELATED, 200, DataForSeoFakes::envelope(['status_code' => 40200, 'status_message' => 'Payment Required.']));
		$paused = $this->discovery->start($context, $this->request('marketing'), DiscoveryService::TRIGGER_CLI);
		$this->discovery->execute($context, $paused->run);
		$waiting = $this->runs->findById($paused->run->id);
		self::assertSame([DiscoveryRun::RUNNING, 'paused'], [$waiting->status, $waiting->blockedBy]);
		self::assertSame('billing', $this->market->paused()['reason']);
		self::assertSame(DiscoveryStartResult::ALREADY_RUNNING, $this->discovery->start($context, $this->request('crm'), DiscoveryService::TRIGGER_CLI)->status);
		add_filter('wp_doing_cron', '__return_true');
		self::assertNull($this->discovery->runBackground(20.0, true)['processed'], 'Wstrzymane — tło nie wysyła żądań.');
		self::assertCount(4, $this->dataForSeoRequests());
	}

	public function test_rate_limited_request_is_retried_later_and_interrupted_requests_are_not_repeated(): void
	{
		$context = $this->projectWithKeywords();
		$this->google->json(self::RELATED, 200, DataForSeoFakes::envelope(['status_code' => 40202, 'status_message' => 'Rate limit.'], 40202));
		$this->google->json(self::RELATED, 200, DataForSeoFakes::envelope(['status_code' => 40202, 'status_message' => 'Rate limit.'], 40202));
		$this->google->json(self::RELATED, 200, DataForSeoFakes::envelope(['status_code' => 40202, 'status_message' => 'Rate limit.'], 40202));
		$result = $this->discovery->start($context, $this->request('seo'), DiscoveryService::TRIGGER_CLI);

		$this->discovery->execute($context, $result->run);
		$seed = $this->runs->seeds($result->run->id)[0];
		self::assertSame(['pending', 'rate_limited'], [$seed['status'], $seed['error_code']], 'Limit żądań — dostawca nic nie wykonał; seed wraca do kolejki.');

		$this->clock->advance(301);
		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('pozycjonowanie', 900), 1]]);
		$this->discovery->execute($context, $this->runs->findById($result->run->id));
		self::assertSame(DiscoveryRun::COMPLETED, $this->runs->findById($result->run->id)->status);

		// Seed „w trakcie” od ponad godziny (proces padł w trakcie żądania) — zamknięty bez ponawiania.
		$this->resetDiscoveryOptions();
		$interrupted = $this->discovery->start($context, $this->request('sem'), DiscoveryService::TRIGGER_CLI);
		$db = self::db();
		$db->execute("UPDATE `{$db->table('discovery_run_seeds')}` SET status = 'running', started_at = %s WHERE run_id = %d", ['2026-01-15 10:00:00', $interrupted->run->id]);
		$this->clock->advance(7200);
		add_filter('wp_doing_cron', '__return_true');
		$report = $this->discovery->runBackground(20.0, true);
		self::assertSame(1, $report['interrupted']);
		self::assertSame(DiscoveryRun::FAILED, $this->runs->findById($interrupted->run->id)->status);
	}

	public function test_market_data_status_shows_discovery_costs_within_shared_usage(): void
	{
		$context = $this->projectWithKeywords();
		$this->spend(0.06, $context->projectId());
		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('pozycjonowanie', 900), 1]], null, 0.0122);
		$this->discover($context, 'seo');

		$status = $this->market->status($context);
		self::assertSame(['tasks' => 1, 'cost' => 0.0122], $status['usage_breakdown']['today']['discovery']);
		self::assertSame(['tasks' => 1, 'cost' => 0.06], $status['usage_breakdown']['today']['enrichment']);
		self::assertSame(0.0722, $status['budget']['spent_today'], 'Jeden wspólny limit.');
	}

	/** Zadanie wzbogacania danych rynkowych (STEP 12) z kosztem — zużycie wspólnego limitu. */
	private function spend(float $cost, ?int $projectId = null): void
	{
		$now = $this->clock->now()->format('Y-m-d H:i:s');
		self::db()->insert(self::db()->table('market_tasks'), [
			'provider' => 'dataforseo', 'endpoint' => 'google_ads_search_volume', 'mode' => 'standard', 'trigger_type' => MarketSyncService::TRIGGER_AUTO,
			'project_id' => $projectId, 'location_code' => 2616, 'language_code' => 'pl', 'status' => 'completed', 'keywords_count' => 1,
			'estimated_cost' => $cost, 'cost' => $cost, 'created_at' => $now, 'updated_at' => $now,
		]);
	}

	/**
	 * @return list<string>
	 */
	private function sortedListed(\OsfSeo\Auth\ProjectContext $context): array
	{
		$keywords = $this->listed($context, ['visibility' => 'all', 'status' => 'all']);
		sort($keywords);

		return $keywords;
	}
}
