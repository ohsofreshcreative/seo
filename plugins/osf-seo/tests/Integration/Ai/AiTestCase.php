<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Ai;

use Closure;
use OsfSeo\Ai\AiAnalysisService;
use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\Budget\AiBudget;
use OsfSeo\Ai\Budget\AiPricing;
use OsfSeo\Ai\Context\AiTopicContextBuilder;
use OsfSeo\Ai\Contract\OutputValidator;
use OsfSeo\Ai\Provider\AiProviderRegistry;
use OsfSeo\Ai\Provider\AiRequest;
use OsfSeo\Ai\Provider\AiResponse;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Provider\OpenAiProvider;
use OsfSeo\Ai\Run\AiRunRepository;
use OsfSeo\Ai\Workspace\AiWorkspaceService;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\PageIntelligence\Extract\HtmlExtractor;
use OsfSeo\PageIntelligence\Fetch\UrlSafetyPolicy;
use OsfSeo\PageIntelligence\PageIntelligenceConfig;
use OsfSeo\PageIntelligence\PageIntelligenceRepository;
use OsfSeo\PageIntelligence\PageIntelligenceService;
use OsfSeo\PageIntelligence\PageJobRepository;
use OsfSeo\PageIntelligence\PageJobService;
use OsfSeo\PageIntelligence\Robots\RobotsPolicy;
use OsfSeo\Strategy\Target\GscPageIndex;
use OsfSeo\Tests\Integration\Strategy\StrategyTestCase;
use OsfSeo\Tests\Support\AiFakes;
use OsfSeo\Tests\Support\ArrayRobotsCache;
use OsfSeo\Tests\Support\FakePageFetcher;
use OsfSeo\Tests\Support\FixtureNetwork;

/**
 * Baza testów analiz AI (STEP 17, faza A): prawdziwy WordPress i baza, Strategia z danymi modułów, dostawca testowy z podmienialną
 * odpowiedzią i adapter OpenAI za atrapą `pre_http_request` — żaden request nie wychodzi do sieci, klucz jest syntetyczny.
 */
abstract class AiTestCase extends StrategyTestCase
{
	protected const AI_TABLES = ['ai_runs', 'ai_run_payloads'];

	protected const PAGE_TABLES = ['page_targets', 'page_snapshots', 'page_fetches', 'page_serp_links', 'page_jobs'];

	protected const PAGE_ENV = [
		PageIntelligenceConfig::PROJECT_ENABLED, PageIntelligenceConfig::COMPETITORS_ENABLED, PageIntelligenceConfig::TTL_HOURS, PageIntelligenceConfig::MAX_BYTES,
		PageIntelligenceConfig::MAX_URLS, PageIntelligenceConfig::DOMAIN_INTERVAL, PageIntelligenceConfig::DOMAIN_DAILY_LIMIT, PageIntelligenceConfig::RETENTION_DAYS,
		PageIntelligenceConfig::MAX_SNAPSHOTS,
	];

	protected const AI_ENV = [
		AiConfig::ENABLED, AiConfig::PROVIDER, AiConfig::MODEL, AiConfig::OPENAI_API_KEY, AiConfig::PRICE_INPUT, AiConfig::PRICE_CACHED_INPUT,
		AiConfig::PRICE_OUTPUT, AiConfig::DAILY_LIMIT, AiConfig::MONTHLY_LIMIT, AiConfig::PROJECT_MONTHLY_LIMIT, AiConfig::MAX_RUN_COST,
		AiConfig::MAX_OUTPUT_TOKENS, AiConfig::TIMEOUT, AiConfig::TEMPERATURE, AiConfig::RETENTION_DAYS,
	];

	protected const PAGE = 'https://example.pl/pozycjonowanie/';

	protected AiAnalysisService $ai;

	protected AiRunRepository $aiRuns;

	protected FakeProvider $fake;

	protected PageIntelligenceService $pageService;

	protected PageIntelligenceRepository $pageRepository;

	/** Atrapa transportu stron (logika usługi); prawdziwy transport — testy z `FixtureServers`. */
	protected FakePageFetcher $pageFetcher;

	/** Resolver i polityka sieci testów: hosty testowe → 127.0.0.1 („publiczny” w polityce testowej), wewnętrzny → 10.0.0.8. */
	protected FixtureNetwork $pageNetwork;

	/** Odpowiedź dostawcy testowego (null = przykładowa odpowiedź zgodna z kontraktem). */
	protected ?Closure $fakeResponder = null;

	protected AiTopicContextBuilder $contextBuilder;

	/** Zlecenia pobrania stron z panelu (faza D) — na tej samej usłudze Page Intelligence co testy. */
	protected PageJobService $pageJobs;

	/** Przestrzeń robocza AI panelu (faza D). */
	protected AiWorkspaceService $workspace;

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables(...self::AI_TABLES, ...self::PAGE_TABLES);
		delete_transient(AiAnalysisService::MAINTENANCE_TRANSIENT);
		delete_transient(PageIntelligenceService::MAINTENANCE_TRANSIENT);
		$this->pageFetcher = new FakePageFetcher();
		$this->pageNetwork = new FixtureNetwork([
			'example.pl' => ['127.0.0.1'],
			'www.example.pl' => ['127.0.0.1'],
			'konkurent.pl' => ['127.0.0.1'],
			'drugi-projekt.pl' => ['127.0.0.1'],
			'intranet.example.pl' => ['10.0.0.8'],
		], [80, 443]);
		$this->buildAi();
	}

	protected function tearDown(): void
	{
		foreach ([...self::AI_ENV, ...self::PAGE_ENV] as $name) {
			putenv($name);
		}

		parent::tearDown();
	}

	protected function buildAi(): void
	{
		$db = self::db();
		$config = new AiConfig();
		$this->fake = new FakeProvider(fn (AiRequest $request): AiResponse => $this->fakeResponder === null
			? (new FakeProvider())->generate($request)
			: ($this->fakeResponder)($request));
		$this->aiRuns = new AiRunRepository($db, $this->clock);
		$this->buildPages();
		$this->contextBuilder = new AiTopicContextBuilder($this->strategy, $db, new GscPageIndex($db), $this->pageService, $this->clock);
		$this->ai = new AiAnalysisService(
			$this->contextBuilder,
			new AiProviderRegistry([$this->fake, new OpenAiProvider($config, new WpHttpTransport(10))]),
			$config,
			new AiPricing($config),
			new AiBudget($config, $this->aiRuns, $db, $this->clock, 0),
			$this->aiRuns,
			new OutputValidator(),
			$this->clock,
			$this->captureLogger(),
			guard: $this->guard,
		);
		$this->workspace = new AiWorkspaceService($this->contextBuilder, $this->aiRuns, $this->ai, $this->strategy, $this->pageJobs);
	}

	/** Usługa Page Intelligence na atrapie transportu (domyślnie) albo na podanym transporcie. */
	protected function buildPages(?\OsfSeo\PageIntelligence\Fetch\PageFetcher $fetcher = null, ?UrlSafetyPolicy $policy = null, bool $transport = true): void
	{
		$db = self::db();
		$fetcher ??= $this->pageFetcher;
		$this->pageRepository = new PageIntelligenceRepository($db, $this->clock);
		$this->pageService = new PageIntelligenceService(
			$this->pageRepository,
			$fetcher,
			new RobotsPolicy($fetcher, new ArrayRobotsCache()),
			new HtmlExtractor(),
			$policy ?? new UrlSafetyPolicy($this->pageNetwork, $this->pageNetwork),
			$this->strategy,
			$this->competitorRepository,
			new PageIntelligenceConfig(),
			$db,
			$this->clock,
			$this->captureLogger(),
			$transport,
			fn (int $seconds) => $this->clock->advance($seconds),
		);
		$this->pageJobs = new PageJobService($this->pageService, new PageJobRepository($db, $this->clock), new PageIntelligenceConfig(), $this->strategy, $this->guard, $db, $this->captureLogger());
	}

	/** Projekt example.pl z tematem „pozycjonowanie stron” (dwie frazy GSC z tą samą stroną) i tematem „audyt seo”. */
	protected function aiProject(string $domain = 'example.pl'): ProjectContext
	{
		$context = $this->gapProject(['konkurent.pl' => 'Konkurent'], $domain);
		$this->gscKeyword($context, 'pozycjonowanie stron', 600, 14.0, 'https://' . $domain . '/pozycjonowanie/', 12);
		$this->gscKeyword($context, 'pozycjonowanie stron www', 300, 16.0, 'https://' . $domain . '/pozycjonowanie/', 3);
		$this->gscKeyword($context, 'audyt seo', 200, 8.0, 'https://' . $domain . '/audyt/', 4);
		$this->strategy->refresh($context);

		return $context;
	}

	/**
	 * Płatny dostawca świadomie skonfigurowany (syntetyczny klucz, ceny i limity testowe).
	 *
	 * @param array<string, string> $overrides
	 */
	protected function configureOpenAi(array $overrides = []): void
	{
		$values = $overrides + [
			AiConfig::ENABLED => '1',
			AiConfig::PROVIDER => 'openai',
			AiConfig::MODEL => 'test-model-1',
			AiConfig::OPENAI_API_KEY => AiFakes::apiKey(),
			AiConfig::PRICE_INPUT => '1',
			AiConfig::PRICE_OUTPUT => '4',
			AiConfig::DAILY_LIMIT => '1',
			AiConfig::MONTHLY_LIMIT => '5',
			AiConfig::PROJECT_MONTHLY_LIMIT => '5',
			AiConfig::MAX_RUN_COST => '0.5',
		];

		foreach ($values as $name => $value) {
			putenv($value === '' ? $name : $name . '=' . $value);
		}

		$this->buildAi();
	}

	/**
	 * Atrapa „modelu” OpenAI: poprawna odpowiedź kontraktu z odwołaniami odczytanymi z wejścia żądania (jak zrobiłby model).
	 */
	protected function mockOpenAiSuccess(int $input = 6000, int $output = 900): void
	{
		$this->google->always(OpenAiProvider::ENDPOINT, static fn (array $request): array => ['status' => 200, 'json' => AiFakes::openAiResponse(AiFakes::validAnalysis(self::refsFromRequest($request)), $input, $output)]);
	}

	/**
	 * @param array{body: string} $request
	 * @return list<string>
	 */
	protected static function refsFromRequest(array $request): array
	{
		$input = (string) (json_decode($request['body'], true)['input'] ?? '');
		preg_match('#<evidence_json>\n(.*)\n</evidence_json>#s', $input, $match);

		return (array) (json_decode($match[1] ?? '', true)['refs'] ?? []);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function openAiRequests(): array
	{
		return $this->google->requestsTo(OpenAiProvider::ENDPOINT);
	}

	protected function aiRunCount(): int
	{
		$db = self::db();

		return (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('ai_runs')}`");
	}
}
