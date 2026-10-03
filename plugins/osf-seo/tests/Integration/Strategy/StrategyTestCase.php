<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Discovery\DiscoveryCandidateRepository;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Opportunities\OpportunityKeywordIndex;
use OsfSeo\Opportunities\OpportunityRepository;
use OsfSeo\Opportunities\UrlKey;
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpDictionary;
use OsfSeo\Serp\SerpPlanner;
use OsfSeo\Serp\SerpSubmitter;
use OsfSeo\Strategy\CandidateFilters;
use OsfSeo\Strategy\CandidateRow;
use OsfSeo\Strategy\Decision\ActionClassifier;
use OsfSeo\Strategy\Decision\ConfidenceModel;
use OsfSeo\Strategy\Decision\PriorityModel;
use OsfSeo\Strategy\Serp\SerpAnalysisService;
use OsfSeo\Strategy\Serp\SerpIntelligence;
use OsfSeo\Strategy\Serp\SerpProfileRepository;
use OsfSeo\Strategy\Sources\ContentGapSource;
use OsfSeo\Strategy\Sources\DiscoverySource;
use OsfSeo\Strategy\Sources\GapSource;
use OsfSeo\Strategy\Sources\GscSource;
use OsfSeo\Strategy\Sources\ManualSource;
use OsfSeo\Strategy\Sources\MarketKeywordLookup;
use OsfSeo\Strategy\Sources\OpportunitySource;
use OsfSeo\Strategy\Sources\SerpSource;
use OsfSeo\Strategy\StrategyConfig;
use OsfSeo\Strategy\StrategyKeywordRepository;
use OsfSeo\Strategy\StrategyRefresher;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\StrategySettingsRepository;
use OsfSeo\Strategy\StrategySource;
use OsfSeo\Strategy\Target\GscPageIndex;
use OsfSeo\Strategy\Target\TargetPageResolver;
use OsfSeo\Strategy\Topics\TopicClusterer;
use OsfSeo\Strategy\Topics\TopicEventRepository;
use OsfSeo\Strategy\Topics\TopicIdentity;
use OsfSeo\Strategy\Topics\TopicInputLoader;
use OsfSeo\Strategy\Topics\TopicRefresher;
use OsfSeo\Strategy\Topics\TopicRepository;
use OsfSeo\Strategy\Topics\UrlConflictDetector;
use OsfSeo\Support\Ulid;
use OsfSeo\Tests\Integration\Gap\GapTestCase;

/**
 * Baza testów Strategii (STEP 16, faza A): prawdziwy WordPress i baza, dane modułów zapisane bezpośrednio albo przez ich usługi
 * (import Luk SEO przez atrapę HTTP). Przeliczenie Strategii nie wysyła żadnych żądań.
 */
abstract class StrategyTestCase extends GapTestCase
{
	protected const STRATEGY_TABLES = ['strategy_settings', 'strategy_keywords', 'strategy_topics', 'strategy_topic_events', 'opportunities', 'opportunity_detections', 'opportunity_analyses'];

	protected const STRATEGY_ENV = [
		StrategyConfig::MAX_KEYWORDS,
		StrategyConfig::WINDOW_DAYS,
		StrategyConfig::GSC_MIN_IMPRESSIONS,
		StrategyConfig::GSC_MAX_POSITION,
		StrategyConfig::DISCOVERY_MIN_PRIORITY,
		StrategyConfig::GAP_MIN_PRIORITY,
	];

	protected StrategyService $strategy;

	protected SerpIntelligence $intelligence;

	protected SerpAnalysisService $analysis;

	protected StrategyRefresher $strategyRefresher;

	protected StrategyKeywordRepository $strategyKeywords;

	protected StrategySettingsRepository $strategySettings;

	protected TopicRepository $topics;

	protected TopicEventRepository $topicEvents;

	protected DiscoveryCandidateRepository $discoveryCandidates;

	protected OpportunityRepository $opportunities;

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables(...self::STRATEGY_TABLES);
	}

	protected function tearDown(): void
	{
		foreach (self::STRATEGY_ENV as $name) {
			putenv($name);
		}

		parent::tearDown();
	}

	protected function buildServices(): void
	{
		parent::buildServices();
		$this->buildStrategy();
	}

	/** Usługi Strategii od nowa (np. po zmianie konfiguracji w zmiennych środowiskowych). */
	protected function buildStrategy(): void
	{
		$db = self::db();
		$config = new StrategyConfig();
		$lookup = new MarketKeywordLookup($db);
		$gsc = new GscSource($db, $lookup, new SerpDictionary($db, $this->clock));
		$this->intelligence = new SerpIntelligence($db, new SerpProfileRepository($db, $this->clock), $this->serpSettings, $this->competitorRepository, $this->serpReports, $this->clock);
		$this->strategyKeywords = new StrategyKeywordRepository($db);
		$this->topics = new TopicRepository($db);
		$this->topicEvents = new TopicEventRepository($db);
		$this->strategySettings = new StrategySettingsRepository($db, $this->clock);
		$this->discoveryCandidates = new DiscoveryCandidateRepository($db, $this->clock);
		$this->opportunities = new OpportunityRepository($db, $this->projects, $this->clock);
		$this->strategyRefresher = new StrategyRefresher(
			$db,
			[
				new ManualSource($db),
				new SerpSource($db, $this->intelligence),
				new OpportunitySource($db, new OpportunityKeywordIndex($db), $lookup),
				new DiscoverySource($db),
				new GapSource($db),
				new ContentGapSource($db),
				$gsc,
			],
			$gsc,
			$this->strategyKeywords,
			$this->strategySettings,
			$this->provider,
			$this->marketMetrics,
			$lookup,
			new MarketKeyBackfill($db),
			$this->discoverySettings,
			$this->gapSettings,
			$this->competitorRepository,
			$config,
			$this->clock,
			new TopicRefresher(
				new TopicInputLoader($db),
				$this->topics,
				$this->topicEvents,
				new TargetPageResolver(),
				new UrlConflictDetector(),
				new TopicClusterer(),
				new TopicIdentity(),
				new ActionClassifier(),
				new ConfidenceModel(),
				new PriorityModel(),
				$this->intelligence,
				new GscPageIndex($db),
				$this->clock,
			),
		);
		$this->strategy = new StrategyService(
			$this->strategyRefresher,
			$this->strategyKeywords,
			$this->strategySettings,
			$config,
			$this->provider,
			$this->marketMetrics,
			$this->intelligence,
			$this->clock,
			$this->captureLogger(),
			$this->topics,
			$this->topicEvents,
			new SerpDictionary($db, $this->clock),
		);
		$logger = $this->captureLogger();
		$planner = new SerpPlanner($this->serpProvider, $this->tracked, new SerpConfig(), $this->market, $this->clock);
		$this->analysis = new SerpAnalysisService(
			$this->intelligence,
			$this->serpProvider,
			$this->tracked,
			new SerpSubmitter($db, $this->serpProvider, $this->tracked, $this->serpRuns, $this->snapshots, $this->contexts, $this->tasks, $this->market, $planner, $this->clock, $logger),
			$planner,
			$this->serpRuns,
			$this->serpSettings,
			$this->strategyKeywords,
			$this->market,
			$config,
			$db,
			$this->clock,
			$logger,
		);
	}

	/**
	 * Kandydat Nowych fraz (bez przebiegu — bezpośredni zapis).
	 */
	protected function discoveryCandidate(ProjectContext $context, string $keyword, string $status, int $priority, bool $excluded = false): string
	{
		$db = self::db();
		$market = $this->strategy->market($context) ?? throw new \RuntimeException('No market');
		$marketId = (int) $this->marketMetrics->ensure($market, [$keyword])[$keyword];
		$now = $this->clock->now()->format('Y-m-d H:i:s');
		$publicId = Ulid::generate();
		$db->insert($db->table('discovery_candidates'), [
			'public_id' => $publicId,
			'project_id' => $context->projectId(),
			'market_keyword_id' => $marketId,
			'status' => $status,
			'seeds_count' => 1,
			'best_relation' => 1,
			'visibility' => 'unknown',
			'priority' => $priority,
			'excluded' => $excluded ? 1 : 0,
			'discovered_at' => $now,
			'last_seen_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		]);

		return $publicId;
	}

	/**
	 * Szansa SEO z wykryciem 28 dni (bez analizy — bezpośredni zapis).
	 */
	protected function opportunity(ProjectContext $context, string $type, ?string $page, ?string $keyword, string $searchText, int $priority, string $status = 'new'): string
	{
		$db = self::db();
		$now = $this->clock->now()->format('Y-m-d H:i:s');
		$publicId = Ulid::generate();
		$id = $db->insert($db->table('opportunities'), array_filter([
			'public_id' => $publicId,
			'project_id' => $context->projectId(),
			'fingerprint' => md5($publicId, true),
			'type' => $type,
			'property' => 'sc-domain:example.pl',
			'page_url' => $page,
			'keyword' => $keyword,
			'state' => 'active',
			'status' => $status,
			'last_priority' => $priority,
			'first_detected_at' => $now,
			'last_detected_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		], static fn (mixed $value): bool => $value !== null));

		if ($page !== null) {
			$db->execute("UPDATE `{$db->table('opportunities')}` SET page_hash = UNHEX(%s) WHERE id = %d", [bin2hex(UrlKey::hash($page)), $id]);
		}

		$db->insert($db->table('opportunity_detections'), [
			'opportunity_id' => $id,
			'period_days' => 28,
			'project_id' => $context->projectId(),
			'priority' => $priority,
			'confidence' => 2,
			'impressions' => 100,
			'clicks' => 1,
			'latest_date' => '2026-01-14',
			'search_text' => $searchText,
			'evidence' => '{}',
			'analyzed_at' => $now,
		]);

		return $publicId;
	}

	protected function candidateRow(ProjectContext $context, string $keyword): ?CandidateRow
	{
		$market = $this->strategy->market($context) ?? throw new \RuntimeException('No market');

		return $this->strategyKeywords->findByKey($context->projectId(), $market, bin2hex(MarketKeyword::key($keyword)));
	}

	/**
	 * Aktywni kandydaci: fraza → kody źródeł.
	 *
	 * @return array<string, list<string>>
	 */
	protected function activeCandidates(ProjectContext $context): array
	{
		$result = [];

		foreach ($this->strategy->candidates($context, new CandidateFilters(perPage: 500))['rows'] as $row) {
			$result[$row->keyword] = array_map(static fn (StrategySource $source): string => $source->value, $row->sources);
		}

		ksort($result);

		return $result;
	}

	/**
	 * Nieaktywni kandydaci: fraza → powód.
	 *
	 * @return array<string, ?string>
	 */
	protected function inactiveCandidates(ProjectContext $context): array
	{
		$result = [];

		foreach ($this->strategy->candidates($context, new CandidateFilters(status: 'inactive', perPage: 500))['rows'] as $row) {
			$result[$row->keyword] = $row->inactiveReason;
		}

		ksort($result);

		return $result;
	}
}
