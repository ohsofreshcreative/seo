<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\DataForSeo;

use InvalidArgumentException;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoMarkets;
use OsfSeo\DataForSeo\DataForSeoProvider;
use OsfSeo\Market\Market;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Support\Logger;
use OsfSeo\Tests\Support\DataForSeoFakes;
use OsfSeo\Tests\Support\FakeHttpTransport;
use OsfSeo\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class DataForSeoProviderTest extends TestCase
{
	private FakeHttpTransport $http;

	private DataForSeoProvider $provider;

	private Market $poland;

	protected function setUp(): void
	{
		DataForSeoFakes::configure();
		$this->http = new FakeHttpTransport();
		$config = new DataForSeoConfig();
		$this->provider = new DataForSeoProvider(new DataForSeoClient($config, $this->http, new RecordingSleeper(), new Logger(Logger::ERROR, static function (): void {
		})), $config);
		$this->poland = DataForSeoMarkets::resolve('pl', 'pl');
	}

	protected function tearDown(): void
	{
		DataForSeoFakes::clear();
		putenv(DataForSeoConfig::PRICE_VOLUME_TASK);
	}

	public function test_markets_map_project_country_and_language_to_dataforseo_codes(): void
	{
		self::assertSame([2616, 'pl', 'Polska / polski'], [$this->poland->locationCode, $this->poland->languageCode, $this->poland->label()]);
		self::assertSame(2616, DataForSeoMarkets::resolve('PL', 'pl-pl')?->locationCode);
		self::assertSame([2276, 'de'], [DataForSeoMarkets::resolve('de', 'de')?->locationCode, DataForSeoMarkets::resolve('de', 'de')?->languageCode]);
		self::assertSame(2826, DataForSeoMarkets::resolve('gb', 'en')?->locationCode);
		self::assertSame(2826, DataForSeoMarkets::resolve('uk', 'en')?->locationCode);
		self::assertSame(2840, DataForSeoMarkets::resolve('us', 'en')?->locationCode);
		self::assertNull(DataForSeoMarkets::resolve('fr', 'fr'), 'Rynek spoza katalogu nie jest zgadywany.');
		self::assertNull(DataForSeoMarkets::resolve('pl', 'en'));
		self::assertSame('dataforseo:2616:pl', $this->poland->id());
	}

	public function test_submit_volume_posts_one_standard_task_and_returns_its_id_and_cost(): void
	{
		$taskId = DataForSeoFakes::taskId();
		$this->http->pushJson(200, DataForSeoFakes::taskCreated($taskId, 0.06));

		$submission = $this->provider->submitVolume($this->poland, ['żółte buty', 'buty damskie']);

		self::assertSame($taskId, $submission->taskId);
		self::assertSame(0.06, $submission->cost);
		self::assertNull($submission->results);
		self::assertSame('https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/task_post', $this->http->requests[0]['url']);
		self::assertSame([['keywords' => ['żółte buty', 'buty damskie'], 'location_code' => 2616, 'language_code' => 'pl']], json_decode((string) $this->http->requests[0]['body'], true));
		self::assertStringContainsString('żółte buty', (string) $this->http->requests[0]['body'], 'UTF-8 bez escapowania.');
	}

	public function test_task_get_in_queue_is_not_ready(): void
	{
		$taskId = DataForSeoFakes::taskId();
		$this->http->pushJson(200, DataForSeoFakes::taskInQueue($taskId));
		$this->http->pushJson(200, DataForSeoFakes::envelope(['id' => $taskId, 'status_code' => 40601, 'status_message' => 'Task Handed.']));

		self::assertNull($this->provider->fetchVolume($taskId));
		self::assertNull($this->provider->fetchVolume($taskId));
		self::assertSame('GET', $this->http->requests[0]['method']);
		self::assertSame('https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/task_get/' . $taskId, $this->http->requests[0]['url']);
	}

	public function test_volume_results_are_parsed_with_nulls_kept_as_unknown(): void
	{
		$taskId = DataForSeoFakes::taskId();
		$this->http->pushJson(200, DataForSeoFakes::volumeResult($taskId, [
			DataForSeoFakes::volumeItem('buty damskie', 1900, 0.87, 'HIGH', 91, [
				['year' => 2026, 'month' => 8, 'search_volume' => 2400],
				['year' => 2025, 'month' => 9, 'search_volume' => 1300],
				['year' => 2026, 'month' => 1, 'search_volume' => null],
			]),
			DataForSeoFakes::volumeItem('bardzo rzadka fraza', null, null, null, null),
			['keyword' => 'zepsute typy', 'search_volume' => '120', 'cpc' => 'abc', 'competition' => 'EXTREME', 'competition_index' => 140, 'monthly_searches' => 'x'],
			['search_volume' => 100],
		]));

		$batch = $this->provider->fetchVolume($taskId);

		self::assertNotNull($batch);
		self::assertCount(3, $batch->items, 'Element bez frazy jest pomijany.');
		[$full, $empty, $broken] = $batch->items;
		self::assertSame(['buty damskie', 1900, 0.87, 'high', 91, 0.44, 1.74], [$full->keyword, $full->searchVolume, $full->cpc, $full->competitionLevel, $full->competitionIndex, $full->lowTopOfPageBid, $full->highTopOfPageBid]);
		self::assertSame([
			['month' => '2025-09-01', 'search_volume' => 1300],
			['month' => '2026-01-01', 'search_volume' => null],
			['month' => '2026-08-01', 'search_volume' => 2400],
		], $full->monthly);
		self::assertSame([null, null, null, null, []], [$empty->searchVolume, $empty->cpc, $empty->competitionLevel, $empty->competitionIndex, $empty->monthly], 'Brak danych to null, nie 0.');
		self::assertSame([null, null, null, null, []], [$broken->searchVolume, $broken->cpc, $broken->competitionLevel, $broken->competitionIndex, $broken->monthly]);
	}

	public function test_task_level_errors_are_classified(): void
	{
		$taskId = DataForSeoFakes::taskId();
		$this->http->pushJson(200, DataForSeoFakes::envelope(['id' => $taskId, 'status_code' => 40401, 'status_message' => 'Task not found.']));
		$this->http->pushJson(200, DataForSeoFakes::envelope(['status_code' => 40501, 'status_message' => 'Invalid Field: keywords.']));
		$this->http->pushJson(200, DataForSeoFakes::envelope(['status_code' => 40210, 'status_message' => 'Insufficient Funds.']));

		foreach ([
			[fn () => $this->provider->fetchVolume($taskId), ProviderErrorCategory::TaskError],
			[fn () => $this->provider->submitVolume($this->poland, ['buty']), ProviderErrorCategory::InvalidRequest],
			[fn () => $this->provider->difficulty($this->poland, ['buty']), ProviderErrorCategory::Billing],
		] as [$call, $expected]) {
			try {
				$call();
				self::fail('Oczekiwano wyjątku.');
			} catch (ProviderException $exception) {
				self::assertSame($expected, $exception->category());
			}
		}
	}

	public function test_submit_without_task_id_is_malformed(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::envelope(['id' => '', 'status_code' => 20100, 'status_message' => 'Task Created.']));

		$this->expectException(ProviderException::class);
		$this->provider->submitVolume($this->poland, ['buty']);
	}

	public function test_keyword_difficulty_is_parsed_with_null_for_unknown(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::difficultyResult(['buty damskie' => 47, 'rzadka fraza' => null, 'zła wartość' => 250], 0.01236));

		$batch = $this->provider->difficulty($this->poland, ['buty damskie', 'rzadka fraza', 'zła wartość']);

		self::assertSame(0.01236, $batch->cost);
		self::assertSame([
			['keyword' => 'buty damskie', 'difficulty' => 47],
			['keyword' => 'rzadka fraza', 'difficulty' => null],
			['keyword' => 'zła wartość', 'difficulty' => null],
		], $batch->items);
		self::assertSame('https://api.dataforseo.com/v3/dataforseo_labs/google/bulk_keyword_difficulty/live', $this->http->requests[0]['url']);
	}

	public function test_no_results_status_means_empty_batch(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::envelope(['status_code' => 40102, 'status_message' => 'No Search Results.']));

		self::assertSame([], $this->provider->difficulty($this->poland, ['nieznana fraza'])->items);
	}

	public function test_batch_size_is_limited_to_the_documented_maximum(): void
	{
		$keywords = array_map(static fn (int $i): string => 'fraza ' . $i, range(1, 1001));

		foreach ([[], $keywords] as $batch) {
			try {
				$this->provider->submitVolume($this->poland, $batch);
				self::fail('Oczekiwano wyjątku.');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}

		self::assertSame([], $this->http->requests, 'Za duża paczka nie jest wysyłana.');
		self::assertSame(1000, $this->provider->maxKeywordsPerTask());
	}

	public function test_task_id_is_validated_before_building_the_url(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->provider->fetchVolume('../../appendix/user_data');
	}

	public function test_cost_estimates_follow_configurable_prices(): void
	{
		self::assertSame(0.06, $this->provider->estimateVolumeCost(1000));
		self::assertSame(0.0, $this->provider->estimateVolumeCost(0));
		self::assertEqualsWithDelta(0.012 + 1000 * 0.00012, $this->provider->estimateDifficultyCost(1000), 1e-9);
		self::assertEqualsWithDelta(0.012 + 10 * 0.00012, $this->provider->estimateDifficultyCost(10), 1e-9);

		putenv(DataForSeoConfig::PRICE_VOLUME_TASK . '=0.05');
		self::assertSame(0.05, $this->provider->estimateVolumeCost(10));
	}

	public function test_endpoints_describe_standard_and_live_modes(): void
	{
		self::assertSame(['google_ads_search_volume', 'standard'], [$this->provider->volumeEndpoint()->name, $this->provider->volumeEndpoint()->mode]);
		self::assertSame(['labs_bulk_keyword_difficulty', 'live'], [$this->provider->difficultyEndpoint()->name, $this->provider->difficultyEndpoint()->mode]);
	}
}
