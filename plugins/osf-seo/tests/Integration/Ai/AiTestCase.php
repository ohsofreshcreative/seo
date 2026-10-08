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
use OsfSeo\Ai\Page\NoPageContentSource;
use OsfSeo\Ai\Provider\AiProviderRegistry;
use OsfSeo\Ai\Provider\AiRequest;
use OsfSeo\Ai\Provider\AiResponse;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Provider\OpenAiProvider;
use OsfSeo\Ai\Run\AiRunRepository;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Strategy\Target\GscPageIndex;
use OsfSeo\Tests\Integration\Strategy\StrategyTestCase;
use OsfSeo\Tests\Support\AiFakes;

/**
 * Baza testów analiz AI (STEP 17, faza A): prawdziwy WordPress i baza, Strategia z danymi modułów, dostawca testowy z podmienialną
 * odpowiedzią i adapter OpenAI za atrapą `pre_http_request` — żaden request nie wychodzi do sieci, klucz jest syntetyczny.
 */
abstract class AiTestCase extends StrategyTestCase
{
	protected const AI_TABLES = ['ai_runs', 'ai_run_payloads'];

	protected const AI_ENV = [
		AiConfig::ENABLED, AiConfig::PROVIDER, AiConfig::MODEL, AiConfig::OPENAI_API_KEY, AiConfig::PRICE_INPUT, AiConfig::PRICE_CACHED_INPUT,
		AiConfig::PRICE_OUTPUT, AiConfig::DAILY_LIMIT, AiConfig::MONTHLY_LIMIT, AiConfig::PROJECT_MONTHLY_LIMIT, AiConfig::MAX_RUN_COST,
		AiConfig::MAX_OUTPUT_TOKENS, AiConfig::TIMEOUT, AiConfig::TEMPERATURE, AiConfig::RETENTION_DAYS,
	];

	protected const PAGE = 'https://example.pl/pozycjonowanie/';

	protected AiAnalysisService $ai;

	protected AiRunRepository $aiRuns;

	protected FakeProvider $fake;

	/** Odpowiedź dostawcy testowego (null = przykładowa odpowiedź zgodna z kontraktem). */
	protected ?Closure $fakeResponder = null;

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables(...self::AI_TABLES);
		delete_transient(AiAnalysisService::MAINTENANCE_TRANSIENT);
		$this->buildAi();
	}

	protected function tearDown(): void
	{
		foreach (self::AI_ENV as $name) {
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
		$this->ai = new AiAnalysisService(
			new AiTopicContextBuilder($this->strategy, $db, new GscPageIndex($db), new NoPageContentSource(), $this->clock),
			new AiProviderRegistry([$this->fake, new OpenAiProvider($config, new WpHttpTransport(10))]),
			$config,
			new AiPricing($config),
			new AiBudget($config, $this->aiRuns, $db, $this->clock, 0),
			$this->aiRuns,
			new OutputValidator(),
			$this->clock,
			$this->captureLogger(),
		);
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
