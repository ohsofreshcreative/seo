<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Gsc;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Gsc\GscApiException;
use OsfSeo\Gsc\GscCalendar;
use OsfSeo\Gsc\GscNotReady;
use OsfSeo\Gsc\GscProbe;
use OsfSeo\Projects\ProjectRole;

final class ProbeTest extends GscTestCase
{
	private const QUERY_URL = 'https://www.googleapis.com/webmasters/v3/sites/sc-domain%3Aexample.pl/searchAnalytics/query';

	private GscProbe $probe;

	protected function setUp(): void
	{
		parent::setUp();

		$this->probe = new GscProbe($this->gsc, $this->connections, $this->projects, new GscCalendar($this->clock));
	}

	public function test_probe_fetches_small_sample_and_aggregates_correctly_without_writing(): void
	{
		$context = $this->readyProject();
		$this->google->on(self::QUERY_URL, function (array $request): array {
			$body = json_decode($request['body'], true);

			// Zegar testów: 2026-01-15 12:00 UTC → dziś w PT 2026-01-15; koniec domyślnie dziś − 3 dni.
			self::assertSame(['startDate' => '2026-01-06', 'endDate' => '2026-01-12', 'dimensions' => ['date'], 'type' => 'web', 'dataState' => 'final', 'aggregationType' => 'auto', 'rowLimit' => 10, 'startRow' => 0], $body);

			return ['status' => 200, 'json' => ['rows' => [
				['keys' => ['2026-01-11'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1.0, 'position' => 1.0],
				['keys' => ['2026-01-12'], 'clicks' => 0, 'impressions' => 99, 'ctr' => 0.0, 'position' => 50.0],
			], 'responseAggregationType' => 'byProperty']];
		});
		$before = self::tableCounts();

		$result = $this->probe->run($context);

		self::assertSame('sc-domain:example.pl', $result->property);
		self::assertSame(2, count($result->rows));
		self::assertSame(1, $result->clicks);
		self::assertSame(100, $result->impressions);
		self::assertSame(0.01, $result->ctr, 'CTR z sum, nie średnia CTR (50%).');
		self::assertEqualsWithDelta(49.51, $result->position, 1e-9, 'Pozycja ważona wyświetleniami, nie AVG (25,5).');
		self::assertFalse($result->limitReached());
		self::assertSame(1, $result->apiRequests);
		self::assertSame(['date' => '2026-01-11', 'clicks' => 1, 'impressions' => 1, 'ctr' => 1.0, 'position' => 1.0], $result->toArray()['sample'][0]);
		self::assertSame($before, self::tableCounts(), 'Probe niczego nie zapisuje.');
		$this->assertTokensNotLogged();
	}

	public function test_probe_options(): void
	{
		$context = $this->readyProject();
		$this->google->on(self::QUERY_URL, function (array $request): array {
			$body = json_decode($request['body'], true);
			self::assertSame(['2025-12-01', '2025-12-03', ['query', 'page'], 5, 'all'], [$body['startDate'], $body['endDate'], $body['dimensions'], $body['rowLimit'], $body['dataState']]);

			return ['status' => 200, 'json' => ['rows' => array_fill(0, 5, ['keys' => ['fraza', 'https://example.pl/'], 'clicks' => 2, 'impressions' => 4, 'ctr' => 0.5, 'position' => 3.0])]];
		});

		$result = $this->probe->run($context, ['start' => '2025-12-01', 'end' => '2025-12-03', 'dimensions' => 'query, page', 'limit' => '5', 'data_state' => 'all']);

		self::assertTrue($result->limitReached());
		self::assertSame(['query' => 'fraza', 'page' => 'https://example.pl/', 'clicks' => 2, 'impressions' => 4, 'ctr' => 0.5, 'position' => 3.0], $result->toArray()['sample'][0]);

		foreach ([['limit' => 1001], ['days' => 0], ['days' => 32], ['start' => '2026-01-10', 'end' => '2026-01-01'], ['dimensions' => 'keyword'], ['data_state' => 'fresh']] as $invalid) {
			try {
				$this->probe->run($context, $invalid);
				self::fail('Nieprawidłowe opcje: ' . json_encode($invalid));
			} catch (\InvalidArgumentException) {
			}
		}
	}

	public function test_probe_requires_ready_project_and_manager(): void
	{
		$noProperty = $this->connectedProject('example.pl');

		try {
			$this->probe->run($noProperty);
			self::fail('Expected GscNotReady.');
		} catch (GscNotReady $exception) {
			self::assertSame(GscNotReady::NO_PROPERTY, $exception->reason());
		}

		$context = $this->readyProject();
		$client = $this->createUser('osf_seo_client');
		$this->service->assignUser($context, $client, ProjectRole::Manager);

		$this->expectException(AccessDenied::class);
		$this->probe->run($this->guard->authorize($context->publicId(), $client));
	}

	public function test_probe_reports_api_errors(): void
	{
		$context = $this->readyProject();
		$this->google->json(self::QUERY_URL, 403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'errors' => [['reason' => 'forbidden']]]]);

		try {
			$this->probe->run($context);
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame('permission_denied', $exception->category()->value);
		}

		$this->assertTokensNotLogged();
	}

	/**
	 * @return array<string, int>
	 */
	private static function tableCounts(): array
	{
		$db = self::db();
		$counts = [];

		foreach (['gsc_site_daily', 'gsc_query_daily', 'gsc_query_page_daily', 'keywords', 'pages', 'sync_runs', 'sync_state'] as $table) {
			$counts[$table] = (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}`");
		}

		return $counts;
	}

	private function assertTokensNotLogged(): void
	{
		$log = implode("\n", $this->logLines);

		self::assertStringNotContainsString('ya' . '29.', $log);
		self::assertStringNotContainsString('Bearer', $log);
	}
}
