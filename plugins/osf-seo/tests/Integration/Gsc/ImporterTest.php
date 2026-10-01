<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Gsc;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Google\GoogleConnection;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Gsc\Dictionary;
use OsfSeo\Gsc\ErrorCategory;
use OsfSeo\Gsc\GscApiException;
use OsfSeo\Gsc\GscImporter;
use OsfSeo\Gsc\ImportAborted;
use OsfSeo\Support\DateRange;

final class ImporterTest extends GscTestCase
{
	private GscImporter $importer;

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables('gsc_import_staging');

		$this->importer = new GscImporter($this->gsc, self::db(), $this->projects, new Dictionary(self::db(), $this->clock), $this->captureLogger());
	}

	private function connection(ProjectContext $context): GoogleConnection
	{
		return $this->connections->find($context->project()->connectionId);
	}

	private function import(ProjectContext $context, Dataset $dataset, string $start, string $end, int $stagingId = 1001): \OsfSeo\Gsc\ImportResult
	{
		return $this->importer->import($context, $this->connection($context), $dataset, new DateRange($start, $end), $stagingId, 'sc-domain:example.pl');
	}

	/**
	 * @return list<array<string, string|null>>
	 */
	private static function facts(string $table, int $projectId, string $columns = '*', string $order = '1, 2, 3'): array
	{
		$db = self::db();

		return $db->fetchAll("SELECT {$columns} FROM `{$db->table($table)}` WHERE project_id = %d ORDER BY {$order}", [$projectId]);
	}

	public function test_site_totals_are_stored_with_weighted_position_components(): void
	{
		$context = $this->readyProject();
		$this->mockSearchAnalytics(static function (array $body): array {
			self::assertSame(['date'], $body['dimensions']);
			self::assertSame(25000, $body['rowLimit']);
			self::assertSame('final', $body['dataState']);

			return [self::apiRow(['2026-09-02'], 30, 1000, 7.5), self::apiRow(['2026-09-01'], 0, 0, 0.0), self::apiRow(['2026-09-03'], 12, 400, 12.25)];
		});

		$result = $this->import($context, Dataset::Site, '2026-09-01', '2026-09-03');

		self::assertSame(3, $result->rowsFetched);
		self::assertSame(3, $result->rowsWritten);
		self::assertSame(['2026-09-01', '2026-09-03'], [$result->minDate, $result->maxDate]);
		self::assertSame([
			['date' => '2026-09-01', 'device' => '0', 'clicks' => '0', 'impressions' => '0', 'position_sum' => '0'],
			['date' => '2026-09-02', 'device' => '0', 'clicks' => '30', 'impressions' => '1000', 'position_sum' => '7500'],
			['date' => '2026-09-03', 'device' => '0', 'clicks' => '12', 'impressions' => '400', 'position_sum' => '4900'],
		], self::facts('gsc_site_daily', $context->projectId(), 'date, device, clicks, impressions, position_sum'));
		self::assertSame(0, (int) self::db()->fetchValue('SELECT COUNT(*) FROM `' . self::db()->table('gsc_import_staging') . '`'), 'Staging wyczyszczony.');
	}

	public function test_query_import_keeps_exact_query_strings_and_deduplicates_by_identity(): void
	{
		$context = $this->readyProject();
		$long = str_repeat('długa fraza ', 60);
		$queries = ['Buty', 'buty', 'buty ', '100% bawełna', "o'reilly \"cytat\" \\ ukośnik", 'zażółć 🦊', $long];
		$this->mockSearchAnalytics(static function (array $body) use ($queries): array {
			$rows = [];

			foreach (['2026-09-01', '2026-09-02'] as $date) {
				foreach ($queries as $i => $query) {
					$rows[] = self::apiRow([$date, $query], $i, 10 + $i, 1.5 + $i);
				}
			}

			return $rows;
		});

		$result = $this->import($context, Dataset::Query, '2026-09-01', '2026-09-02');

		self::assertSame(14, $result->rowsWritten);
		self::assertSame(7, $result->keywordsCreated);
		$db = self::db();
		$stored = $db->fetchAll("SELECT keyword, LOWER(HEX(keyword_hash)) AS h, first_seen, last_seen FROM `{$db->table('keywords')}` WHERE project_id = %d ORDER BY id", [$context->projectId()]);

		self::assertSame(array_slice($queries, 0, 6), array_slice(array_column($stored, 'keyword'), 0, 6), 'Frazy bez zmiany wielkości liter, spacji i znaków.');
		self::assertSame(mb_substr($long, 0, 500), $stored[6]['keyword'], 'Długa fraza skrócona w kolumnie.');
		self::assertSame(md5($long), $stored[6]['h'], 'Tożsamość z pełnej frazy.');
		self::assertSame(['2026-09-01', '2026-09-02'], [$stored[0]['first_seen'], $stored[0]['last_seen']]);

		$daily = $db->fetchRow("SELECT clicks, impressions, position_sum FROM `{$db->table('gsc_query_daily')}` q JOIN `{$db->table('keywords')}` k ON k.id = q.keyword_id WHERE q.project_id = %d AND q.date = '2026-09-02' AND k.keyword = BINARY %s", [$context->projectId(), '100% bawełna']);
		self::assertSame(['clicks' => '3', 'impressions' => '13', 'position_sum' => '58.5'], $daily);
	}

	public function test_importing_the_same_range_twice_is_idempotent(): void
	{
		$context = $this->readyProject();
		$this->mockSearchAnalytics(static fn (array $body): array => [
			self::apiRow([$body['startDate'], 'alpha'], 5, 50, 3.0),
			self::apiRow([$body['startDate'], 'beta'], 1, 20, 9.0),
			self::apiRow([$body['endDate'], 'alpha'], 2, 30, 4.0),
		]);

		$first = $this->import($context, Dataset::Query, '2026-09-01', '2026-09-07');
		$snapshot = [self::facts('gsc_query_daily', $context->projectId()), self::facts('keywords', $context->projectId(), 'id, keyword', 'id')];
		$second = $this->import($context, Dataset::Query, '2026-09-01', '2026-09-07', 1002);

		self::assertSame([3, 3], [$first->rowsWritten, $second->rowsWritten]);
		self::assertSame(3, $second->rowsReplaced);
		self::assertSame(0, $second->keywordsCreated);
		self::assertSame($snapshot, [self::facts('gsc_query_daily', $context->projectId()), self::facts('keywords', $context->projectId(), 'id, keyword', 'id')]);

		// Powtórny import nie zużywa wartości AUTO_INCREMENT (słownik nie robi INSERT dla istniejących fraz).
		$maxId = (int) self::db()->fetchValue('SELECT MAX(id) FROM `' . self::db()->table('keywords') . '`');
		$this->mockSearchAnalytics(static fn (array $body): array => [self::apiRow([$body['startDate'], 'gamma'], 1, 1, 1.0)]);
		$this->import($context, Dataset::Query, '2026-09-08', '2026-09-08', 1003);
		self::assertSame($maxId + 1, (int) self::db()->fetchValue('SELECT id FROM `' . self::db()->table('keywords') . "` WHERE keyword = 'gamma'"));
	}

	public function test_import_replaces_only_its_date_range(): void
	{
		$context = $this->readyProject();
		$this->mockSearchAnalytics(static fn (array $body): array => [
			self::apiRow(['2026-09-01', 'stara'], 1, 10, 2.0),
			self::apiRow(['2026-09-02', 'stara'], 1, 10, 2.0),
			self::apiRow(['2026-09-03', 'stara'], 1, 10, 2.0),
		]);
		$this->import($context, Dataset::Query, '2026-09-01', '2026-09-03');

		// Google po dopracowaniu danych zwraca dla 2026-09-02 inną frazę; poza zakresem nic się nie zmienia.
		$this->mockSearchAnalytics(static fn (array $body): array => [self::apiRow(['2026-09-02', 'nowa'], 4, 40, 6.0)]);
		$result = $this->import($context, Dataset::Query, '2026-09-02', '2026-09-02', 1002);

		self::assertSame(1, $result->rowsReplaced);
		$rows = self::db()->fetchAll('SELECT q.date, k.keyword FROM `' . self::db()->table('gsc_query_daily') . '` q JOIN `' . self::db()->table('keywords') . '` k ON k.id = q.keyword_id WHERE q.project_id = %d ORDER BY q.date', [$context->projectId()]);
		self::assertSame([['date' => '2026-09-01', 'keyword' => 'stara'], ['date' => '2026-09-02', 'keyword' => 'nowa'], ['date' => '2026-09-03', 'keyword' => 'stara']], $rows);
	}

	public function test_failed_google_request_on_a_later_page_keeps_previous_data(): void
	{
		$context = $this->readyProject();
		$this->mockSearchAnalytics(static fn (array $body): array => [self::apiRow(['2026-09-01', 'dobra'], 5, 50, 3.0)]);
		$this->import($context, Dataset::Query, '2026-09-01', '2026-09-01');
		$before = self::facts('gsc_query_daily', $context->projectId());

		$url = self::queryUrl();
		$this->google->always($url, static function (array $request): array {
			$body = json_decode($request['body'], true);

			if ($body['startRow'] === 0) {
				return ['status' => 200, 'json' => ['rows' => array_map(static fn (int $i): array => self::apiRow(['2026-09-01', 'fraza ' . $i], 1, 2, 3.0), range(1, 25000))]];
			}

			return ['status' => 503, 'json' => ['error' => ['code' => 503, 'status' => 'UNAVAILABLE']]];
		});

		try {
			$this->import($context, Dataset::Query, '2026-09-01', '2026-09-01', 1002);
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::Transient, $exception->category());
		}

		self::assertSame($before, self::facts('gsc_query_daily', $context->projectId()), 'Dobre dane nietknięte.');
		self::assertSame(0, (int) self::db()->fetchValue('SELECT COUNT(*) FROM `' . self::db()->table('gsc_import_staging') . '`'), 'Staging wyczyszczony po błędzie.');
	}

	public function test_malformed_response_keeps_previous_data(): void
	{
		$context = $this->readyProject();
		$this->mockSearchAnalytics(static fn (array $body): array => [self::apiRow(['2026-09-01'], 5, 50, 3.0)]);
		$this->import($context, Dataset::Site, '2026-09-01', '2026-09-01');
		$before = self::facts('gsc_site_daily', $context->projectId());

		$this->mockSearchAnalytics(static fn (array $body): array => [self::apiRow(['2026-09-01'], 5, 50, 3.0), ['keys' => ['2026-09-01'], 'clicks' => 'x', 'impressions' => 1, 'position' => 1]]);

		try {
			$this->import($context, Dataset::Site, '2026-09-01', '2026-09-01', 1002);
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::Malformed, $exception->category());
		}

		self::assertSame($before, self::facts('gsc_site_daily', $context->projectId()));
	}

	public function test_duplicate_keys_across_pages_fail_without_writing(): void
	{
		$context = $this->readyProject();
		$this->mockSearchAnalytics(static fn (array $body): array => [...array_map(static fn (int $i): array => self::apiRow(['2026-09-01', 'fraza ' . $i], 1, 2, 3.0), range(1, 25000)), self::apiRow(['2026-09-01', 'fraza 7'], 1, 2, 3.0)]);

		try {
			$this->import($context, Dataset::Query, '2026-09-01', '2026-09-01');
			self::fail('Expected pagination error.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::Pagination, $exception->category());
		}

		self::assertSame(0, self::rowCount('gsc_query_daily', $context->projectId()));
	}

	public function test_pagination_imports_all_pages_in_bounded_batches(): void
	{
		$context = $this->readyProject();
		$rows = [];

		foreach (['2026-09-01', '2026-09-02'] as $date) {
			for ($i = 1; $i <= 25005; $i++) {
				$rows[] = self::apiRow([$date, 'fraza ' . $i], $i % 3, 3 + $i % 7, 1.0 + ($i % 90));
			}
		}

		$this->mockSearchAnalytics(static fn (array $body): array => $rows);

		$result = $this->import($context, Dataset::Query, '2026-09-01', '2026-09-02');

		self::assertSame(3, $result->pages);
		self::assertSame([0, 25000, 50000], array_map(static fn (array $r): int => json_decode($r['body'], true)['startRow'], $this->google->requestsTo(self::queryUrl())));
		self::assertSame(50010, $result->rowsFetched);
		self::assertSame(50010, $result->rowsWritten);
		self::assertSame(50010, self::rowCount('gsc_query_daily', $context->projectId()));
		self::assertSame(25005, $result->keywordsCreated);

		$expected = array_sum(array_map(static fn (array $row): float => $row['position'] * $row['impressions'], $rows));
		$stored = (float) self::db()->fetchValue('SELECT SUM(position_sum) FROM `' . self::db()->table('gsc_query_daily') . '` WHERE project_id = %d', [$context->projectId()]);
		self::assertEqualsWithDelta($expected, $stored, 0.01);
	}

	public function test_property_change_during_import_aborts_commit(): void
	{
		$context = $this->readyProject();
		$this->mockSearchAnalytics(function (array $body) use ($context): array {
			// W trakcie pobierania użytkownik zmienia property (np. reset w drugiej karcie).
			$this->projects->setProperty($context->projectId(), 'https://www.example.pl/', 'siteOwner', 'https://www.example.pl/');

			return [self::apiRow(['2026-09-01', 'fraza'], 1, 2, 3.0)];
		});

		try {
			$this->import($context, Dataset::Query, '2026-09-01', '2026-09-01');
			self::fail('Expected ImportAborted.');
		} catch (ImportAborted $exception) {
			self::assertSame(ImportAborted::PROPERTY_CHANGED, $exception->reason());
		}

		self::assertSame(0, self::rowCount('gsc_query_daily', $context->projectId()));
	}

	public function test_database_error_during_commit_rolls_back_the_range_replacement(): void
	{
		$context = $this->readyProject();
		$this->mockSearchAnalytics(static fn (array $body): array => [self::apiRow(['2026-09-01', 'dobra'], 5, 50, 3.0)]);
		$this->import($context, Dataset::Query, '2026-09-01', '2026-09-01');
		$before = self::facts('gsc_query_daily', $context->projectId());
		$db = self::db();
		$trigger = $db->prefix() . 'osf_test_fail_insert';
		$db->execute("DROP TRIGGER IF EXISTS `{$trigger}`");
		$db->execute("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$db->table('gsc_query_daily')}` FOR EACH ROW BEGIN IF NEW.clicks = 4242 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'; END IF; END");

		try {
			$this->mockSearchAnalytics(static fn (array $body): array => [self::apiRow(['2026-09-01', 'nowa'], 1, 2, 3.0), self::apiRow(['2026-09-01', 'zła'], 4242, 5000, 3.0)]);
			$this->import($context, Dataset::Query, '2026-09-01', '2026-09-01', 1002);
			self::fail('Expected DatabaseException.');
		} catch (\OsfSeo\Database\DatabaseException $exception) {
			self::assertStringContainsString('test failure', $exception->getMessage());
		} finally {
			$db->execute("DROP TRIGGER IF EXISTS `{$trigger}`");
		}

		self::assertSame($before, self::facts('gsc_query_daily', $context->projectId()), 'DELETE zakresu wycofany razem z INSERT.');
	}

	public function test_query_page_import_stores_exact_urls(): void
	{
		$context = $this->readyProject();
		$this->mockSearchAnalytics(static function (array $body): array {
			self::assertSame(['date', 'query', 'page'], $body['dimensions']);

			return [
				self::apiRow(['2026-09-01', 'buty', 'https://example.pl/Buty/?kolor=czerwony'], 3, 30, 2.0),
				self::apiRow(['2026-09-01', 'buty', 'https://example.pl/buty/'], 1, 10, 6.0),
				self::apiRow(['2026-09-01', 'sandały', 'https://example.pl/buty/'], 0, 5, 12.0),
			];
		});

		$result = $this->import($context, Dataset::QueryPage, '2026-09-01', '2026-09-01');

		self::assertSame([3, 2, 2], [$result->rowsWritten, $result->keywordsCreated, $result->pagesCreated]);
		$pages = self::db()->fetchAll('SELECT url, path FROM `' . self::db()->table('pages') . '` WHERE project_id = %d ORDER BY id', [$context->projectId()]);
		self::assertSame([['url' => 'https://example.pl/Buty/?kolor=czerwony', 'path' => '/Buty/?kolor=czerwony'], ['url' => 'https://example.pl/buty/', 'path' => '/buty/']], $pages);
	}

	public function test_site_totals_are_not_derived_from_query_rows(): void
	{
		$context = $this->readyProject();
		// Suma property (z zanonimizowanymi zapytaniami) jest większa niż suma widocznych fraz — to oczekiwane.
		$this->mockSearchAnalytics(static fn (array $body): array => $body['dimensions'] === ['date']
			? [self::apiRow(['2026-09-01'], 100, 5000, 8.0)]
			: [self::apiRow(['2026-09-01', 'a'], 40, 1000, 3.0), self::apiRow(['2026-09-01', 'b'], 10, 500, 9.0)]);

		$this->import($context, Dataset::Site, '2026-09-01', '2026-09-01');
		$this->import($context, Dataset::Query, '2026-09-01', '2026-09-01', 1002);

		$db = self::db();
		self::assertSame(['100', '5000'], array_values($db->fetchRow("SELECT clicks, impressions FROM `{$db->table('gsc_site_daily')}` WHERE project_id = %d", [$context->projectId()])));
		self::assertSame(['50', '1500'], array_values($db->fetchRow("SELECT SUM(clicks), SUM(impressions) FROM `{$db->table('gsc_query_daily')}` WHERE project_id = %d", [$context->projectId()])));
	}

	public function test_imports_are_isolated_between_projects(): void
	{
		$a = $this->readyProject('example.pl');
		$b = $this->readyProject('example.pl');
		$this->mockSearchAnalytics(static fn (array $body): array => [self::apiRow(['2026-09-01', 'wspólna'], 1, 10, 2.0)]);

		$this->import($a, Dataset::Query, '2026-09-01', '2026-09-01');
		$this->import($b, Dataset::Query, '2026-09-01', '2026-09-01', 1002);
		$this->mockSearchAnalytics(static fn (array $body): array => []);
		$this->import($a, Dataset::Query, '2026-09-01', '2026-09-01', 1003);

		self::assertSame(0, self::rowCount('gsc_query_daily', $a->projectId()));
		self::assertSame(1, self::rowCount('gsc_query_daily', $b->projectId()), 'Zamiana zakresu projektu A nie dotyka projektu B.');
		self::assertSame(1, self::rowCount('keywords', $b->projectId()), 'Słowniki są per projekt.');
	}
}
