<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Gsc;

use InvalidArgumentException;
use OsfSeo\Gsc\ErrorCategory;
use OsfSeo\Gsc\GscApiException;
use OsfSeo\Gsc\GscClient;
use OsfSeo\Gsc\SearchAnalyticsRequest;
use OsfSeo\Support\DateRange;
use OsfSeo\Support\Logger;
use OsfSeo\Tests\Support\FakeApiRequester;
use OsfSeo\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchAnalyticsTest extends TestCase
{
	private const QUERY_URL = 'https://www.googleapis.com/webmasters/v3/sites/sc-domain%3Aexample.pl/searchAnalytics/query';

	private FakeApiRequester $api;

	private RecordingSleeper $sleeper;

	private GscClient $client;

	protected function setUp(): void
	{
		$this->api = new FakeApiRequester();
		$this->sleeper = new RecordingSleeper();
		$this->client = new GscClient($this->api, $this->sleeper, new Logger(Logger::ERROR, static function (): void {
		}));
	}

	private static function request(array $dimensions = ['date', 'query'], int $rowLimit = 25000, string $start = '2026-09-01', string $end = '2026-09-07'): SearchAnalyticsRequest
	{
		return new SearchAnalyticsRequest(new DateRange($start, $end), $dimensions, $rowLimit);
	}

	public function test_request_body_matches_api_contract(): void
	{
		self::assertSame([
			'startDate' => '2026-09-01',
			'endDate' => '2026-09-07',
			'dimensions' => ['date', 'query'],
			'type' => 'web',
			'dataState' => 'final',
			'aggregationType' => 'auto',
			'rowLimit' => 25000,
			'startRow' => 0,
		], self::request()->toApi());
		self::assertSame(50000, self::request()->withStartRow(50000)->toApi()['startRow']);
	}

	/**
	 * @return iterable<string, array{\Closure(): mixed}>
	 */
	public static function invalidRequests(): iterable
	{
		yield 'row limit above 25000' => [static fn () => self::request(rowLimit: 25001)];
		yield 'row limit zero' => [static fn () => self::request(rowLimit: 0)];
		yield 'unknown dimension' => [static fn () => self::request(['date', 'keyword'])];
		yield 'duplicate dimension' => [static fn () => self::request(['date', 'date'])];
		yield 'negative start row' => [static fn () => self::request()->withStartRow(-1)];
		yield 'start after end' => [static fn () => self::request(start: '2026-09-08')];
		yield 'invalid date' => [static fn () => self::request(start: '2026-02-30')];
		yield 'invalid data state' => [static fn () => new SearchAnalyticsRequest(new DateRange('2026-09-01', '2026-09-01'), ['date'], 10, 0, 'fresh')];
	}

	#[DataProvider('invalidRequests')]
	public function test_invalid_requests_are_rejected(\Closure $build): void
	{
		$this->expectException(InvalidArgumentException::class);

		$build();
	}

	public function test_max_pages_is_bounded_by_google_daily_row_limit(): void
	{
		self::assertSame(15, self::request()->maxPages(), '7 dni × 50 000 / 25 000 + 1');
		self::assertSame(3, self::request(['date'], 25000, '2026-09-01', '2026-09-01')->maxPages());
		self::assertSame(400, self::request(['date', 'query'], 10, '2025-01-01', '2026-01-01')->maxPages(), 'Twardy limit.');
	}

	public function test_query_posts_to_encoded_site_url_and_parses_rows(): void
	{
		$this->api->json(200, ['rows' => [
			['keys' => ['2026-09-01', 'buty "do" biegania'], 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.035, 'position' => 4.25],
			['keys' => ['2026-09-02', 'zażółć gęślą jaźń 🦊'], 'clicks' => 0.0, 'impressions' => 7.0, 'ctr' => 0, 'position' => 31],
		], 'responseAggregationType' => 'byProperty']);

		$page = $this->client->query(FakeApiRequester::connection(), 'sc-domain:example.pl', self::request());

		self::assertSame('POST', $this->api->requests[0]['method']);
		self::assertSame(self::QUERY_URL, $this->api->requests[0]['url']);
		self::assertSame(self::request()->toApi(), $this->api->requests[0]['json']);
		self::assertSame([
			['keys' => ['2026-09-01', 'buty "do" biegania'], 'clicks' => 12, 'impressions' => 340, 'position' => 4.25],
			['keys' => ['2026-09-02', 'zażółć gęślą jaźń 🦊'], 'clicks' => 0, 'impressions' => 7, 'position' => 31.0],
		], $page->rows, 'Frazy bez normalizacji, CTR pomijany (liczony z sum).');
		self::assertSame('byProperty', $page->aggregationType);
	}

	public function test_empty_response_means_no_rows(): void
	{
		$this->api->json(200, ['responseAggregationType' => 'byProperty']);

		self::assertSame([], $this->client->query(FakeApiRequester::connection(), 'sc-domain:example.pl', self::request())->rows);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function malformedResponses(): iterable
	{
		$row = static fn (array $overrides): array => ['rows' => [$overrides + ['keys' => ['2026-09-01', 'fraza'], 'clicks' => 1, 'impressions' => 2, 'position' => 3.0]]];

		yield 'rows not a list' => [['rows' => ['a' => 1]]];
		yield 'keys count mismatch' => [$row(['keys' => ['2026-09-01']])];
		yield 'missing keys' => [['rows' => [['clicks' => 1, 'impressions' => 2, 'position' => 3]]]];
		yield 'non-string key' => [$row(['keys' => ['2026-09-01', 5]])];
		yield 'date outside range' => [$row(['keys' => ['2026-08-31', 'fraza']])];
		yield 'invalid date' => [$row(['keys' => ['2026-09-31', 'fraza']])];
		yield 'negative clicks' => [$row(['clicks' => -1])];
		yield 'fractional impressions' => [$row(['impressions' => 2.5])];
		yield 'string metrics' => [$row(['clicks' => '1'])];
		yield 'missing position' => [['rows' => [['keys' => ['2026-09-01', 'fraza'], 'clicks' => 1, 'impressions' => 2]]]];
		yield 'negative position' => [$row(['position' => -2])];
		yield 'impressions overflow' => [$row(['impressions' => 5000000000])];
	}

	/**
	 * @param array<string, mixed> $json
	 */
	#[DataProvider('malformedResponses')]
	public function test_malformed_rows_are_rejected(array $json): void
	{
		$this->api->json(200, $json);

		try {
			$this->client->query(FakeApiRequester::connection(), 'sc-domain:example.pl', self::request());
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::Malformed, $exception->category());
		}
	}

	public function test_more_rows_than_row_limit_is_malformed(): void
	{
		$this->api->json(200, ['rows' => array_fill(0, 3, ['keys' => ['2026-09-01'], 'clicks' => 1, 'impressions' => 1, 'position' => 1])]);

		$this->expectExceptionObject(GscApiException::malformed('More rows than rowLimit.'));
		$this->client->query(FakeApiRequester::connection(), 'sc-domain:example.pl', self::request(['date'], 2));
	}

	public function test_pagination_continues_until_a_short_page(): void
	{
		$this->api->json(200, ['rows' => self::rows(0, 3)])->json(200, ['rows' => self::rows(3, 3)])->json(200, ['rows' => self::rows(6, 1)]);

		$pages = iterator_to_array($this->client->pages(FakeApiRequester::connection(), 'sc-domain:example.pl', self::request(['date', 'query'], 3)), false);

		self::assertSame([3, 3, 1], array_map(static fn ($page) => $page->count(), $pages));
		self::assertSame([0, 3, 6], array_column(array_column($this->api->requests, 'json'), 'startRow'));
	}

	public function test_pagination_with_exact_multiple_ends_on_empty_page(): void
	{
		$this->api->json(200, ['rows' => self::rows(0, 3)])->json(200, ['rows' => self::rows(3, 3)])->json(200, []);

		$pages = iterator_to_array($this->client->pages(FakeApiRequester::connection(), 'sc-domain:example.pl', self::request(['date', 'query'], 3)), false);

		self::assertSame([3, 3, 0], array_map(static fn ($page) => $page->count(), $pages));
	}

	public function test_repeated_page_is_detected(): void
	{
		$this->api->json(200, ['rows' => self::rows(0, 3)])->json(200, ['rows' => self::rows(0, 3)]);

		$this->expectExceptionObject(GscApiException::pagination('Page at startRow 3 repeats the previous page.'));
		iterator_to_array($this->client->pages(FakeApiRequester::connection(), 'sc-domain:example.pl', self::request(['date', 'query'], 3)));
	}

	public function test_infinite_pagination_is_stopped(): void
	{
		$offset = 0;
		$api = new FakeApiRequester(function (string $method, string $url, ?array $json) use (&$offset): \OsfSeo\Http\HttpResponse {
			$offset += 25000;

			return new \OsfSeo\Http\HttpResponse(200, (string) json_encode(['rows' => self::rows($offset, 25000, '2026-09-01')]));
		});
		$client = new GscClient($api, $this->sleeper, new Logger(Logger::ERROR, static function (): void {
		}));

		try {
			iterator_to_array($client->pages(FakeApiRequester::connection(), 'sc-domain:example.pl', self::request(['date', 'query'], 25000, '2026-09-01', '2026-09-01')));
			self::fail('Expected pagination error.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::Pagination, $exception->category());
			self::assertTrue($exception->isRetryable());
		}

		self::assertCount(3, $api->requests, '1 dzień → maks. 50 000 wierszy (2 strony) + 1.');
	}

	public function test_query_errors_are_retried_like_other_requests(): void
	{
		$this->api->json(429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']])->json(200, ['rows' => self::rows(0, 1)]);

		self::assertSame(1, $this->client->query(FakeApiRequester::connection(), 'sc-domain:example.pl', self::request())->count());
		self::assertSame([1.0], $this->sleeper->sleeps);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function rows(int $offset, int $count, string $date = '2026-09-03'): array
	{
		$rows = [];

		for ($i = $offset; $i < $offset + $count; $i++) {
			$rows[] = ['keys' => [$date, 'fraza ' . $i], 'clicks' => 1, 'impressions' => 10 + $i, 'ctr' => 0.1, 'position' => 2.5];
		}

		return $rows;
	}
}
