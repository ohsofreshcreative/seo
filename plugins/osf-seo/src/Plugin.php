<?php

declare(strict_types=1);

namespace OsfSeo;

use OsfSeo\Ai\AiAnalysisService;
use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\Budget\AiBudget;
use OsfSeo\Ai\Budget\AiPricing;
use OsfSeo\Ai\Context\AiTopicContextBuilder;
use OsfSeo\Ai\Contract\OutputValidator;
use OsfSeo\Ai\Provider\AiProviderRegistry;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Provider\OpenAiProvider;
use OsfSeo\Ai\Run\AiRunRepository;
use OsfSeo\Analytics\KeywordReport;
use OsfSeo\PageIntelligence\Extract\HtmlExtractor;
use OsfSeo\PageIntelligence\Fetch\CurlPageFetcher;
use OsfSeo\PageIntelligence\Fetch\PublicNetworkPolicy;
use OsfSeo\PageIntelligence\Fetch\SystemHostResolver;
use OsfSeo\PageIntelligence\Fetch\UrlSafetyPolicy;
use OsfSeo\PageIntelligence\PageIntelligenceConfig;
use OsfSeo\PageIntelligence\PageIntelligenceRepository;
use OsfSeo\PageIntelligence\PageIntelligenceService;
use OsfSeo\PageIntelligence\Robots\RobotsPolicy;
use OsfSeo\PageIntelligence\Robots\TransientRobotsCache;
use OsfSeo\Analytics\OverviewReport;
use OsfSeo\Analytics\ReportCache;
use OsfSeo\Auth\LoginThrottle;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\RoleManager;
use OsfSeo\Auth\WpAdminAccess;
use OsfSeo\Auth\WpRoleStore;
use OsfSeo\Branding\BrandingService;
use OsfSeo\Cli\AiCommand;
use OsfSeo\Cli\PagesCommand;
use OsfSeo\Cli\DbCommand;
use OsfSeo\Cli\CompetitorCommand;
use OsfSeo\Cli\DiscoveryCommand;
use OsfSeo\Cli\GapCommand;
use OsfSeo\Cli\SerpCommand;
use OsfSeo\Cli\GoogleCommand;
use OsfSeo\Cli\GscCommand;
use OsfSeo\Cli\MarketCommand;
use OsfSeo\Cli\OpportunityCommand;
use OsfSeo\Cli\ProjectCommand;
use OsfSeo\Cli\StatusCommand;
use OsfSeo\Cli\StrategyCommand;
use OsfSeo\Cli\StrategyTopicCommand;
use OsfSeo\Cli\SyncCommand;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migrator;
use OsfSeo\Database\SchemaInspector;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoDiscoveryProvider;
use OsfSeo\DataForSeo\DataForSeoRankedKeywordsProvider;
use OsfSeo\DataForSeo\DataForSeoSerpProvider;
use OsfSeo\DataForSeo\DataForSeoProvider;
use OsfSeo\Discovery\DiscoveryCandidateRepository;
use OsfSeo\Discovery\DiscoveryConfig;
use OsfSeo\Discovery\DiscoveryPlanner;
use OsfSeo\Discovery\DiscoveryRefresher;
use OsfSeo\Discovery\DiscoveryRunner;
use OsfSeo\Discovery\DiscoveryRunRepository;
use OsfSeo\Discovery\DiscoveryService;
use OsfSeo\Discovery\DiscoverySettingsRepository;
use OsfSeo\Discovery\KeywordDiscoveryProvider;
use OsfSeo\Discovery\SeedSuggester;
use OsfSeo\Gap\CompetitorKeywordsProvider;
use OsfSeo\Gap\GapConfig;
use OsfSeo\Gap\GapDomainRepository;
use OsfSeo\Gap\GapImporter;
use OsfSeo\Gap\GapPlanner;
use OsfSeo\Gap\GapRefresher;
use OsfSeo\Gap\GapReports;
use OsfSeo\Gap\GapRunRepository;
use OsfSeo\Gap\GapService;
use OsfSeo\Gap\GapSettingsRepository;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Serp\CompetitorService;
use OsfSeo\Serp\SerpCollector;
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpContextRepository;
use OsfSeo\Serp\SerpDictionary;
use OsfSeo\Serp\SerpPlanner;
use OsfSeo\Serp\SerpProvider;
use OsfSeo\Serp\SerpReports;
use OsfSeo\Serp\SerpRunRepository;
use OsfSeo\Serp\SerpSettingsRepository;
use OsfSeo\Serp\SerpSnapshotRepository;
use OsfSeo\Serp\SerpStore;
use OsfSeo\Serp\SerpSubmitter;
use OsfSeo\Serp\SerpTrackingService;
use OsfSeo\Serp\TrackedKeywordRepository;
use OsfSeo\Google\AccessTokenProvider;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleApi;
use OsfSeo\Google\GoogleConfig;
use OsfSeo\Google\OAuthClient;
use OsfSeo\Google\OAuthFlow;
use OsfSeo\Google\OAuthStateStore;
use OsfSeo\Google\TokenVault;
use OsfSeo\Gsc\GscCalendar;
use OsfSeo\Gsc\GscClient;
use OsfSeo\Gsc\Dictionary;
use OsfSeo\Gsc\GscDataStore;
use OsfSeo\Gsc\GscImporter;
use OsfSeo\Gsc\GscProbe;
use OsfSeo\Gsc\PropertyService;
use OsfSeo\Http\HttpTransport;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Market\KeywordMetricsProvider;
use OsfSeo\Market\MarketCandidateSelector;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\MarketSyncStateRepository;
use OsfSeo\Market\MarketTaskRepository;
use OsfSeo\Opportunities\OpportunityAnalyzer;
use OsfSeo\Opportunities\OpportunityConfig;
use OsfSeo\Opportunities\OpportunityDataSource;
use OsfSeo\Opportunities\OpportunityKeywordIndex;
use OsfSeo\Opportunities\OpportunityRepository;
use OsfSeo\Opportunities\OpportunityScheduler;
use OsfSeo\Opportunities\OpportunityService;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Projects\ProjectService;
use OsfSeo\Setup\Installer;
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
use OsfSeo\Strategy\StrategyFreshness;
use OsfSeo\Strategy\StrategyKeywordRepository;
use OsfSeo\Strategy\StrategyRefresher;
use OsfSeo\Strategy\StrategyRefreshQueue;
use OsfSeo\Strategy\StrategyRefreshRunner;
use OsfSeo\Strategy\StrategyScheduler;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\StrategySettingsRepository;
use OsfSeo\Strategy\Target\GscPageIndex;
use OsfSeo\Strategy\Target\ProjectPageIndex;
use OsfSeo\Strategy\Target\TargetPageResolver;
use OsfSeo\Strategy\Topics\TopicClusterer;
use OsfSeo\Strategy\Topics\TopicEventRepository;
use OsfSeo\Strategy\Topics\TopicIdentity;
use OsfSeo\Strategy\Topics\TopicInputLoader;
use OsfSeo\Strategy\Topics\TopicRefresher;
use OsfSeo\Strategy\Topics\TopicRepository;
use OsfSeo\Strategy\Topics\UrlConflictDetector;
use OsfSeo\Support\Clock;
use OsfSeo\Sync\SyncConfig;
use OsfSeo\Sync\SyncPlanner;
use OsfSeo\Sync\SyncRunner;
use OsfSeo\Sync\SyncRunRepository;
use OsfSeo\Sync\SyncScheduler;
use OsfSeo\Sync\SyncService;
use OsfSeo\Sync\SyncStateRepository;
use OsfSeo\Sync\TriggerType;
use OsfSeo\Support\Config;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Sleeper;
use OsfSeo\Support\SystemClock;
use OsfSeo\Support\SystemSleeper;

final class Plugin
{
	/** Musi być zgodna z nagłówkiem `Version` w osf-seo.php (pilnuje tego test). */
	public const VERSION = '0.18.0';

	public const MIN_PHP = '8.2';

	public const MIN_WP = '6.6';

	private static ?self $instance = null;

	private bool $booted = false;

	public function __construct(
		private readonly string $file,
		private readonly Container $container,
	) {
	}

	public static function instance(): self
	{
		return self::$instance ??= new self(OSF_SEO_FILE, self::createContainer());
	}

	/**
	 * Rejestr usług pluginu — jedyne miejsce, w którym składane są zależności.
	 * Fabryki są leniwe: samo zbudowanie kontenera nie dotyka WordPressa ani bazy.
	 */
	public static function createContainer(): Container
	{
		$container = new Container();

		$container->singleton(Config::class, static fn (): Config => new Config());
		$container->singleton(Clock::class, static fn (): Clock => new SystemClock());
		$container->singleton(Sleeper::class, static fn (): Sleeper => new SystemSleeper());
		$container->singleton(Logger::class, static fn (Container $c): Logger => Logger::fromConfig($c->get(Config::class)));
		$container->singleton(Connection::class, static fn (): Connection => Connection::fromGlobals());
		$container->singleton(Migrator::class, static fn (Container $c): Migrator => new Migrator(
			$c->get(Connection::class),
			$c->get(Logger::class),
		));
		$container->singleton(SchemaInspector::class, static fn (Container $c): SchemaInspector => new SchemaInspector($c->get(Connection::class)));
		$container->singleton(RoleManager::class, static fn (): RoleManager => new RoleManager(new WpRoleStore()));
		$container->singleton(Installer::class, static fn (Container $c): Installer => new Installer(
			$c->get(RoleManager::class),
			$c->get(Migrator::class),
			$c->get(Logger::class),
		));
		$container->singleton(ProjectRepository::class, static fn (Container $c): ProjectRepository => new ProjectRepository(
			$c->get(Connection::class),
			$c->get(Clock::class),
		));
		$container->singleton(LoginThrottle::class, static fn (): LoginThrottle => new LoginThrottle());
		$container->singleton(ProjectGuard::class, static fn (Container $c): ProjectGuard => new ProjectGuard($c->get(ProjectRepository::class)));
		$container->singleton(ProjectService::class, static fn (Container $c): ProjectService => new ProjectService(
			$c->get(ProjectRepository::class),
			$c->get(ProjectGuard::class),
			$c->get(Logger::class),
		));

		$container->singleton(HttpTransport::class, static fn (): HttpTransport => new WpHttpTransport());
		$container->singleton(GoogleConfig::class, static fn (Container $c): GoogleConfig => new GoogleConfig(
			$c->get(Config::class),
			home_url(GoogleConfig::CALLBACK_PATH),
		));
		// Klucz czytany przy każdym użyciu (nie jest kopiowany do pól obiektów ani do bazy).
		$container->singleton(TokenVault::class, static fn (Container $c): TokenVault => new TokenVault(
			static fn (): ?string => $c->get(GoogleConfig::class)->encryptionKey(),
		));
		$container->singleton(OAuthClient::class, static fn (Container $c): OAuthClient => new OAuthClient(
			$c->get(GoogleConfig::class),
			$c->get(HttpTransport::class),
		));
		$container->singleton(OAuthStateStore::class, static fn (Container $c): OAuthStateStore => new OAuthStateStore($c->get(Clock::class)));
		$container->singleton(ConnectionRepository::class, static fn (Container $c): ConnectionRepository => new ConnectionRepository(
			$c->get(Connection::class),
			$c->get(Clock::class),
		));
		$container->singleton(AccessTokenProvider::class, static fn (Container $c): AccessTokenProvider => new AccessTokenProvider(
			$c->get(ConnectionRepository::class),
			$c->get(TokenVault::class),
			$c->get(OAuthClient::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));
		$container->singleton(GoogleApi::class, static fn (Container $c): GoogleApi => new GoogleApi(
			$c->get(AccessTokenProvider::class),
			$c->get(HttpTransport::class),
		));
		$container->singleton(OAuthFlow::class, static fn (Container $c): OAuthFlow => new OAuthFlow(
			$c->get(GoogleConfig::class),
			$c->get(OAuthClient::class),
			$c->get(OAuthStateStore::class),
			$c->get(TokenVault::class),
			$c->get(ConnectionRepository::class),
			$c->get(ProjectRepository::class),
			$c->get(ProjectGuard::class),
			$c->get(AccessTokenProvider::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));

		// Search Console: odpowiedzi do 25 000 wierszy (kilka MB) — dłuższy timeout niż dla endpointów OAuth.
		$container->singleton(GscClient::class, static fn (Container $c): GscClient => new GscClient(
			new GoogleApi($c->get(AccessTokenProvider::class), new WpHttpTransport(GscClient::HTTP_TIMEOUT)),
			$c->get(Sleeper::class),
			$c->get(Logger::class),
		));
		$container->singleton(GscCalendar::class, static fn (Container $c): GscCalendar => new GscCalendar($c->get(Clock::class)));
		$container->singleton(GscProbe::class, static fn (Container $c): GscProbe => new GscProbe(
			$c->get(GscClient::class),
			$c->get(ConnectionRepository::class),
			$c->get(ProjectRepository::class),
			$c->get(GscCalendar::class),
		));
		$container->singleton(GscDataStore::class, static fn (Container $c): GscDataStore => new GscDataStore($c->get(Connection::class)));
		$container->singleton(Dictionary::class, static fn (Container $c): Dictionary => new Dictionary($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(GscImporter::class, static fn (Container $c): GscImporter => new GscImporter(
			$c->get(GscClient::class),
			$c->get(Connection::class),
			$c->get(ProjectRepository::class),
			$c->get(Dictionary::class),
			$c->get(Logger::class),
		));
		$container->singleton(PropertyService::class, static function (Container $c): PropertyService {
			$service = new PropertyService(
				$c->get(GscClient::class),
				$c->get(ConnectionRepository::class),
				$c->get(ProjectRepository::class),
				$c->get(GscDataStore::class),
				$c->get(Connection::class),
				$c->get(Logger::class),
			);
			// Reset danych anuluje oczekujące zadania starej property i archiwizuje jej szanse SEO;
			// wybór property planuje pierwszy import.
			$service->onDetach(static fn (ProjectContext $context) => $c->get(SyncRunRepository::class)->cancelPending($context->projectId(), 'property_reset'));
			$service->onDetach(static fn (ProjectContext $context) => $c->get(OpportunityRepository::class)->archiveProject($context->projectId()));
			// Nowe frazy (STEP 13) zostają ze stanem pracy; widoczność GSC — do ponownej oceny po nowym imporcie.
			$service->onDetach(static fn (ProjectContext $context) => $c->get(DiscoveryService::class)->onPropertyReset($context->projectId()));
			$service->onSelected(static fn (ProjectContext $context) => $c->get(SyncPlanner::class)->plan($context, TriggerType::Connect));

			return $service;
		});

		// Dane rynkowe fraz (STEP 12): DataForSEO za interfejsem KeywordMetricsProvider; płatne żądania wyłącznie z MarketSyncService.
		$container->singleton(MarketDataConfig::class, static fn (Container $c): MarketDataConfig => new MarketDataConfig($c->get(Config::class)));
		$container->singleton(DataForSeoConfig::class, static fn (Container $c): DataForSeoConfig => new DataForSeoConfig($c->get(Config::class)));
		$container->singleton(DataForSeoClient::class, static fn (Container $c): DataForSeoClient => new DataForSeoClient(
			$c->get(DataForSeoConfig::class),
			new WpHttpTransport(DataForSeoClient::HTTP_TIMEOUT),
			$c->get(Sleeper::class),
			$c->get(Logger::class),
		));
		$container->singleton(KeywordMetricsProvider::class, static fn (Container $c): KeywordMetricsProvider => new DataForSeoProvider(
			$c->get(DataForSeoClient::class),
			$c->get(DataForSeoConfig::class),
		));
		$container->singleton(MarketMetricsRepository::class, static fn (Container $c): MarketMetricsRepository => new MarketMetricsRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(MarketTaskRepository::class, static fn (Container $c): MarketTaskRepository => new MarketTaskRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(MarketSyncStateRepository::class, static fn (Container $c): MarketSyncStateRepository => new MarketSyncStateRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(MarketKeyBackfill::class, static fn (Container $c): MarketKeyBackfill => new MarketKeyBackfill($c->get(Connection::class)));
		$container->singleton(MarketCandidateSelector::class, static fn (Container $c): MarketCandidateSelector => new MarketCandidateSelector($c->get(Connection::class), $c->get(KeywordReport::class)));
		$container->singleton(MarketSyncService::class, static fn (Container $c): MarketSyncService => new MarketSyncService(
			$c->get(KeywordMetricsProvider::class),
			$c->get(MarketMetricsRepository::class),
			$c->get(MarketTaskRepository::class),
			$c->get(MarketSyncStateRepository::class),
			$c->get(MarketCandidateSelector::class),
			$c->get(MarketKeyBackfill::class),
			$c->get(MarketDataConfig::class),
			$c->get(ProjectGuard::class),
			$c->get(Connection::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));

		// Wyszukiwanie nowych fraz (STEP 13): DataForSEO Labs za interfejsem KeywordDiscoveryProvider; płatne żądania
		// wyłącznie z DiscoveryRunner (pod wspólną blokadą i limitami kosztów danych rynkowych).
		$container->singleton(DiscoveryConfig::class, static fn (Container $c): DiscoveryConfig => new DiscoveryConfig($c->get(Config::class)));
		$container->singleton(KeywordDiscoveryProvider::class, static fn (Container $c): KeywordDiscoveryProvider => new DataForSeoDiscoveryProvider(
			$c->get(DataForSeoClient::class),
			$c->get(DataForSeoConfig::class),
		));
		$container->singleton(DiscoveryRunRepository::class, static fn (Container $c): DiscoveryRunRepository => new DiscoveryRunRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(DiscoveryCandidateRepository::class, static fn (Container $c): DiscoveryCandidateRepository => new DiscoveryCandidateRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(DiscoverySettingsRepository::class, static fn (Container $c): DiscoverySettingsRepository => new DiscoverySettingsRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(DiscoveryService::class, static fn (Container $c): DiscoveryService => new DiscoveryService(
			$c->get(KeywordDiscoveryProvider::class),
			new DiscoveryPlanner($c->get(KeywordDiscoveryProvider::class), $c->get(DiscoveryRunRepository::class), $c->get(DiscoveryConfig::class), $c->get(MarketSyncService::class), $c->get(Clock::class)),
			new DiscoveryRunner(
				$c->get(KeywordDiscoveryProvider::class),
				$c->get(DiscoveryRunRepository::class),
				$c->get(DiscoveryCandidateRepository::class),
				$c->get(DiscoverySettingsRepository::class),
				$c->get(MarketMetricsRepository::class),
				$c->get(MarketTaskRepository::class),
				$c->get(MarketSyncService::class),
				$c->get(MarketDataConfig::class),
				$c->get(Clock::class),
				$c->get(Logger::class),
			),
			new DiscoveryRefresher(
				$c->get(Connection::class),
				$c->get(DiscoveryCandidateRepository::class),
				$c->get(DiscoverySettingsRepository::class),
				$c->get(KeywordDiscoveryProvider::class),
				$c->get(DiscoveryConfig::class),
				$c->get(MarketKeyBackfill::class),
			),
			new SeedSuggester($c->get(Connection::class), $c->get(KeywordDiscoveryProvider::class)),
			$c->get(DiscoveryRunRepository::class),
			$c->get(DiscoveryCandidateRepository::class),
			$c->get(DiscoverySettingsRepository::class),
			$c->get(DiscoveryConfig::class),
			$c->get(MarketSyncService::class),
			$c->get(MarketDataConfig::class),
			$c->get(MarketMetricsRepository::class),
			$c->get(ProjectGuard::class),
			$c->get(Connection::class),
			$c->get(Logger::class),
		));

		// Pozycje SERP i konkurenci (STEP 14): DataForSEO Google Organic (Standard) za interfejsem SerpProvider; płatne
		// zlecenia wyłącznie z SerpSubmitter (rezerwacja kosztu we wspólnych limitach, wysyłka pod wspólną blokadą).
		$container->singleton(SerpConfig::class, static fn (Container $c): SerpConfig => new SerpConfig($c->get(Config::class)));
		$container->singleton(SerpProvider::class, static fn (Container $c): SerpProvider => new DataForSeoSerpProvider(
			$c->get(DataForSeoClient::class),
			$c->get(DataForSeoConfig::class),
		));
		$container->singleton(SerpContextRepository::class, static fn (Container $c): SerpContextRepository => new SerpContextRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(TrackedKeywordRepository::class, static fn (Container $c): TrackedKeywordRepository => new TrackedKeywordRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(SerpSnapshotRepository::class, static fn (Container $c): SerpSnapshotRepository => new SerpSnapshotRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(SerpRunRepository::class, static fn (Container $c): SerpRunRepository => new SerpRunRepository($c->get(Connection::class), $c->get(Clock::class), $c->get(SerpSnapshotRepository::class)));
		$container->singleton(SerpSettingsRepository::class, static fn (Container $c): SerpSettingsRepository => new SerpSettingsRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(CompetitorRepository::class, static fn (Container $c): CompetitorRepository => new CompetitorRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(SerpReports::class, static fn (Container $c): SerpReports => new SerpReports($c->get(Connection::class)));
		$container->singleton(SerpPlanner::class, static fn (Container $c): SerpPlanner => new SerpPlanner(
			$c->get(SerpProvider::class),
			$c->get(TrackedKeywordRepository::class),
			$c->get(SerpConfig::class),
			$c->get(MarketSyncService::class),
			$c->get(Clock::class),
		));
		$container->singleton(SerpSubmitter::class, static fn (Container $c): SerpSubmitter => new SerpSubmitter(
			$c->get(Connection::class),
			$c->get(SerpProvider::class),
			$c->get(TrackedKeywordRepository::class),
			$c->get(SerpRunRepository::class),
			$c->get(SerpSnapshotRepository::class),
			$c->get(SerpContextRepository::class),
			$c->get(MarketTaskRepository::class),
			$c->get(MarketSyncService::class),
			$c->get(SerpPlanner::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));
		$container->singleton(SerpCollector::class, static fn (Container $c): SerpCollector => new SerpCollector(
			$c->get(Connection::class),
			$c->get(SerpProvider::class),
			$c->get(SerpSnapshotRepository::class),
			$c->get(SerpRunRepository::class),
			new SerpStore(
				$c->get(Connection::class),
				new SerpDictionary($c->get(Connection::class), $c->get(Clock::class)),
				$c->get(SerpSnapshotRepository::class),
				$c->get(TrackedKeywordRepository::class),
				$c->get(Clock::class),
			),
			$c->get(SerpConfig::class),
			$c->get(MarketSyncService::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));
		$container->singleton(SerpTrackingService::class, static fn (Container $c): SerpTrackingService => new SerpTrackingService(
			$c->get(SerpProvider::class),
			$c->get(SerpPlanner::class),
			$c->get(SerpSubmitter::class),
			$c->get(SerpCollector::class),
			$c->get(TrackedKeywordRepository::class),
			$c->get(SerpRunRepository::class),
			$c->get(SerpSettingsRepository::class),
			$c->get(SerpContextRepository::class),
			$c->get(CompetitorRepository::class),
			$c->get(SerpReports::class),
			$c->get(SerpConfig::class),
			$c->get(MarketSyncService::class),
			$c->get(MarketMetricsRepository::class),
			$c->get(ProjectGuard::class),
			$c->get(Connection::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));
		$container->singleton(CompetitorService::class, static fn (Container $c): CompetitorService => new CompetitorService(
			$c->get(CompetitorRepository::class),
			$c->get(SerpReports::class),
			$c->get(SerpTrackingService::class),
			$c->get(Logger::class),
			new ReportCache(),
		));

		// Luki SEO (STEP 15): DataForSEO Labs Ranked Keywords za interfejsem CompetitorKeywordsProvider; płatne żądania
		// wyłącznie z GapImporter (pod wspólną blokadą i limitami kosztów DataForSEO).
		$container->singleton(GapConfig::class, static fn (Container $c): GapConfig => new GapConfig($c->get(Config::class)));
		$container->singleton(CompetitorKeywordsProvider::class, static fn (Container $c): CompetitorKeywordsProvider => new DataForSeoRankedKeywordsProvider(
			$c->get(DataForSeoClient::class),
			$c->get(DataForSeoConfig::class),
		));
		$container->singleton(GapDomainRepository::class, static fn (Container $c): GapDomainRepository => new GapDomainRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(GapRunRepository::class, static fn (Container $c): GapRunRepository => new GapRunRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(GapSettingsRepository::class, static fn (Container $c): GapSettingsRepository => new GapSettingsRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(GapReports::class, static fn (Container $c): GapReports => new GapReports($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(GapRefresher::class, static fn (Container $c): GapRefresher => new GapRefresher(
			$c->get(Connection::class),
			$c->get(CompetitorKeywordsProvider::class),
			$c->get(GapDomainRepository::class),
			$c->get(GapSettingsRepository::class),
			$c->get(CompetitorRepository::class),
			$c->get(DiscoverySettingsRepository::class),
			new SerpDictionary($c->get(Connection::class), $c->get(Clock::class)),
			$c->get(MarketKeyBackfill::class),
			$c->get(GapConfig::class),
			$c->get(Clock::class),
		));
		$container->singleton(GapService::class, static fn (Container $c): GapService => new GapService(
			$c->get(CompetitorKeywordsProvider::class),
			new GapPlanner($c->get(CompetitorKeywordsProvider::class), $c->get(GapDomainRepository::class), $c->get(GapRunRepository::class), $c->get(CompetitorRepository::class), $c->get(MarketSyncService::class), $c->get(Clock::class)),
			new GapImporter(
				$c->get(CompetitorKeywordsProvider::class),
				$c->get(GapRunRepository::class),
				$c->get(GapDomainRepository::class),
				$c->get(MarketMetricsRepository::class),
				$c->get(MarketTaskRepository::class),
				new SerpDictionary($c->get(Connection::class), $c->get(Clock::class)),
				$c->get(MarketSyncService::class),
				$c->get(MarketDataConfig::class),
				$c->get(GapConfig::class),
				$c->get(Clock::class),
				$c->get(Logger::class),
			),
			$c->get(GapRefresher::class),
			$c->get(GapRunRepository::class),
			$c->get(GapDomainRepository::class),
			$c->get(GapSettingsRepository::class),
			$c->get(GapReports::class),
			$c->get(CompetitorRepository::class),
			$c->get(GapConfig::class),
			$c->get(MarketSyncService::class),
			$c->get(MarketDataConfig::class),
			$c->get(ProjectGuard::class),
			$c->get(Connection::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));

		// Strategia (STEP 16): warstwa decyzyjna nad modułami — tylko odczyt ich danych i decyzji, bez żadnych żądań do API.
		$container->singleton(StrategyConfig::class, static fn (Container $c): StrategyConfig => new StrategyConfig($c->get(Config::class)));
		$container->singleton(StrategySettingsRepository::class, static fn (Container $c): StrategySettingsRepository => new StrategySettingsRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(StrategyKeywordRepository::class, static fn (Container $c): StrategyKeywordRepository => new StrategyKeywordRepository($c->get(Connection::class)));
		$container->singleton(MarketKeywordLookup::class, static fn (Container $c): MarketKeywordLookup => new MarketKeywordLookup($c->get(Connection::class)));
		$container->singleton(GscSource::class, static fn (Container $c): GscSource => new GscSource(
			$c->get(Connection::class),
			$c->get(MarketKeywordLookup::class),
			new SerpDictionary($c->get(Connection::class), $c->get(Clock::class)),
		));
		$container->singleton(SerpProfileRepository::class, static fn (Container $c): SerpProfileRepository => new SerpProfileRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(SerpIntelligence::class, static fn (Container $c): SerpIntelligence => new SerpIntelligence(
			$c->get(Connection::class),
			$c->get(SerpProfileRepository::class),
			$c->get(SerpSettingsRepository::class),
			$c->get(CompetitorRepository::class),
			$c->get(SerpReports::class),
			$c->get(Clock::class),
		));
		$container->singleton(SerpAnalysisService::class, static fn (Container $c): SerpAnalysisService => new SerpAnalysisService(
			$c->get(SerpIntelligence::class),
			$c->get(SerpProvider::class),
			$c->get(TrackedKeywordRepository::class),
			$c->get(SerpSubmitter::class),
			$c->get(SerpPlanner::class),
			$c->get(SerpRunRepository::class),
			$c->get(SerpSettingsRepository::class),
			$c->get(StrategyKeywordRepository::class),
			$c->get(MarketSyncService::class),
			$c->get(StrategyConfig::class),
			$c->get(Connection::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));
		$container->singleton(TopicRepository::class, static fn (Container $c): TopicRepository => new TopicRepository($c->get(Connection::class)));
		$container->singleton(TopicEventRepository::class, static fn (Container $c): TopicEventRepository => new TopicEventRepository($c->get(Connection::class)));
		$container->singleton(ProjectPageIndex::class, static fn (Container $c): ProjectPageIndex => new GscPageIndex($c->get(Connection::class)));
		$container->singleton(TopicRefresher::class, static fn (Container $c): TopicRefresher => new TopicRefresher(
			new TopicInputLoader($c->get(Connection::class)),
			$c->get(TopicRepository::class),
			$c->get(TopicEventRepository::class),
			new TargetPageResolver(),
			new UrlConflictDetector(),
			new TopicClusterer(),
			new TopicIdentity(),
			new ActionClassifier(),
			new ConfidenceModel(),
			new PriorityModel(),
			$c->get(SerpIntelligence::class),
			$c->get(ProjectPageIndex::class),
			$c->get(Clock::class),
		));
		$container->singleton(StrategyRefresher::class, static fn (Container $c): StrategyRefresher => new StrategyRefresher(
			$c->get(Connection::class),
			[
				new ManualSource($c->get(Connection::class)),
				new SerpSource($c->get(Connection::class), $c->get(SerpIntelligence::class)),
				new OpportunitySource($c->get(Connection::class), new OpportunityKeywordIndex($c->get(Connection::class)), $c->get(MarketKeywordLookup::class)),
				new DiscoverySource($c->get(Connection::class)),
				new GapSource($c->get(Connection::class)),
				new ContentGapSource($c->get(Connection::class)),
				$c->get(GscSource::class),
			],
			$c->get(GscSource::class),
			$c->get(StrategyKeywordRepository::class),
			$c->get(StrategySettingsRepository::class),
			$c->get(KeywordMetricsProvider::class),
			$c->get(MarketMetricsRepository::class),
			$c->get(MarketKeywordLookup::class),
			$c->get(MarketKeyBackfill::class),
			$c->get(DiscoverySettingsRepository::class),
			$c->get(GapSettingsRepository::class),
			$c->get(CompetitorRepository::class),
			$c->get(StrategyConfig::class),
			$c->get(Clock::class),
			$c->get(TopicRefresher::class),
		));
		// Wygląd aplikacji: logo z biblioteki mediów (identyfikator załącznika w opcji), nazwa „Whack-a-mole” w UI.
		$container->singleton(BrandingService::class, static fn (Container $c): BrandingService => new BrandingService($c->get(Logger::class)));
		$container->singleton(StrategyRefreshQueue::class, static fn (Container $c): StrategyRefreshQueue => new StrategyRefreshQueue($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(StrategyRefreshRunner::class, static fn (Container $c): StrategyRefreshRunner => new StrategyRefreshRunner(
			$c->get(Connection::class),
			$c->get(StrategyRefresher::class),
			$c->get(StrategyRefreshQueue::class),
			$c->get(StrategySettingsRepository::class),
			$c->get(Logger::class),
		));
		$container->singleton(StrategyScheduler::class, static fn (Container $c): StrategyScheduler => new StrategyScheduler(
			$c->get(StrategyRefresher::class),
			$c->get(StrategyRefreshRunner::class),
			$c->get(StrategyRefreshQueue::class),
			$c->get(StrategySettingsRepository::class),
			$c->get(SyncRunRepository::class),
			$c->get(ProjectGuard::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));
		$container->singleton(StrategyService::class, static fn (Container $c): StrategyService => new StrategyService(
			$c->get(StrategyRefresher::class),
			$c->get(StrategyKeywordRepository::class),
			$c->get(StrategySettingsRepository::class),
			$c->get(StrategyConfig::class),
			$c->get(KeywordMetricsProvider::class),
			$c->get(MarketMetricsRepository::class),
			$c->get(SerpIntelligence::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
			$c->get(TopicRepository::class),
			$c->get(TopicEventRepository::class),
			new SerpDictionary($c->get(Connection::class), $c->get(Clock::class)),
			new StrategyFreshness($c->get(Connection::class)),
			$c->get(StrategyRefreshQueue::class),
			$c->get(StrategyRefreshRunner::class),
		));

		// Page Intelligence (STEP 17, faza B): pobieranie publicznych stron wyłącznie jawnie, przez transport z przypięciem zweryfikowanego
		// adresu IP (ext-curl, bez WordPress HTTP API — ochrona przed DNS rebinding), z robots.txt i limitami wobec hosta.
		$container->singleton(PageIntelligenceConfig::class, static fn (Container $c): PageIntelligenceConfig => new PageIntelligenceConfig($c->get(Config::class)));
		$container->singleton(PageIntelligenceRepository::class, static fn (Container $c): PageIntelligenceRepository => new PageIntelligenceRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(PageIntelligenceService::class, static function (Container $c): PageIntelligenceService {
			$config = $c->get(PageIntelligenceConfig::class);
			$policy = new UrlSafetyPolicy(new PublicNetworkPolicy(), new SystemHostResolver());
			$fetcher = new CurlPageFetcher($policy, $config->caBundle());

			return new PageIntelligenceService(
				$c->get(PageIntelligenceRepository::class),
				$fetcher,
				new RobotsPolicy($fetcher, new TransientRobotsCache()),
				new HtmlExtractor(),
				$policy,
				$c->get(StrategyService::class),
				$c->get(CompetitorRepository::class),
				$config,
				$c->get(Connection::class),
				$c->get(Clock::class),
				$c->get(Logger::class),
				CurlPageFetcher::available(),
			);
		});

		// Analizy AI (STEP 17, faza A): dostawcy za interfejsem AiProvider, budżet AI oddzielny od DataForSEO; wywołanie modelu wyłącznie
		// z AiAnalysisService na jawne polecenie — nigdy przy renderowaniu panelu, przeliczeniu Strategii, synchronizacji ani w kroku w tle.
		$container->singleton(AiConfig::class, static fn (Container $c): AiConfig => new AiConfig($c->get(Config::class)));
		$container->singleton(AiProviderRegistry::class, static fn (Container $c): AiProviderRegistry => new AiProviderRegistry([
			new FakeProvider(),
			new OpenAiProvider($c->get(AiConfig::class), new WpHttpTransport($c->get(AiConfig::class)->timeout())),
		]));
		$container->singleton(AiRunRepository::class, static fn (Container $c): AiRunRepository => new AiRunRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(AiAnalysisService::class, static fn (Container $c): AiAnalysisService => new AiAnalysisService(
			new AiTopicContextBuilder($c->get(StrategyService::class), $c->get(Connection::class), $c->get(ProjectPageIndex::class), $c->get(PageIntelligenceService::class), $c->get(Clock::class)),
			$c->get(AiProviderRegistry::class),
			$c->get(AiConfig::class),
			new AiPricing($c->get(AiConfig::class)),
			new AiBudget($c->get(AiConfig::class), $c->get(AiRunRepository::class), $c->get(Connection::class), $c->get(Clock::class)),
			$c->get(AiRunRepository::class),
			new OutputValidator(),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));

		$container->singleton(KeywordReport::class, static fn (Container $c): KeywordReport => new KeywordReport(
			$c->get(Connection::class),
			$c->get(Config::class),
			$c->get(KeywordMetricsProvider::class),
			$c->get(MarketMetricsRepository::class),
		));
		$container->singleton(OverviewReport::class, static fn (Container $c): OverviewReport => new OverviewReport($c->get(Connection::class), $c->get(KeywordReport::class), new ReportCache()));

		$container->singleton(SyncConfig::class, static fn (Container $c): SyncConfig => new SyncConfig($c->get(Config::class)));
		$container->singleton(SyncStateRepository::class, static fn (Container $c): SyncStateRepository => new SyncStateRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(SyncRunRepository::class, static fn (Container $c): SyncRunRepository => new SyncRunRepository($c->get(Connection::class), $c->get(Clock::class)));
		$container->singleton(SyncPlanner::class, static fn (Container $c): SyncPlanner => new SyncPlanner(
			$c->get(Connection::class),
			$c->get(ProjectRepository::class),
			$c->get(ConnectionRepository::class),
			$c->get(SyncStateRepository::class),
			$c->get(SyncRunRepository::class),
			$c->get(GscCalendar::class),
			$c->get(SyncConfig::class),
			$c->get(Logger::class),
		));
		$container->singleton(SyncRunner::class, static fn (Container $c): SyncRunner => new SyncRunner(
			$c->get(Connection::class),
			$c->get(SyncRunRepository::class),
			$c->get(SyncStateRepository::class),
			$c->get(SyncPlanner::class),
			$c->get(GscImporter::class),
			$c->get(ProjectGuard::class),
			$c->get(ProjectRepository::class),
			$c->get(ConnectionRepository::class),
			$c->get(Logger::class),
		));
		$container->singleton(SyncService::class, static fn (Container $c): SyncService => new SyncService(
			$c->get(Connection::class),
			$c->get(ProjectRepository::class),
			$c->get(ConnectionRepository::class),
			$c->get(SyncPlanner::class),
			$c->get(SyncRunner::class),
			$c->get(SyncRunRepository::class),
			$c->get(SyncStateRepository::class),
			$c->get(GscCalendar::class),
			$c->get(SyncConfig::class),
		));
		$container->singleton(SyncScheduler::class, static function (Container $c): SyncScheduler {
			$scheduler = new SyncScheduler(
				$c->get(SyncPlanner::class),
				$c->get(SyncRunner::class),
				$c->get(ProjectGuard::class),
				$c->get(Installer::class),
				$c->get(SyncConfig::class),
				$c->get(Logger::class),
			);
			// Szanse SEO przeliczane po imporcie — osobny krok po kolejce, nie część importera.
			$scheduler->onAfterRun(static fn (): array => $c->get(OpportunityScheduler::class)->run((float) $c->get(SyncConfig::class)->timeBudget()));
			// Dane rynkowe (DataForSEO): odbiór wyników i odświeżanie w tle — osobny krok, błąd dostawcy nie dotyka GSC.
			$scheduler->onAfterRun(static fn (): array => $c->get(MarketSyncService::class)->runBackground((float) $c->get(SyncConfig::class)->timeBudget()));
			// Wyszukiwanie nowych fraz: żądania aktywnych przebiegów i przeliczenie kandydatów — osobny krok, błąd nie dotyka GSC.
			$scheduler->onAfterRun(static fn (): array => $c->get(DiscoveryService::class)->runBackground((float) $c->get(SyncConfig::class)->timeBudget()));
			// Luki SEO: strony aktywnych importów, harmonogram (domyślnie wyłączony) i przeliczenie luk — osobny krok, błąd nie dotyka GSC.
			$scheduler->onAfterRun(static fn (): array => $c->get(GapService::class)->runBackground((float) $c->get(SyncConfig::class)->timeBudget()));
			// Pozycje SERP: odbiór wyników (bezpłatny), pomiary z harmonogramu i wysyłka paczek — osobny krok, błąd nie dotyka GSC.
			$scheduler->onAfterRun(static fn (): array => $c->get(SerpTrackingService::class)->runBackground((float) $c->get(SyncConfig::class)->timeBudget()));
			// Strategia: lokalne przeliczenie zapisanych danych (zlecenia z panelu, wykryte zmiany modułów) — ostatni krok, w czasie
			// pozostałym ze wspólnego limitu ticka (D63), bez żadnego żądania do API (D78).
			$scheduler->onAfterRun(static fn (): array => $c->get(StrategyScheduler::class)->runBackground($scheduler->remainingBudget()));
			// Analizy AI: wyłącznie porządki historii (porzucone uruchomienia, retencja raz na dobę) — żadnego wywołania modelu (D94).
			$scheduler->onAfterRun(static fn (): array => $c->get(AiAnalysisService::class)->maintenance());
			// Page Intelligence: wyłącznie retencja zapisanych treści raz na dobę — żadnego pobierania stron w tle (D101).
			$scheduler->onAfterRun(static fn (): array => $c->get(PageIntelligenceService::class)->maintenance() ?? []);

			return $scheduler;
		});

		$container->singleton(OpportunityConfig::class, static fn (Container $c): OpportunityConfig => new OpportunityConfig($c->get(Config::class)));
		$container->singleton(OpportunityRepository::class, static fn (Container $c): OpportunityRepository => new OpportunityRepository(
			$c->get(Connection::class),
			$c->get(ProjectRepository::class),
			$c->get(Clock::class),
		));
		$container->singleton(OpportunityDataSource::class, static fn (Container $c): OpportunityDataSource => new OpportunityDataSource(
			$c->get(Connection::class),
			$c->get(KeywordReport::class),
			$c->get(OverviewReport::class),
		));
		$container->singleton(OpportunityAnalyzer::class, static fn (Container $c): OpportunityAnalyzer => new OpportunityAnalyzer(
			$c->get(Connection::class),
			$c->get(OpportunityDataSource::class),
			$c->get(OpportunityRepository::class),
			$c->get(ProjectRepository::class),
			$c->get(OpportunityConfig::class),
			$c->get(Logger::class),
		));
		$container->singleton(OpportunityService::class, static fn (Container $c): OpportunityService => new OpportunityService(
			$c->get(OpportunityRepository::class),
			$c->get(OpportunityAnalyzer::class),
			$c->get(OpportunityDataSource::class),
			$c->get(Logger::class),
			new ReportCache(),
			$c->get(KeywordMetricsProvider::class),
			$c->get(MarketMetricsRepository::class),
		));
		$container->singleton(OpportunityScheduler::class, static fn (Container $c): OpportunityScheduler => new OpportunityScheduler(
			$c->get(OpportunityAnalyzer::class),
			$c->get(OpportunityRepository::class),
			$c->get(SyncRunRepository::class),
			$c->get(ProjectGuard::class),
			$c->get(Logger::class),
		));

		return $container;
	}

	public function boot(): void
	{
		if ($this->booted) {
			return;
		}

		$this->booted = true;

		$this->get(Installer::class)->maybeUpgrade();

		WpAdminAccess::register();
		$this->get(SyncScheduler::class)->register();

		add_action('deleted_user', function (int $userId): void {
			$this->get(ProjectService::class)->forgetDeletedUser($userId);
		});

		if (defined('WP_CLI') && WP_CLI) {
			StatusCommand::register($this);
			DbCommand::register($this);
			ProjectCommand::register($this);
			GoogleCommand::register($this);
			GscCommand::register($this);
			OpportunityCommand::register($this);
			MarketCommand::register($this);
			DiscoveryCommand::register($this);
			SerpCommand::register($this);
			CompetitorCommand::register($this);
			GapCommand::register($this);
			StrategyCommand::register($this);
			StrategyTopicCommand::register($this);
			SyncCommand::register($this);
			AiCommand::register($this);
			PagesCommand::register($this);
		}
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get(string $id): object
	{
		return $this->container->get($id);
	}

	public function logger(): Logger
	{
		return $this->get(Logger::class);
	}

	public function file(): string
	{
		return $this->file;
	}
}
