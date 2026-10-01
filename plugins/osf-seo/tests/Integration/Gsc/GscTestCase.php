<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Gsc;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Google\GoogleApi;
use OsfSeo\Gsc\GscClient;
use OsfSeo\Gsc\GscDataStore;
use OsfSeo\Gsc\PropertyService;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Tests\Integration\Google\GoogleTestCase;
use OsfSeo\Tests\Support\GoogleFakes;
use OsfSeo\Tests\Support\RecordingSleeper;

/**
 * Baza testów Search Console: prawdziwy WordPress i baza, ruch do Google przez atrapę `pre_http_request`.
 */
abstract class GscTestCase extends GoogleTestCase
{
	protected const SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';

	protected RecordingSleeper $sleeper;

	protected GscClient $gsc;

	protected GscDataStore $dataStore;

	protected PropertyService $properties;

	protected function setUp(): void
	{
		parent::setUp();

		self::freshTables(...[...GscDataStore::DATA_TABLES, ...GscDataStore::STATE_TABLES, 'sync_runs']);

		$this->sleeper = new RecordingSleeper();
		$logger = $this->captureLogger();
		$this->gsc = new GscClient(new GoogleApi($this->tokens, new WpHttpTransport()), $this->sleeper, $logger);
		$this->dataStore = new GscDataStore(self::db());
		$this->properties = new PropertyService($this->gsc, $this->connections, $this->projects, $this->dataStore, self::db(), $logger);

		// Każde odświeżenie access tokenu dostaje świeży (fałszywy) token.
		$this->google->always(self::TOKEN_URL, fn (): array => ['status' => 200, 'json' => ['access_token' => GoogleFakes::accessToken(), 'expires_in' => 3599]]);
	}

	/**
	 * Projekt administratora połączony z kontem Google (połączenie zapisane bezpośrednio).
	 */
	protected function connectedProject(string $domain = 'example.pl', ?int $owner = null): ProjectContext
	{
		$owner ??= $this->createUser('administrator');
		$context = $this->service->create(['name' => 'Projekt ' . $domain, 'domain' => $domain], $owner);
		$connection = $this->storeConnection($owner, GoogleFakes::refreshToken(), '1000' . random_int(100000, 999999));
		$this->projects->setConnection($context->projectId(), $connection->id);

		return $context->withProject($this->projects->reload($context->project()));
	}

	/**
	 * @param list<array{0: string, 1: string}> $sites [siteUrl, permissionLevel]
	 */
	protected function mockSites(array $sites): void
	{
		$this->google->json(self::SITES_URL, 200, $sites === [] ? [] : ['siteEntry' => array_map(
			static fn (array $site): array => ['siteUrl' => $site[0], 'permissionLevel' => $site[1]],
			$sites,
		)]);
	}

	/** Wiersze danych GSC projektu (fakty + słownik) — do sprawdzania resetu. */
	protected function seedGscData(ProjectContext $context, string $date = '2026-09-01'): void
	{
		$db = self::db();
		$projectId = $context->projectId();
		$keywordId = $db->insert($db->table('keywords'), ['project_id' => $projectId, 'keyword' => 'fraza ' . $projectId, 'keyword_hash' => md5('fraza ' . $projectId, true), 'created_at' => '2026-09-02 00:00:00']);
		$db->insert($db->table('gsc_site_daily'), ['project_id' => $projectId, 'date' => $date, 'device' => 0, 'clicks' => 10, 'impressions' => 100, 'position_sum' => 500.0]);
		$db->insert($db->table('gsc_query_daily'), ['project_id' => $projectId, 'date' => $date, 'keyword_id' => $keywordId, 'clicks' => 5, 'impressions' => 50, 'position_sum' => 150.0]);
	}

	protected static function queryUrl(string $siteUrl = 'sc-domain:example.pl'): string
	{
		return 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
	}

	/**
	 * Atrapa searchAnalytics.query: $rows(body) zwraca pełną listę wierszy dla żądania,
	 * atrapa tnie ją według startRow/rowLimit (jak Google). Zwraca licznik żądań.
	 *
	 * @param \Closure(array<string, mixed>): list<array<string, mixed>> $rows
	 */
	protected function mockSearchAnalytics(\Closure $rows, string $siteUrl = 'sc-domain:example.pl'): void
	{
		$this->google->always(self::queryUrl($siteUrl), static function (array $request) use ($rows): array {
			$body = json_decode($request['body'], true);
			$page = array_slice($rows($body), (int) $body['startRow'], (int) $body['rowLimit']);

			return ['status' => 200, 'json' => $page === [] ? ['responseAggregationType' => 'byProperty'] : ['rows' => $page, 'responseAggregationType' => 'byProperty']];
		});
	}

	/**
	 * Wiersz API: klucze + metryki.
	 *
	 * @param list<string> $keys
	 * @return array<string, mixed>
	 */
	protected static function apiRow(array $keys, int $clicks, int $impressions, float $position): array
	{
		return ['keys' => $keys, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions > 0 ? $clicks / $impressions : 0, 'position' => $position];
	}

	/** Projekt gotowy do importu: połączenie + wybrana property sc-domain:example.pl. */
	protected function readyProject(string $domain = 'example.pl'): ProjectContext
	{
		$context = $this->connectedProject($domain);
		$this->mockSites([['sc-domain:' . $domain, 'siteOwner']]);

		return $this->properties->select($context, 'sc-domain:' . $domain);
	}

	protected static function rowCount(string $table, int $projectId): int
	{
		$db = self::db();

		return (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}` WHERE project_id = %d", [$projectId]);
	}
}
