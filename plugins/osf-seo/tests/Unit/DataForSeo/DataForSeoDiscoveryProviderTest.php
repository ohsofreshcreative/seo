<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\DataForSeo;

use InvalidArgumentException;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoDiscoveryProvider;
use OsfSeo\DataForSeo\DataForSeoMarkets;
use OsfSeo\Discovery\DiscoveryMethod;
use OsfSeo\Discovery\DiscoveryQuery;
use OsfSeo\Market\Market;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Support\Logger;
use OsfSeo\Tests\Support\DataForSeoFakes;
use OsfSeo\Tests\Support\FakeHttpTransport;
use OsfSeo\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class DataForSeoDiscoveryProviderTest extends TestCase
{
	private FakeHttpTransport $http;

	private DataForSeoDiscoveryProvider $provider;

	private Market $poland;

	protected function setUp(): void
	{
		DataForSeoFakes::configure();
		$this->http = new FakeHttpTransport();
		$config = new DataForSeoConfig();
		$this->provider = new DataForSeoDiscoveryProvider(new DataForSeoClient($config, $this->http, new RecordingSleeper(), new Logger(Logger::ERROR, static function (): void {
		})), $config);
		$this->poland = DataForSeoMarkets::resolve('pl', 'pl');
	}

	protected function tearDown(): void
	{
		DataForSeoFakes::clear();
		putenv(DataForSeoConfig::PRICE_DISCOVERY_REQUEST);
		putenv(DataForSeoConfig::PRICE_DISCOVERY_ITEM);
	}

	public function test_related_keywords_request_uses_market_depth_limits_and_provider_side_filters(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::relatedResult('strony internetowe', []));

		$this->provider->discover($this->poland, new DiscoveryQuery(DiscoveryMethod::Related, 'strony internetowe', 35, 0, 2, 10, 60));

		self::assertSame('https://api.dataforseo.com/v3/dataforseo_labs/google/related_keywords/live', $this->http->requests[0]['url']);
		self::assertSame('POST', $this->http->requests[0]['method']);
		self::assertSame([[
			'keyword' => 'strony internetowe',
			'location_code' => 2616,
			'language_code' => 'pl',
			'include_seed_keyword' => true,
			'include_serp_info' => false,
			'ignore_synonyms' => false,
			'limit' => 35,
			'offset' => 0,
			'order_by' => ['keyword_data.keyword_info.search_volume,desc'],
			'depth' => 2,
			'filters' => [
				['keyword_data.keyword_info.search_volume', '>=', 10],
				'and',
				['keyword_data.keyword_properties.keyword_difficulty', '<=', 60],
			],
		]], json_decode((string) $this->http->requests[0]['body'], true));
		self::assertStringNotContainsString('clickstream', (string) $this->http->requests[0]['body'], 'Dane clickstream (podwójna cena) nigdy nie są zamawiane.');
	}

	public function test_suggestions_request_has_no_depth_and_skips_seed_data_on_later_pages(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::suggestionsResult('woocommerce', []));

		$this->provider->discover($this->poland, new DiscoveryQuery(DiscoveryMethod::Suggestions, 'woocommerce', 1000, 1000, null, 0, null));

		$body = json_decode((string) $this->http->requests[0]['body'], true)[0];
		self::assertSame('https://api.dataforseo.com/v3/dataforseo_labs/google/keyword_suggestions/live', $this->http->requests[0]['url']);
		self::assertSame([1000, 1000, false, false], [$body['limit'], $body['offset'], $body['include_seed_keyword'], $body['exact_match']]);
		self::assertArrayNotHasKey('depth', $body);
		self::assertArrayNotHasKey('filters', $body, 'Bez min. wolumenu i maks. trudności — bez filtrów.');
		self::assertSame(['keyword_info.search_volume,desc'], $body['order_by']);
	}

	public function test_single_filter_is_sent_as_one_condition(): void
	{
		$body = DataForSeoDiscoveryProvider::requestBody($this->poland, new DiscoveryQuery(DiscoveryMethod::Suggestions, 'ux ui', 50, 0, null, 0, 40));

		self::assertSame(['keyword_properties.keyword_difficulty', '<=', 40], $body['filters']);
	}

	public function test_related_result_is_parsed_with_depth_position_seed_metrics_and_intent(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::relatedResult('strony internetowe', [
			[DataForSeoFakes::labsKeyword('projektowanie stron internetowych', 1900, 41, 6.2, 0.71, 'commercial'), 1],
			[DataForSeoFakes::labsKeyword('tanie strony www', 320, null, null, null, null), 2],
			[DataForSeoFakes::labsKeyword('website design', 50, 20, 1.0, 0.1, 'informational', true), 2],
		], DataForSeoFakes::labsKeyword('strony internetowe', 2400, 35), 72, 0.0127));

		$batch = $this->provider->discover($this->poland, new DiscoveryQuery(DiscoveryMethod::Related, 'strony internetowe', 50, 0, 2));

		self::assertSame(72, $batch->totalCount);
		self::assertSame(0.0127, $batch->cost);
		self::assertCount(3, $batch->items);
		[$first, $second, $third] = $batch->items;
		self::assertSame(['projektowanie stron internetowych', 1900, 41, 6.2, 'high', 71, 'commercial', 1, 1], [
			$first->keyword, $first->searchVolume, $first->keywordDifficulty, $first->cpc, $first->competitionLevel, $first->competitionIndex, $first->intent, $first->depth, $first->position,
		]);
		self::assertSame([['month' => '2026-07-01', 'search_volume' => 1710], ['month' => '2026-08-01', 'search_volume' => 1900]], $first->monthly);
		self::assertSame([null, null, null, null, 2, 2], [$second->keywordDifficulty, $second->cpc, $second->competitionIndex, $second->intent, $second->depth, $second->position]);
		self::assertTrue($third->isAnotherLanguage);
		self::assertSame(['is_another_language' => true], $third->meta());
		self::assertSame(['strony internetowe', 2400, 35, 0], [$batch->seed?->keyword, $batch->seed?->searchVolume, $batch->seed?->keywordDifficulty, $batch->seed?->depth]);
		self::assertSame(2400, $batch->seed?->volumeMetrics()->searchVolume, 'Metryki w formacie danych rynkowych (STEP 12).');
	}

	public function test_suggestions_pagination_positions_continue_after_offset(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::suggestionsResult('woocommerce', [
			DataForSeoFakes::labsKeyword('sklep woocommerce', 880),
			DataForSeoFakes::labsKeyword('woocommerce wtyczki', 210),
		], null, 2400, 0.0124, 1000));

		$batch = $this->provider->discover($this->poland, new DiscoveryQuery(DiscoveryMethod::Suggestions, 'woocommerce', 1000, 1000));

		self::assertSame([1001, 1002], array_map(static fn ($item): int => $item->position, $batch->items));
		self::assertSame(2400, $batch->totalCount);
		self::assertNull($batch->seed);
		self::assertNull($batch->items[0]->depth);
	}

	public function test_no_results_status_is_an_empty_batch_with_cost(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::envelope(['status_code' => 40102, 'status_message' => 'No Search Results.', 'cost' => 0.012], 20000, 0.012));

		$batch = $this->provider->discover($this->poland, new DiscoveryQuery(DiscoveryMethod::Related, 'xyzzy', 10));

		self::assertSame([[], 0, 0.012], [$batch->items, $batch->totalCount, $batch->cost]);
	}

	public function test_invalid_values_become_null_and_items_without_keyword_are_skipped(): void
	{
		$broken = DataForSeoFakes::labsKeyword('audyt ux', 90);
		$broken['keyword_info']['search_volume'] = -5;
		$broken['keyword_info']['competition'] = 7;
		$broken['keyword_info']['cpc'] = 'drogo';
		$broken['keyword_properties']['keyword_difficulty'] = 150;
		$broken['search_intent_info']['main_intent'] = 'shopping';
		$this->http->pushJson(200, DataForSeoFakes::suggestionsResult('audyt', [$broken, ['keyword' => ''], 'oops', ['keyword_info' => []]]));

		$batch = $this->provider->discover($this->poland, new DiscoveryQuery(DiscoveryMethod::Suggestions, 'audyt', 10));

		self::assertCount(1, $batch->items);
		$item = $batch->items[0];
		self::assertSame([null, null, null, null, null], [$item->searchVolume, $item->competitionIndex, $item->cpc, $item->keywordDifficulty, $item->intent]);
		self::assertSame(1, $item->position);
	}

	public function test_malformed_result_and_task_errors_are_classified(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::envelope(['result' => ['items' => 'x']]));
		$this->http->pushJson(200, DataForSeoFakes::envelope(['status_code' => 40501, 'status_message' => 'Invalid Field: depth.']));
		$this->http->pushJson(200, DataForSeoFakes::envelope(['status_code' => 40200, 'status_message' => 'Payment Required.']));

		$categories = [];

		for ($i = 0; $i < 3; $i++) {
			try {
				$this->provider->discover($this->poland, new DiscoveryQuery(DiscoveryMethod::Related, 'seo', 10));
				self::fail('Oczekiwano wyjątku.');
			} catch (ProviderException $exception) {
				$categories[] = $exception->category();
			}
		}

		self::assertSame([ProviderErrorCategory::MalformedResponse, ProviderErrorCategory::InvalidRequest, ProviderErrorCategory::Billing], $categories);
		self::assertCount(3, $this->http->requests, 'Płatne żądanie nie jest ponawiane po błędzie.');
	}

	public function test_invalid_query_is_rejected_before_any_request(): void
	{
		$this->expectException(InvalidArgumentException::class);

		try {
			$this->provider->discover($this->poland, new DiscoveryQuery(DiscoveryMethod::Related, 'seo', 1001));
		} finally {
			self::assertSame([], $this->http->requests);
		}
	}

	public function test_cost_estimate_and_result_caps(): void
	{
		self::assertSame(0.012 + 36 * 0.00012, $this->provider->estimateCost(35));
		self::assertSame(0.012 + 0.00012, $this->provider->estimateCost(0));
		putenv(DataForSeoConfig::PRICE_DISCOVERY_REQUEST . '=0.02');
		putenv(DataForSeoConfig::PRICE_DISCOVERY_ITEM . '=0.0002');
		self::assertSame(0.02 + 11 * 0.0002, $this->provider->estimateCost(10));

		self::assertSame([8, 72, 584], [
			$this->provider->maxResults(DiscoveryMethod::Related, 1),
			$this->provider->maxResults(DiscoveryMethod::Related, 2),
			$this->provider->maxResults(DiscoveryMethod::Related, 3),
		]);
		self::assertNull($this->provider->maxResults(DiscoveryMethod::Suggestions, null));
		self::assertSame(['labs_related_keywords', 'live'], [$this->provider->endpoint(DiscoveryMethod::Related)->name, $this->provider->endpoint(DiscoveryMethod::Related)->mode]);
		self::assertSame('dataforseo', $this->provider->name(), 'Wspólne dane rynkowe z dostawcą metryk (STEP 12).');
	}
}
