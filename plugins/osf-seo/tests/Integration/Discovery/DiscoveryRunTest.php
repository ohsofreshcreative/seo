<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Discovery;

use OsfSeo\Discovery\CandidateStatus;
use OsfSeo\Discovery\DiscoveryConfig;
use OsfSeo\Discovery\DiscoveryRun;
use OsfSeo\Discovery\DiscoveryService;
use OsfSeo\Discovery\DiscoveryStartResult;
use OsfSeo\Discovery\Visibility;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketTaskRepository;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Plan (bez API), przebieg, deduplikacja, wiele seedów, ponowne użycie metryk rynkowych, limity i filtry jakości.
 */
final class DiscoveryRunTest extends DiscoveryTestCase
{
	public function test_plan_is_free_and_shows_requests_items_and_maximum_cost(): void
	{
		$context = $this->projectWithKeywords();

		$plan = $this->discovery->plan($context, $this->request("strony internetowe\nwoocommerce\nUX UI\nsklep?", ['max_candidates' => '250']));

		self::assertSame([], $this->dataForSeoRequests(), 'Plan nie wysyła żadnego żądania.');
		self::assertNull($plan->skipReason);
		self::assertSame('Polska / polski', $plan->market?->label());
		self::assertSame(['strony internetowe', 'woocommerce', 'ux ui'], array_map(static fn ($seed): string => $seed->seed, $plan->seeds));
		self::assertSame([['seed' => 'sklep?', 'reason' => 'provider_rules']], $plan->rejected);
		// 250 / 3 seedy = 83 na seed, ale Related Keywords przy głębokości 2 zwraca najwyżej 72 frazy.
		self::assertSame(83, $plan->seedLimit);
		self::assertSame([3, 216], [$plan->requests(), $plan->maxItems()]);
		self::assertSame(round(3 * (0.012 + 73 * 0.00012), 6), $plan->estimatedCost());
		self::assertSame([1.0, 10.0], [$plan->remainingToday(), $plan->remainingMonth()]);
		self::assertNull($plan->blockedBy());

		$suggestions = $this->discovery->plan($context, $this->request('woocommerce', ['method' => 'suggestions', 'max_candidates' => '100']));
		self::assertSame([1, 100], [$suggestions->requests(), $suggestions->maxItems()], 'Keyword Suggestions — limit przebiegu na jeden seed.');
	}

	public function test_start_only_queues_and_execution_creates_candidates_with_sources_costs_and_shared_metrics(): void
	{
		$context = $this->projectWithKeywords();
		$result = $this->discovery->start($context, $this->request('strony internetowe', ['max_candidates' => '100']), DiscoveryService::TRIGGER_MANUAL);

		self::assertSame(DiscoveryStartResult::QUEUED, $result->status);
		self::assertSame([], $this->dataForSeoRequests(), 'Uruchomienie z panelu tylko kolejkuje — żądania wysyła tło.');
		self::assertSame(DiscoveryRun::QUEUED, $result->run?->status);

		$this->mockRelated('strony internetowe', [
			[DataForSeoFakes::labsKeyword('projektowanie stron internetowych', 1900, 41, 6.2, 0.71, 'commercial'), 1],
			[DataForSeoFakes::labsKeyword('tanie strony internetowe', 480, 22, 3.1, 0.55, 'transactional'), 1],
			[DataForSeoFakes::labsKeyword('strony www kraków', 210, null, null, null, null), 2],
		], 2400, 0.0124);

		$this->discovery->execute($context, $result->run);
		$run = $this->runs->findById($result->run->id);

		self::assertSame(DiscoveryRun::COMPLETED, $run->status);
		self::assertSame([1, 3, 4, 0.0124], [$run->tasksDone, $run->itemsReceived, $run->candidatesNew, $run->cost]);
		self::assertSame([[
			'keyword' => 'strony internetowe', 'location_code' => 2616, 'language_code' => 'pl', 'include_seed_keyword' => true, 'include_serp_info' => false,
			'ignore_synonyms' => false, 'limit' => 72, 'offset' => 0, 'order_by' => ['keyword_data.keyword_info.search_volume,desc'], 'depth' => 2,
			'filters' => ['keyword_data.keyword_info.search_volume', '>=', 10],
		]], $this->discoveryBodies());

		$task = self::db()->fetchRow("SELECT * FROM `" . self::db()->table('market_tasks') . '`');
		self::assertSame([MarketTaskRepository::TRIGGER_DISCOVERY, 'labs_related_keywords', 'live', 'completed', '0.012400'], [$task['trigger_type'], $task['endpoint'], $task['mode'], $task['status'], $task['cost']]);
		self::assertSame(['strony internetowe'], json_decode((string) $task['keywords'], true));

		$candidate = $this->candidate($context, 'projektowanie stron internetowych');
		self::assertSame(CandidateStatus::New, $candidate->status);
		self::assertSame([1900, 41, 6.2, 'high', 71, 'commercial'], [$candidate->market->searchVolume, $candidate->market->keywordDifficulty, $candidate->market->cpc, $candidate->market->competitionLevel, $candidate->market->competitionIndex, $candidate->intent]);
		self::assertSame([['month' => '2026-07-01', 'search_volume' => 1710], ['month' => '2026-08-01', 'search_volume' => 1900]], $candidate->market->monthly);
		self::assertSame([['strony internetowe', 'related', 80, 1, 1, $run->publicId]], array_map(static fn (array $s): array => [$s['seed'], $s['method'], $s['relation'], $s['depth'], $s['position'], $s['run']], $candidate->sources));
		self::assertNotNull($candidate->priority, 'Priorytet przeliczony po przebiegu.');

		$seed = $this->candidate($context, 'strony internetowe');
		self::assertSame([100, 0], [$seed->sources[0]['relation'], $seed->sources[0]['depth']], 'Sam seed też jest kandydatem (gdy strona go nie pokrywa).');
		self::assertNull($this->candidate($context, 'strony www kraków')->market->keywordDifficulty, 'Brak trudności u dostawcy = null, nie 0.');
		self::assertSame('2400', $this->marketRow('strony internetowe')['search_volume'], 'Metryki trafiają do wspólnych danych rynkowych.');
	}

	public function test_same_keyword_from_two_seeds_is_one_candidate_with_two_sources_and_stronger_relevance(): void
	{
		$context = $this->projectWithKeywords();
		$this->mockRelated('sklepy internetowe', [
			[DataForSeoFakes::labsKeyword('projektowanie sklepów internetowych', 720, 38), 2],
			[DataForSeoFakes::labsKeyword('sklep internetowy cena', 390, 25), 1],
		]);
		$this->mockRelated('woocommerce', [
			[DataForSeoFakes::labsKeyword('Projektowanie Sklepów  Internetowych', 720, 38), 1],
			[DataForSeoFakes::labsKeyword('woocommerce wtyczki', 170, 12), 1],
		]);

		$run = $this->discover($context, "sklepy internetowe\nwoocommerce");

		self::assertSame(3, $run->candidatesNew, '2 + 2 frazy, z czego jedna wspólna (inna wielkość liter i spacje) — 3 kandydatów.');
		$candidate = $this->candidate($context, 'projektowanie sklepów internetowych');
		self::assertSame(2, $candidate->seedsCount);
		self::assertSame(80, $candidate->bestRelation, 'Najsilniejsze powiązanie: głębokość 1 od „woocommerce”.');
		self::assertSame(['woocommerce', 'sklepy internetowe'], array_column($candidate->sources, 'seed'));
		self::assertSame(1, self::tableCount('discovery_candidates', "market_keyword_id = {$candidate->market->id}"), 'Bez duplikatów kandydata.');
		self::assertSame(18.0, $candidate->score?->components['relevance'], 'Dwa seedy: 20 × min(1; 0,8 + 0,1).');
	}

	public function test_fresh_step12_metrics_are_reused_and_not_overwritten_while_missing_or_stale_ones_are_filled(): void
	{
		$context = $this->projectWithKeywords();
		$market = $this->market->market($context);
		$items = \OsfSeo\DataForSeo\DataForSeoProvider::parseVolume([DataForSeoFakes::volumeItem('audyt ux', 260, 4.0, 'HIGH', 80)]);
		$this->marketMetrics->storeVolume($market, ['audyt ux'], ['audyt ux' => $items[0]], 1, 30);
		$this->marketMetrics->storeVolume($market, ['audyt seo'], ['audyt seo' => \OsfSeo\DataForSeo\DataForSeoProvider::parseVolume([DataForSeoFakes::volumeItem('audyt seo', 50, 1.0, 'LOW', 10)])[0]], 1, 30);
		$this->clock->advance(20 * 86400);
		$this->marketMetrics->storeVolume($market, ['audyt ux'], ['audyt ux' => $items[0]], 2, 30);
		$this->clock->advance(15 * 86400);
		// „audyt ux” — wolumen z Google Ads sprzed 15 dni (świeży), „audyt seo” — sprzed 35 dni (po TTL).
		$this->mockSuggestions('audyt', [
			DataForSeoFakes::labsKeyword('audyt ux', 300, 33, 5.0, 0.9, 'commercial'),
			DataForSeoFakes::labsKeyword('audyt seo', 590, 48, 7.0, 0.6, 'commercial'),
		]);

		$this->discover($context, 'audyt', ['method' => 'suggestions']);

		$ux = $this->marketRow('audyt ux');
		self::assertSame(['260', '80', '33', 'commercial'], [$ux['search_volume'], $ux['competition_index'], $ux['keyword_difficulty'], $ux['search_intent']], 'Świeży wolumen STEP 12 zostaje; brakująca trudność i intencja uzupełnione z odpowiedzi.');
		$seo = $this->marketRow('audyt seo');
		self::assertSame(['590', '60', '48'], [$seo['search_volume'], $seo['competition_index'], $seo['keyword_difficulty']], 'Wolumen po TTL odświeżony danymi z wyszukiwania.');
		self::assertCount(1, $this->dataForSeoRequests(), 'Żadnych dodatkowych żądań wzbogacających kandydatów.');
		self::assertSame(0, self::tableCount('market_tasks', "trigger_type <> 'discovery'"));
	}

	public function test_max_candidates_limits_items_per_seed_and_new_candidates_of_the_run(): void
	{
		$context = $this->projectWithKeywords();
		$items = static fn (string $prefix): array => array_map(static fn (int $i): array => [DataForSeoFakes::labsKeyword("{$prefix} fraza {$i}", 100 - $i), 1], range(1, 5));
		$this->mockRelated('seo', $items('seo'), 900);
		$this->mockRelated('sem', $items('sem'), 800);

		$run = $this->discover($context, "seo\nsem", ['max_candidates' => '10']);

		self::assertSame([5, 5], array_column($this->discoveryBodies(), 'limit'), '10 kandydatów / 2 seedy = 5 elementów na seed.');
		self::assertSame(10, $run->candidatesNew);
		self::assertSame(['limit' => 2], $run->rejected, 'Seed + 5 fraz drugiego seeda przekracza limit przebiegu — nadmiar pominięty.');
		self::assertSame(10, self::tableCount('discovery_candidates'));
	}

	public function test_quality_filters_reject_excluded_other_language_low_volume_and_too_difficult_keywords(): void
	{
		$context = $this->projectWithKeywords();
		$this->discovery->saveExclusions($context, "praca\ndarmow*");
		$this->mockRelated('strony internetowe', [
			[DataForSeoFakes::labsKeyword('praca strony internetowe', 500, 20), 1],
			[DataForSeoFakes::labsKeyword('darmowe strony internetowe', 880, 30), 1],
			[DataForSeoFakes::labsKeyword('website design', 700, 30, 1.0, 0.1, null, true), 1],
			[DataForSeoFakes::labsKeyword('strony internetowe trudne', 400, 85), 1],
			[DataForSeoFakes::labsKeyword('strony internetowe dobre', 320, 35), 1],
		], 5);

		$run = $this->discover($context, 'strony internetowe', ['max_kd' => '60', 'min_volume' => '10']);

		self::assertSame(['strony internetowe dobre'], $this->listed($context, ['visibility' => 'all']));
		self::assertEquals(['excluded' => 2, 'other_language' => 1, 'max_difficulty' => 1, 'min_volume' => 1], $run->rejected, 'Seed z wolumenem 5 < 10 też odrzucony.');
		self::assertSame(['keyword_data.keyword_properties.keyword_difficulty', '<=', 60], $this->discoveryBodies()[0]['filters'][2], 'Maks. trudność filtrowana też po stronie dostawcy.');

		$this->resetDiscoveryOptions();
		$this->mockRelated('strony internetowe', [[DataForSeoFakes::labsKeyword('website design', 700, 30, 1.0, 0.1, null, true), 1]]);
		$this->discover($context, 'strony internetowe', ['other_language' => '1', 'force' => '1', 'max_kd' => '60']);
		self::assertContains('website design', $this->listed($context, ['visibility' => 'all']), 'Inny język — opcjonalnie uwzględniony.');
	}

	public function test_suggestions_paginate_with_offset_until_provider_results_end(): void
	{
		putenv(DiscoveryConfig::MAX_CANDIDATES . '=5000');
		$context = $this->projectWithKeywords();
		$this->mockSuggestions('woocommerce', array_map(static fn (int $i): array => DataForSeoFakes::labsKeyword("woocommerce {$i}", 2000 - $i), range(1, 1000)), 1300);
		$this->mockSuggestions('woocommerce', array_map(static fn (int $i): array => DataForSeoFakes::labsKeyword("woocommerce {$i}", 2000 - $i), range(1001, 1300)), 1300, 0.0, 1000);

		$plan = $this->discovery->plan($context, $this->request('woocommerce', ['method' => 'suggestions', 'max_candidates' => '2500']));
		self::assertSame([2500, 3], [$plan->seedLimit, $plan->requests()]);

		$run = $this->discover($context, 'woocommerce', ['method' => 'suggestions', 'max_candidates' => '2500']);

		self::assertSame([[1000, 0], [1000, 1000]], array_map(static fn (array $body): array => [$body['limit'], $body['offset']], $this->discoveryBodies()), 'Druga strona od offsetu 1000; koniec wyników (1300) — bez trzeciego żądania.');
		self::assertSame([true, false], array_column($this->discoveryBodies(), 'include_seed_keyword'), 'Dane seeda tylko na pierwszej stronie.');
		self::assertSame([DiscoveryRun::COMPLETED, 2, 1300, 1300], [$run->status, $run->tasksDone, $run->itemsReceived, $run->candidatesNew]);
		self::assertSame(1301, (int) $this->candidate($context, 'woocommerce 1300')->sources[0]['position'] + 1);
	}

	public function test_background_step_processes_runs_within_the_task_limit_per_pass(): void
	{
		putenv(MarketDataConfig::MAX_TASKS_PER_RUN . '=1');
		$context = $this->projectWithKeywords();
		$result = $this->discovery->start($context, $this->request("seo\nsem"), DiscoveryService::TRIGGER_MANUAL);
		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('pozycjonowanie', 500), 1]]);
		$this->mockRelated('sem', [[DataForSeoFakes::labsKeyword('kampanie google ads', 400), 1]]);

		self::assertNull($this->discovery->runBackground(20.0, true)['processed'], 'Poza procesem systemowym (cron/CLI) nic się nie dzieje.');
		self::assertSame([], $this->dataForSeoRequests());

		add_filter('wp_doing_cron', '__return_true');
		$first = $this->discovery->runBackground(20.0, true);
		self::assertSame(1, $first['processed']['requests'], 'Limit 1 żądania na przebieg tła.');
		self::assertSame(DiscoveryRun::RUNNING, $this->runs->findById($result->run->id)->status);

		$this->discovery->runBackground(20.0, true);
		self::assertSame(DiscoveryRun::COMPLETED, $this->runs->findById($result->run->id)->status);
		self::assertCount(2, $this->dataForSeoRequests());
		self::assertContains('kampanie google ads', $this->listed($context));
	}

	public function test_numeric_keywords_are_stored_as_text_in_market_data_and_candidates(): void
	{
		$context = $this->projectWithKeywords('example.pl', [['2024', 300, 10], ['seo', 200, 5]]);
		$market = $this->market->market($context);
		// Klucze tablic PHP zamieniają „2024” na int — zapis metryk (STEP 12) i kandydatów (STEP 13) musi to obsłużyć.
		$this->marketMetrics->storeDifficulty($market, ['2024'], ['2024' => 12], 1, 30);
		$this->marketMetrics->storeVolume($market, ['2024'], ['2024' => \OsfSeo\DataForSeo\DataForSeoProvider::parseVolume([DataForSeoFakes::volumeItem('2024', 900)])[0]], 1, 30);
		self::assertSame(['900', '12'], [$this->marketRow('2024')['search_volume'], $this->marketRow('2024')['keyword_difficulty']]);

		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('404', 1300, 10), 1], [DataForSeoFakes::labsKeyword('seo 2024', 500, 20), 1]]);
		$this->discover($context, 'seo');

		self::assertSame(1300, $this->candidate($context, '404')->market->searchVolume);
		self::assertSame('404', $this->candidate($context, '404')->keyword);
	}
}
