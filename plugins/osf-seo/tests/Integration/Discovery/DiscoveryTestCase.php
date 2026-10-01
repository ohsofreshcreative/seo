<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Discovery;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoDiscoveryProvider;
use OsfSeo\Discovery\CandidateFilters;
use OsfSeo\Discovery\CandidateRow;
use OsfSeo\Discovery\DiscoveryCandidateRepository;
use OsfSeo\Discovery\DiscoveryConfig;
use OsfSeo\Discovery\DiscoveryPlanner;
use OsfSeo\Discovery\DiscoveryRefresher;
use OsfSeo\Discovery\DiscoveryRequest;
use OsfSeo\Discovery\DiscoveryRun;
use OsfSeo\Discovery\DiscoveryRunner;
use OsfSeo\Discovery\DiscoveryRunRepository;
use OsfSeo\Discovery\DiscoveryService;
use OsfSeo\Discovery\DiscoverySettingsRepository;
use OsfSeo\Discovery\SeedSuggester;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Tests\Integration\Market\MarketTestCase;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Baza testów wyszukiwania nowych fraz: prawdziwy WordPress i baza, DataForSEO Labs przez atrapę `pre_http_request`
 * (żaden request nie wychodzi do sieci), syntetyczne dane logowania.
 */
abstract class DiscoveryTestCase extends MarketTestCase
{
	protected const RELATED = 'https://api.dataforseo.com/v3/dataforseo_labs/google/related_keywords/live';

	protected const SUGGESTIONS = 'https://api.dataforseo.com/v3/dataforseo_labs/google/keyword_suggestions/live';

	protected const DISCOVERY_TABLES = ['discovery_runs', 'discovery_run_seeds', 'discovery_candidates', 'discovery_candidate_sources', 'discovery_settings'];

	protected const DISCOVERY_ENV = [DiscoveryConfig::MAX_CANDIDATES, DiscoveryConfig::TTL_DAYS, DiscoveryConfig::MIN_VOLUME];

	protected DataForSeoDiscoveryProvider $discoveryProvider;

	protected DiscoveryRunRepository $runs;

	protected DiscoveryCandidateRepository $candidates;

	protected DiscoverySettingsRepository $discoverySettings;

	protected DiscoveryService $discovery;

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables(...self::DISCOVERY_TABLES);
		$this->resetDiscoveryOptions();
	}

	protected function tearDown(): void
	{
		foreach (self::DISCOVERY_ENV as $name) {
			putenv($name);
		}

		$this->resetDiscoveryOptions();
		parent::tearDown();
	}

	protected function buildServices(): void
	{
		parent::buildServices();
		$db = self::db();
		$logger = $this->captureLogger();
		$config = new DataForSeoConfig();
		$discoveryConfig = new DiscoveryConfig();
		$this->discoveryProvider = new DataForSeoDiscoveryProvider(new DataForSeoClient($config, new WpHttpTransport(), $this->sleeper, $logger), $config);
		$this->runs = new DiscoveryRunRepository($db, $this->clock);
		$this->candidates = new DiscoveryCandidateRepository($db, $this->clock);
		$this->discoverySettings = new DiscoverySettingsRepository($db, $this->clock);
		$this->discovery = new DiscoveryService(
			$this->discoveryProvider,
			new DiscoveryPlanner($this->discoveryProvider, $this->runs, $discoveryConfig, $this->market, $this->clock),
			new DiscoveryRunner($this->discoveryProvider, $this->runs, $this->candidates, $this->discoverySettings, $this->marketMetrics, $this->tasks, $this->market, new MarketDataConfig(), $this->clock, $logger),
			new DiscoveryRefresher($db, $this->candidates, $this->discoverySettings, $this->discoveryProvider, $discoveryConfig, new MarketKeyBackfill($db)),
			new SeedSuggester($db, $this->discoveryProvider),
			$this->runs,
			$this->candidates,
			$this->discoverySettings,
			$discoveryConfig,
			$this->market,
			new MarketDataConfig(),
			$this->marketMetrics,
			$this->guard,
			$db,
			$logger,
		);
	}

	protected function resetDiscoveryOptions(): void
	{
		$db = self::db();
		$db->execute("DELETE FROM `{$db->optionsTable()}` WHERE option_name LIKE %s", ['%osf_seo_discovery_%']);
		wp_cache_flush();
	}

	/**
	 * @param array<string, mixed> $input
	 */
	protected function request(string $seeds, array $input = []): DiscoveryRequest
	{
		return $this->discovery->request(['seeds' => $seeds] + $input);
	}

	/**
	 * Uruchomienie (zakolejkowanie) i wykonanie przebiegu w bieżącym procesie (jak `discovery:run`).
	 *
	 * @param array<string, mixed> $input
	 */
	protected function discover(ProjectContext $context, string $seeds, array $input = []): DiscoveryRun
	{
		$result = $this->discovery->start($context, $this->request($seeds, $input), DiscoveryService::TRIGGER_CLI);
		self::assertSame('queued', $result->status, 'Przebieg zakolejkowany: ' . $result->status . ' ' . ($result->reason ?? ''));
		$this->discovery->execute($context, $result->run);

		return $this->runs->findById($result->run->id);
	}

	/**
	 * @param list<array{0: array<string, mixed>, 1: int}> $items [dane frazy, głębokość]
	 */
	protected function mockRelated(string $seed, array $items, ?int $seedVolume = null, float $cost = 0.0, ?int $total = null): void
	{
		$this->google->json(self::RELATED, 200, DataForSeoFakes::relatedResult($seed, $items, $seedVolume === null ? null : DataForSeoFakes::labsKeyword($seed, $seedVolume), $total, $cost));
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	protected function mockSuggestions(string $seed, array $items, ?int $total = null, float $cost = 0.0, int $offset = 0): void
	{
		$this->google->json(self::SUGGESTIONS, 200, DataForSeoFakes::suggestionsResult($seed, $items, null, $total, $cost, $offset));
	}

	/**
	 * @return list<array<string, mixed>> treści wysłanych żądań discovery (pierwsze zadanie)
	 */
	protected function discoveryBodies(): array
	{
		return array_map(
			static fn (array $request): array => json_decode($request['body'], true)[0],
			array_values(array_filter($this->dataForSeoRequests(), static fn (array $request): bool => str_contains($request['url'], 'dataforseo_labs/google/related_keywords') || str_contains($request['url'], 'keyword_suggestions'))),
		);
	}

	protected function candidate(ProjectContext $context, string $keyword): CandidateRow
	{
		$page = $this->discovery->list($context, CandidateFilters::fromInput(['status' => 'all', 'visibility' => 'all', 'excluded' => '0', 'q' => $keyword]));

		foreach ($page?->rows ?? [] as $row) {
			if ($row->keyword === $keyword) {
				return $this->discovery->candidate($context, $row->publicId);
			}
		}

		self::fail('Brak kandydata: ' . $keyword);
	}

	/**
	 * @return list<string>
	 */
	protected function listed(ProjectContext $context, array $filters = []): array
	{
		return array_map(static fn (CandidateRow $row): string => $row->keyword, $this->discovery->list($context, CandidateFilters::fromInput($filters))?->rows ?? []);
	}
}
