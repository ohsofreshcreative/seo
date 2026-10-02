<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Gap;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoRankedKeywordsProvider;
use OsfSeo\Discovery\DiscoverySettingsRepository;
use OsfSeo\Gap\GapConfig;
use OsfSeo\Gap\GapDomainRepository;
use OsfSeo\Gap\GapImporter;
use OsfSeo\Gap\GapPlanner;
use OsfSeo\Gap\GapRefresher;
use OsfSeo\Gap\GapReports;
use OsfSeo\Gap\GapRun;
use OsfSeo\Gap\GapRunRepository;
use OsfSeo\Gap\GapService;
use OsfSeo\Gap\GapSettingsRepository;
use OsfSeo\Gap\GapStartResult;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Serp\Competitor;
use OsfSeo\Serp\SerpDictionary;
use OsfSeo\Tests\Integration\Serp\SerpTestCase;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Baza testów Luk SEO: prawdziwy WordPress i baza, DataForSEO Labs Ranked Keywords przez atrapę `pre_http_request`
 * (filtruje i stronicuje syntetyczne frazy domen tak jak dostawca) — żaden request nie wychodzi do sieci,
 * dane logowania są syntetyczne.
 */
abstract class GapTestCase extends SerpTestCase
{
	protected const RANKED = 'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live';

	protected const GAP_TABLES = [
		'gap_domains',
		'gap_domain_keywords',
		'gap_domain_pages',
		'gap_domain_events',
		'gap_runs',
		'gap_run_targets',
		'gap_settings',
		'gap_keywords',
		'gap_clusters',
		'gap_competitor_pages',
		'discovery_settings',
		'discovery_candidates',
		'keywords',
		'pages',
		'gsc_query_daily',
		'gsc_query_page_daily',
	];

	protected const GAP_ENV = [
		GapConfig::TTL_DAYS,
		GapConfig::MAX_REQUESTS_PER_TICK,
		MarketDataConfig::DAILY_COST_LIMIT,
		MarketDataConfig::MONTHLY_COST_LIMIT,
		DataForSeoConfig::PRICE_GAP_REQUEST,
		DataForSeoConfig::PRICE_GAP_ITEM,
	];

	protected DataForSeoRankedKeywordsProvider $rankedProvider;

	protected GapService $gaps;

	protected GapRunRepository $gapRuns;

	protected GapDomainRepository $gapDomains;

	protected GapSettingsRepository $gapSettings;

	protected GapReports $gapReports;

	protected GapRefresher $refresher;

	protected DiscoverySettingsRepository $discoverySettings;

	/** @var array<string, list<array<string, mixed>>> domena → wiersze dostawcy (kolejność: wolumen malejąco, pozycja rosnąco) */
	protected array $ranked = [];

	/** @var array<string, list<array<string, mixed>|\WP_Error>> domena → kolejka odpowiedzi specjalnych (błędy, przesunięcia) */
	protected array $rankedOverrides = [];

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables(...self::GAP_TABLES);
		$this->resetGapOptions();
		$this->ranked = [];
		$this->rankedOverrides = [];
		$this->google->always(self::RANKED, function (array $request): array|\WP_Error {
			$body = json_decode($request['body'], true)[0];
			$domain = (string) $body['target'];

			if (($this->rankedOverrides[$domain] ?? []) !== []) {
				$override = array_shift($this->rankedOverrides[$domain]);

				if ($override instanceof \WP_Error || isset($override['status'])) {
					return $override;
				}
			}

			$filters = $body['filters'];
			$conditions = isset($filters[1]) && $filters[1] === 'and' ? [$filters[0], $filters[2]] : [$filters];
			$rows = array_values(array_filter($this->ranked[$domain] ?? [], static function (array $row) use ($conditions): bool {
				foreach ($conditions as [$field, $operator, $value]) {
					$actual = $field === 'ranked_serp_element.serp_item.rank_group' ? $row['ranked_serp_element']['serp_item']['rank_group'] : (int) $row['keyword_data']['keyword_info']['search_volume'];

					if (($operator === '<=' && $actual > $value) || ($operator === '>=' && $actual < $value)) {
						return false;
					}
				}

				return true;
			}));
			$page = array_slice($rows, (int) $body['offset'], (int) $body['limit']);

			return ['status' => 200, 'json' => DataForSeoFakes::rankedResult($domain, $page, count($rows), round(0.012 + count($page) * 0.00012, 6), (int) $body['offset'])];
		});
	}

	protected function tearDown(): void
	{
		foreach (self::GAP_ENV as $name) {
			putenv($name);
		}

		$this->resetGapOptions();
		parent::tearDown();
	}

	protected function buildServices(): void
	{
		parent::buildServices();
		$db = self::db();
		$logger = $this->captureLogger();
		$config = new DataForSeoConfig();
		$gapConfig = new GapConfig();
		$this->rankedProvider = new DataForSeoRankedKeywordsProvider(new DataForSeoClient($config, new WpHttpTransport(), $this->sleeper, $logger), $config);
		$this->gapRuns = new GapRunRepository($db, $this->clock);
		$this->gapDomains = new GapDomainRepository($db, $this->clock);
		$this->gapSettings = new GapSettingsRepository($db, $this->clock);
		$this->gapReports = new GapReports($db, $this->clock);
		$this->discoverySettings = new DiscoverySettingsRepository($db, $this->clock);
		$this->refresher = new GapRefresher(
			$db,
			$this->rankedProvider,
			$this->gapDomains,
			$this->gapSettings,
			$this->competitorRepository,
			$this->discoverySettings,
			new SerpDictionary($db, $this->clock),
			new MarketKeyBackfill($db),
			$gapConfig,
			$this->clock,
		);
		$this->gaps = new GapService(
			$this->rankedProvider,
			new GapPlanner($this->rankedProvider, $this->gapDomains, $this->gapRuns, $this->competitorRepository, $this->market, $this->clock),
			new GapImporter(
				$this->rankedProvider,
				$this->gapRuns,
				$this->gapDomains,
				$this->marketMetrics,
				$this->tasks,
				new SerpDictionary($db, $this->clock),
				$this->market,
				new MarketDataConfig(),
				$gapConfig,
				$this->clock,
				$logger,
			),
			$this->refresher,
			$this->gapRuns,
			$this->gapDomains,
			$this->gapSettings,
			$this->gapReports,
			$this->competitorRepository,
			$gapConfig,
			$this->market,
			new MarketDataConfig(),
			$this->guard,
			$db,
			$this->clock,
			$logger,
		);
	}

	protected function resetGapOptions(): void
	{
		$db = self::db();
		$db->execute("DELETE FROM `{$db->optionsTable()}` WHERE option_name LIKE %s", ['%osf_seo_gap_%']);
		wp_cache_flush();
	}

	/**
	 * Projekt example.pl (rynek PL/pl) z konkurentami.
	 *
	 * @param array<string, string> $competitors domena → nazwa
	 */
	protected function gapProject(array $competitors = ['konkurent.pl' => 'Konkurent'], string $domain = 'example.pl'): ProjectContext
	{
		$context = $this->readyProject($domain);

		foreach ($competitors as $competitorDomain => $name) {
			$this->competitorRepository->create($context->projectId(), $name, $competitorDomain, $context->userId());
		}

		return $context;
	}

	protected function competitor(ProjectContext $context, string $domain): Competitor
	{
		return $this->competitorRepository->findByDomain($context->projectId(), $domain) ?? throw new \RuntimeException('No competitor ' . $domain);
	}

	/**
	 * Wiersz dostawcy: fraza z metrykami i wynik domeny w migawce Labs.
	 *
	 * @return array<string, mixed>
	 */
	protected static function ranked(string $domain, string $keyword, int $volume, int $rank, ?string $path = null, ?int $kd = 30, ?string $intent = 'commercial', ?float $cpc = 2.5, ?string $core = null, bool $otherLanguage = false, ?string $title = null): array
	{
		$data = DataForSeoFakes::labsKeyword($keyword, $volume, $kd, $cpc, 0.42, $intent, $otherLanguage);
		$data['keyword_properties']['core_keyword'] = $core;
		$url = 'https://' . $domain . ($path ?? '/' . str_replace(' ', '-', $keyword) . '/');

		return DataForSeoFakes::rankedItem($data, $rank, $domain, $url, $rank + 1, $title);
	}

	/**
	 * Frazy domeny u dostawcy (sortowane jak w żądaniu: wolumen malejąco, pozycja rosnąco).
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	protected function setRanked(string $domain, array $rows): void
	{
		usort($rows, static fn (array $a, array $b): int => [(int) $b['keyword_data']['keyword_info']['search_volume'], $a['ranked_serp_element']['serp_item']['rank_group']]
			<=> [(int) $a['keyword_data']['keyword_info']['search_volume'], $b['ranked_serp_element']['serp_item']['rank_group']]);
		$this->ranked[$domain] = $rows;
	}

	/**
	 * Uruchomienie z CLI: zakolejkowanie (bez API) i wykonanie w bieżącym procesie.
	 *
	 * @param array<string, mixed> $input
	 */
	protected function gapRun(ProjectContext $context, array $input = []): GapRun
	{
		$result = $this->gaps->start($context, $this->gaps->request($context, $input), GapService::TRIGGER_CLI);
		self::assertSame(GapStartResult::QUEUED, $result->status, 'Import zakolejkowany: ' . $result->status . ' ' . ($result->reason ?? ''));
		$this->gaps->execute($context, $result->run);

		return $this->gapRuns->findById($result->run->id);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function rankedBodies(): array
	{
		return array_map(static fn (array $request): array => json_decode($request['body'], true)[0], $this->google->requestsTo(self::RANKED));
	}

	/**
	 * Fraza GSC projektu (dzień 2026-01-14) ze średnią pozycją i opcjonalnie stroną.
	 */
	protected function gscKeyword(ProjectContext $context, string $keyword, int $impressions, float $position, ?string $page = null, int $clicks = 0, string $date = '2026-01-14'): void
	{
		$db = self::db();
		$keywordId = (int) ($db->fetchValue("SELECT id FROM `{$db->table('keywords')}` WHERE project_id = %d AND keyword_hash = UNHEX(%s)", [$context->projectId(), md5($keyword)])
			?? $db->insert($db->table('keywords'), ['project_id' => $context->projectId(), 'keyword' => $keyword, 'keyword_hash' => md5($keyword, true), 'first_seen' => $date, 'last_seen' => $date, 'created_at' => $date . ' 00:00:00']));
		$db->execute(
			"INSERT INTO `{$db->table('gsc_query_daily')}` (project_id, date, keyword_id, clicks, impressions, position_sum) VALUES (%d, %s, %d, %d, %d, %f)
			ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), position_sum = VALUES(position_sum)",
			[$context->projectId(), $date, $keywordId, $clicks, $impressions, $position * $impressions],
		);

		if ($page !== null) {
			$pageId = (int) ($db->fetchValue("SELECT id FROM `{$db->table('pages')}` WHERE project_id = %d AND url_hash = UNHEX(%s)", [$context->projectId(), md5($page)])
				?? $db->insert($db->table('pages'), ['project_id' => $context->projectId(), 'url' => $page, 'url_hash' => md5($page, true), 'path' => (string) parse_url($page, PHP_URL_PATH), 'first_seen' => $date, 'last_seen' => $date, 'created_at' => $date . ' 00:00:00']));
			$db->execute(
				"INSERT INTO `{$db->table('gsc_query_page_daily')}` (project_id, date, keyword_id, page_id, clicks, impressions, position_sum) VALUES (%d, %s, %d, %d, %d, %d, %f)
				ON DUPLICATE KEY UPDATE impressions = VALUES(impressions)",
				[$context->projectId(), $date, $keywordId, $pageId, $clicks, $impressions, $position * $impressions],
			);
		}
	}

	/**
	 * Luka frazy (po tekście frazy).
	 *
	 * @return array<string, string|null>|null
	 */
	protected function gap(ProjectContext $context, string $keyword): ?array
	{
		$db = self::db();

		return $db->fetchRow(
			"SELECT g.*, m.keyword FROM `{$db->table('gap_keywords')}` g JOIN `{$db->table('market_keywords')}` m ON m.id = g.market_keyword_id
			WHERE g.project_id = %d AND m.keyword_key = UNHEX(%s)",
			[$context->projectId(), md5($keyword)],
		);
	}

	/**
	 * Wiersz zbioru domeny.
	 *
	 * @return array<string, string|null>|null
	 */
	protected function datasetRow(string $domain, string $keyword): ?array
	{
		$db = self::db();

		return $db->fetchRow(
			"SELECT dk.*, u.url FROM `{$db->table('gap_domain_keywords')}` dk
			JOIN `{$db->table('gap_domains')}` d ON d.id = dk.domain_id
			JOIN `{$db->table('market_keywords')}` m ON m.id = dk.market_keyword_id
			LEFT JOIN `{$db->table('serp_urls')}` u ON u.id = dk.url_id
			WHERE d.domain_key = UNHEX(%s) AND m.keyword_key = UNHEX(%s)",
			[md5($domain), md5($keyword)],
		);
	}
}
