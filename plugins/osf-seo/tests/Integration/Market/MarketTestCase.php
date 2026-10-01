<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Market;

use OsfSeo\Analytics\KeywordReport;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoProvider;
use OsfSeo\Gsc\Dictionary;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Market\MarketCandidateSelector;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\MarketSyncStateRepository;
use OsfSeo\Market\MarketTaskRepository;
use OsfSeo\Support\Config;
use OsfSeo\Tests\Integration\Gsc\GscTestCase;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Baza testów danych rynkowych: prawdziwy WordPress i baza, DataForSEO przez atrapę `pre_http_request`
 * (żaden request nie wychodzi do sieci), syntetyczne dane logowania ze zmiennych środowiskowych.
 */
abstract class MarketTestCase extends GscTestCase
{
	protected const VOLUME_POST = 'https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/task_post';

	protected const VOLUME_GET = 'https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/task_get/';

	protected const DIFFICULTY = 'https://api.dataforseo.com/v3/dataforseo_labs/google/bulk_keyword_difficulty/live';

	protected const MARKET_TABLES = ['market_keywords', 'market_keyword_monthly', 'market_tasks', 'market_sync_state'];

	protected const ENV = [
		MarketDataConfig::MAX_TASKS_PER_RUN,
		MarketDataConfig::DAILY_COST_LIMIT,
		MarketDataConfig::MONTHLY_COST_LIMIT,
		MarketDataConfig::AUTO_REFRESH,
		MarketDataConfig::MIN_IMPRESSIONS,
	];

	protected DataForSeoProvider $provider;

	protected MarketMetricsRepository $marketMetrics;

	protected MarketTaskRepository $tasks;

	protected MarketSyncStateRepository $states;

	protected KeywordReport $report;

	protected MarketSyncService $market;

	protected string $login = '';

	protected string $password = '';

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables(...self::MARKET_TABLES);
		[$this->login, $this->password] = DataForSeoFakes::configure();
		$this->resetMarketOptions();
		$this->buildServices();
	}

	protected function tearDown(): void
	{
		DataForSeoFakes::clear();

		foreach (self::ENV as $name) {
			putenv($name);
		}

		$this->resetMarketOptions();
		remove_all_filters('wp_doing_cron');
		parent::tearDown();
	}

	protected function buildServices(): void
	{
		$db = self::db();
		$logger = $this->captureLogger();
		$config = new DataForSeoConfig();
		$this->provider = new DataForSeoProvider(new DataForSeoClient($config, new WpHttpTransport(), $this->sleeper, $logger), $config);
		$this->marketMetrics = new MarketMetricsRepository($db, $this->clock);
		$this->tasks = new MarketTaskRepository($db, $this->clock);
		$this->states = new MarketSyncStateRepository($db, $this->clock);
		$this->report = new KeywordReport($db, new Config(), $this->provider, $this->marketMetrics);
		$this->market = new MarketSyncService(
			$this->provider,
			$this->marketMetrics,
			$this->tasks,
			$this->states,
			new MarketCandidateSelector($db, $this->report),
			new MarketKeyBackfill($db),
			new MarketDataConfig(),
			$this->guard,
			$db,
			$this->clock,
			$logger,
		);
	}

	protected function resetMarketOptions(): void
	{
		delete_option(MarketSyncService::PAUSE_OPTION);
		$db = self::db();
		$db->execute("DELETE FROM `{$db->optionsTable()}` WHERE option_name LIKE %s", ['%osf_seo_market_%']);
		wp_cache_flush();
	}

	/**
	 * Projekt z frazami GSC (ostatnia data 2026-01-14): [fraza, wyświetlenia, kliknięcia] — wiersze z jednego dnia.
	 *
	 * @param list<array{0: string, 1: int, 2: int}> $keywords
	 */
	protected function projectWithKeywords(string $domain = 'example.pl', ?array $keywords = null, string $country = 'pl', string $language = 'pl'): ProjectContext
	{
		$context = $this->readyProject($domain);

		if ($country !== 'pl' || $language !== 'pl') {
			$this->projects->update($context->projectId(), ['country' => $country, 'language' => $language]);
			$context = $context->withProject($this->projects->reload($context->project()));
		}

		$this->seedKeywords($context, $keywords ?? self::defaultKeywords());

		return $context;
	}

	/**
	 * @param list<array{0: string, 1: int, 2: int}> $keywords
	 */
	protected function seedKeywords(ProjectContext $context, array $keywords, string $date = '2026-01-14'): void
	{
		$db = self::db();
		$ids = (new Dictionary($db, $this->clock))->keywordIds($context->projectId(), array_column($keywords, 0));

		foreach ($keywords as [$keyword, $impressions, $clicks]) {
			$db->execute(
				"INSERT INTO `{$db->table('gsc_query_daily')}` (project_id, date, keyword_id, clicks, impressions, position_sum) VALUES (%d, %s, %d, %d, %d, %f)
				ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), position_sum = VALUES(position_sum)",
				[$context->projectId(), $date, $ids[$keyword], $clicks, $impressions, 8.0 * $impressions],
			);
		}
	}

	/**
	 * Wybór: „Buty Damskie” i „buty damskie” = jedna fraza rynkowa, pytajnik niedozwolony, „rzadka fraza” poniżej progu 50.
	 *
	 * @return list<array{0: string, 1: int, 2: int}>
	 */
	protected static function defaultKeywords(): array
	{
		return [
			['Buty Damskie', 500, 40],
			['jak zrobić stronę?', 400, 10],
			['żółte buty', 300, 20],
			['kurs c++', 200, 5],
			['sklep internetowy', 80, 2],
			['buty damskie', 60, 1],
			['rzadka fraza', 10, 0],
		];
	}

	/** Atrapa task_post (Standard): zwraca identyfikator zadania; zapisuje treść żądania. */
	protected function mockVolumePost(string $taskId, float $cost = 0.06): void
	{
		$this->google->json(self::VOLUME_POST, 200, DataForSeoFakes::taskCreated($taskId, $cost));
	}

	/**
	 * @param array<string, ?int> $difficulties
	 */
	protected function mockDifficulty(array $difficulties, float $cost = 0.0125): void
	{
		$this->google->json(self::DIFFICULTY, 200, DataForSeoFakes::difficultyResult($difficulties, $cost));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function dataForSeoRequests(): array
	{
		return array_values(array_filter($this->google->requests, static fn (array $request): bool => str_starts_with($request['url'], 'https://api.dataforseo.com/')));
	}

	/**
	 * @return list<string>
	 */
	protected static function requestKeywords(array $request): array
	{
		return json_decode($request['body'], true)[0]['keywords'];
	}

	protected function systemContext(ProjectContext $context): ProjectContext
	{
		add_filter('wp_doing_cron', '__return_true');

		return $this->guard->authorizeSystem($context->publicId());
	}

	/**
	 * @return array<string, string|null>|null
	 */
	protected function marketRow(string $keyword, int $locationCode = 2616, string $languageCode = 'pl'): ?array
	{
		$db = self::db();

		return $db->fetchRow(
			"SELECT * FROM `{$db->table('market_keywords')}` WHERE provider = 'dataforseo' AND location_code = %d AND language_code = %s AND keyword_key = UNHEX(%s)",
			[$locationCode, $languageCode, md5($keyword)],
		);
	}

	protected static function tableCount(string $table, string $where = '1 = 1'): int
	{
		$db = self::db();

		return (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}` WHERE {$where}");
	}
}
