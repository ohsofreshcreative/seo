<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\DataForSeo;

use InvalidArgumentException;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoMarkets;
use OsfSeo\DataForSeo\DataForSeoRankedKeywordsProvider;
use OsfSeo\Gap\RankedKeywordsQuery;
use OsfSeo\Market\Market;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Support\Logger;
use OsfSeo\Tests\Support\DataForSeoFakes;
use OsfSeo\Tests\Support\FakeHttpTransport;
use OsfSeo\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataForSeoRankedKeywordsProviderTest extends TestCase
{
	private FakeHttpTransport $http;

	private DataForSeoRankedKeywordsProvider $provider;

	private Market $poland;

	protected function setUp(): void
	{
		DataForSeoFakes::configure();
		$this->http = new FakeHttpTransport();
		$config = new DataForSeoConfig();
		$this->provider = new DataForSeoRankedKeywordsProvider(new DataForSeoClient($config, $this->http, new RecordingSleeper(), new Logger(Logger::ERROR, static function (): void {
		})), $config);
		$this->poland = DataForSeoMarkets::resolve('pl', 'pl');
	}

	protected function tearDown(): void
	{
		DataForSeoFakes::clear();
		putenv(DataForSeoConfig::PRICE_GAP_REQUEST);
		putenv(DataForSeoConfig::PRICE_GAP_ITEM);
	}

	public function test_request_is_organic_live_with_provider_side_range_and_stable_order(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::rankedResult('konkurent.pl', []));

		$this->provider->rankedKeywords($this->poland, new RankedKeywordsQuery('konkurent.pl', 1000, 2000, 30, 10));

		self::assertSame('https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live', $this->http->requests[0]['url']);
		self::assertSame('POST', $this->http->requests[0]['method']);
		self::assertSame([[
			'target' => 'konkurent.pl',
			'location_code' => 2616,
			'language_code' => 'pl',
			'item_types' => ['organic'],
			'historical_serp_mode' => 'live',
			'ignore_synonyms' => false,
			'load_rank_absolute' => false,
			'limit' => 1000,
			'offset' => 2000,
			'order_by' => ['keyword_data.keyword_info.search_volume,desc', 'ranked_serp_element.serp_item.rank_group,asc'],
			'filters' => [
				['ranked_serp_element.serp_item.rank_group', '<=', 30],
				'and',
				['keyword_data.keyword_info.search_volume', '>=', 10],
			],
		]], json_decode((string) $this->http->requests[0]['body'], true));
		self::assertStringNotContainsString('clickstream', (string) $this->http->requests[0]['body'], 'Dane clickstream (podwójna cena) nigdy nie są zamawiane.');
	}

	public function test_zero_minimum_volume_sends_only_rank_filter(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::rankedResult('konkurent.pl', []));

		$this->provider->rankedKeywords($this->poland, new RankedKeywordsQuery('konkurent.pl', 100, 0, 100, 0));

		$body = json_decode((string) $this->http->requests[0]['body'], true)[0];
		self::assertSame(['ranked_serp_element.serp_item.rank_group', '<=', 100], $body['filters']);
	}

	public function test_parses_position_url_title_metrics_and_labs_date(): void
	{
		$data = DataForSeoFakes::labsKeyword('Projektowanie Stron Internetowych', 720, 31, 4.5, 0.71, 'commercial');
		$data['keyword_properties']['core_keyword'] = 'projektowanie stron';
		$this->http->pushJson(200, DataForSeoFakes::rankedResult('konkurent.pl', [
			DataForSeoFakes::rankedItem($data, 4, 'www.konkurent.pl', 'https://www.konkurent.pl/strony-www/?srsltid=abc#oferta', 6, 'Strony WWW — Konkurent', 88.2),
		], 4321, 0.01212));

		$batch = $this->provider->rankedKeywords($this->poland, new RankedKeywordsQuery('konkurent.pl', 100, 0, 30, 10));

		self::assertSame(4321, $batch->totalCount);
		self::assertSame(1, $batch->received);
		self::assertSame(0.01212, $batch->cost);
		self::assertCount(1, $batch->items);
		$item = $batch->items[0];
		self::assertSame('Projektowanie Stron Internetowych', $item->keyword->keyword);
		self::assertSame(720, $item->keyword->searchVolume);
		self::assertSame(31, $item->keyword->keywordDifficulty);
		self::assertSame(4.5, $item->keyword->cpc);
		self::assertSame(71, $item->keyword->competitionIndex);
		self::assertSame('commercial', $item->keyword->intent);
		self::assertSame('projektowanie stron', $item->keyword->coreKeyword);
		self::assertSame(4, $item->rankGroup);
		self::assertSame(6, $item->rankAbsolute);
		self::assertSame('https://www.konkurent.pl/strony-www/', $item->url, 'Bez fragmentu i parametru śledzącego.');
		self::assertSame('konkurent.pl', $item->host, 'Host znormalizowany jak domena projektu (bez www).');
		self::assertSame('Strony WWW — Konkurent', $item->title);
		self::assertSame(88.2, $item->etv);
		self::assertSame('2026-09-20 08:15:00', $item->serpUpdatedAt);
	}

	public function test_rejects_non_organic_foreign_hosts_invalid_ranks_and_keeps_best_duplicate(): void
	{
		$keyword = static fn (string $text): array => DataForSeoFakes::labsKeyword($text, 100);
		$this->http->pushJson(200, DataForSeoFakes::rankedResult('konkurent.pl', [
			DataForSeoFakes::rankedItem($keyword('sklep'), 2, 'konkurent.pl', type: 'paid'),
			DataForSeoFakes::rankedItem($keyword('blog'), 3, 'notkonkurent.pl'),
			DataForSeoFakes::rankedItem($keyword('blog 2'), 3, 'konkurent.pl.evil.example'),
			DataForSeoFakes::rankedItem($keyword('oferta'), 0, 'konkurent.pl'),
			DataForSeoFakes::rankedItem($keyword('Strony WWW'), 9, 'blog.konkurent.pl', 'https://blog.konkurent.pl/a/'),
			DataForSeoFakes::rankedItem($keyword('strony  www'), 5, 'konkurent.pl', 'https://konkurent.pl/b/'),
			['se_type' => 'google'],
		], 7));

		$batch = $this->provider->rankedKeywords($this->poland, new RankedKeywordsQuery('konkurent.pl', 100, 0, 30, 0));

		self::assertSame(7, $batch->received, 'Paginacja liczy wszystkie zwrócone elementy.');
		self::assertCount(1, $batch->items);
		self::assertSame(5, $batch->items[0]->rankGroup, 'Ta sama fraza (klucz rynkowy) — najlepszy wynik domeny.');
		self::assertSame('https://konkurent.pl/b/', $batch->items[0]->url);
		self::assertSame(['not_organic' => 1, 'foreign_host' => 2, 'invalid_rank' => 1, 'duplicate' => 1, 'invalid' => 1], $batch->skipped);
	}

	public function test_subdomain_result_belongs_to_domain_family(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::rankedResult('konkurent.pl', [
			DataForSeoFakes::rankedItem(DataForSeoFakes::labsKeyword('poradnik seo', 50), 7, 'blog.konkurent.pl', 'https://blog.konkurent.pl/poradnik/'),
		]));

		$batch = $this->provider->rankedKeywords($this->poland, new RankedKeywordsQuery('konkurent.pl', 100, 0, 30, 0));

		self::assertSame('blog.konkurent.pl', $batch->items[0]->host);
	}

	public function test_no_results_status_is_an_empty_batch_with_cost(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::envelope(['status_code' => 40102, 'status_message' => 'No Search Results.', 'cost' => 0.012], 20000, 0.012));

		$batch = $this->provider->rankedKeywords($this->poland, new RankedKeywordsQuery('konkurent.pl', 100, 0, 30, 10));

		self::assertSame([], $batch->items);
		self::assertSame(0, $batch->totalCount);
		self::assertSame(0.012, $batch->cost);
	}

	public function test_task_error_is_a_categorized_provider_exception(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::envelope(['status_code' => 40200, 'status_message' => 'Payment Required.'], 20000));

		try {
			$this->provider->rankedKeywords($this->poland, new RankedKeywordsQuery('konkurent.pl', 100, 0, 30, 10));
			self::fail('Oczekiwano wyjątku.');
		} catch (ProviderException $exception) {
			self::assertSame(ProviderErrorCategory::Billing, $exception->category());
		}
	}

	public function test_malformed_result_is_rejected(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::envelope(['status_code' => 20000, 'result' => ['items' => 'x']], 20000));

		$this->expectException(ProviderException::class);

		$this->provider->rankedKeywords($this->poland, new RankedKeywordsQuery('konkurent.pl', 100, 0, 30, 10));
	}

	/**
	 * @return iterable<string, array{RankedKeywordsQuery}>
	 */
	public static function invalidQueries(): iterable
	{
		yield 'limit 0' => [new RankedKeywordsQuery('konkurent.pl', 0, 0, 30, 10)];
		yield 'limit above page' => [new RankedKeywordsQuery('konkurent.pl', 1001, 0, 30, 10)];
		yield 'negative offset' => [new RankedKeywordsQuery('konkurent.pl', 100, -1, 30, 10)];
		yield 'beyond safe pagination' => [new RankedKeywordsQuery('konkurent.pl', 1000, 9001, 30, 10)];
		yield 'offset 10000' => [new RankedKeywordsQuery('konkurent.pl', 1, 10000, 30, 10)];
		yield 'not normalized domain' => [new RankedKeywordsQuery('https://www.konkurent.pl/', 100, 0, 30, 10)];
	}

	#[DataProvider('invalidQueries')]
	public function test_invalid_queries_never_reach_the_api(RankedKeywordsQuery $query): void
	{
		try {
			$this->provider->rankedKeywords($this->poland, $query);
			self::fail('Oczekiwano wyjątku.');
		} catch (InvalidArgumentException) {
		}

		self::assertSame([], $this->http->requests);
	}

	public function test_last_safe_page_is_accepted(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::rankedResult('konkurent.pl', []));

		$this->provider->rankedKeywords($this->poland, new RankedKeywordsQuery('konkurent.pl', 1000, 9000, 30, 10));

		self::assertCount(1, $this->http->requests);
		self::assertSame(10000, $this->provider->maxRowsPerDomain());
	}

	public function test_cost_estimate_is_request_plus_items_and_prices_can_be_overridden(): void
	{
		self::assertSame(0.132, $this->provider->estimateCost(1000));
		self::assertSame(0.012, $this->provider->estimateCost(0));

		putenv(DataForSeoConfig::PRICE_GAP_REQUEST . '=0.02');
		putenv(DataForSeoConfig::PRICE_GAP_ITEM . '=0.0002');

		self::assertSame(0.22, $this->provider->estimateCost(1000));
	}
}
