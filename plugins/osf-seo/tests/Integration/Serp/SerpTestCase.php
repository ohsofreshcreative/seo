<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Serp;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoSerpProvider;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Serp\CompetitorService;
use OsfSeo\Serp\PositionsFilters;
use OsfSeo\Serp\SerpCollector;
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpContextRepository;
use OsfSeo\Serp\SerpDictionary;
use OsfSeo\Serp\SerpPlanner;
use OsfSeo\Serp\SerpReports;
use OsfSeo\Serp\SerpRun;
use OsfSeo\Serp\SerpRunRepository;
use OsfSeo\Serp\SerpSettingsRepository;
use OsfSeo\Serp\SerpSnapshotRepository;
use OsfSeo\Serp\SerpStartResult;
use OsfSeo\Serp\SerpStore;
use OsfSeo\Serp\SerpSubmitter;
use OsfSeo\Serp\SerpTrackingService;
use OsfSeo\Serp\TrackedKeywordRepository;
use OsfSeo\Serp\TrackedKeywordRow;
use OsfSeo\Tests\Integration\Market\MarketTestCase;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Baza testów pozycji SERP: prawdziwy WordPress i baza, DataForSEO Google Organic (task_post / tasks_ready / task_get)
 * przez atrapę `pre_http_request` — żaden request nie wychodzi do sieci, dane logowania są syntetyczne.
 */
abstract class SerpTestCase extends MarketTestCase
{
	protected const SERP_POST = 'https://api.dataforseo.com/v3/serp/google/organic/task_post';

	protected const SERP_READY = 'https://api.dataforseo.com/v3/serp/google/organic/tasks_ready';

	protected const SERP_GET = 'https://api.dataforseo.com/v3/serp/google/organic/task_get/advanced/';

	protected const SERP_TABLES = [
		'serp_contexts',
		'serp_settings',
		'serp_competitors',
		'serp_tracked_keywords',
		'serp_runs',
		'serp_snapshots',
		'serp_results',
		'serp_domains',
		'serp_urls',
		'serp_snippets',
	];

	protected const SERP_ENV = [
		SerpConfig::MAX_KEYWORDS,
		SerpConfig::MIN_RECHECK_HOURS,
		SerpConfig::MAX_POSTS_PER_RUN,
		SerpConfig::COLLECT_PER_RUN,
		SerpConfig::EXPIRE_HOURS,
		DataForSeoConfig::PRICE_SERP_PAGE,
		DataForSeoConfig::PRICE_SERP_NEXT_PAGE,
	];

	/** Koszt zadania TOP100 przy domyślnym cenniku: 0,0006 + 9 × 0,00045. */
	protected const TOP100_COST = 0.00465;

	protected DataForSeoSerpProvider $serpProvider;

	protected TrackedKeywordRepository $tracked;

	protected SerpSnapshotRepository $snapshots;

	protected SerpRunRepository $serpRuns;

	protected SerpSettingsRepository $serpSettings;

	protected SerpContextRepository $contexts;

	protected CompetitorRepository $competitorRepository;

	protected SerpReports $serpReports;

	protected SerpTrackingService $serp;

	protected CompetitorService $competitors;

	/** @var array<string, array{id: string, keyword: string}> tag (ULID pomiaru) → zadanie utworzone przez atrapę */
	protected array $serpTasks = [];

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables(...self::SERP_TABLES);
		$this->resetSerpOptions();
		$this->serpTasks = [];
		// Lista gotowych zadań: domyślnie pusta (odbiór bezpośredni); testy dokładają odpowiedzi przez `on()`.
		$this->google->always(self::SERP_READY, ['status' => 200, 'json' => DataForSeoFakes::serpTasksReady([])]);
	}

	protected function tearDown(): void
	{
		foreach (self::SERP_ENV as $name) {
			putenv($name);
		}

		$this->resetSerpOptions();
		parent::tearDown();
	}

	protected function buildServices(): void
	{
		parent::buildServices();
		$db = self::db();
		$logger = $this->captureLogger();
		$config = new DataForSeoConfig();
		$serpConfig = new SerpConfig();
		$this->serpProvider = new DataForSeoSerpProvider(new DataForSeoClient($config, new WpHttpTransport(), $this->sleeper, $logger), $config);
		$this->tracked = new TrackedKeywordRepository($db, $this->clock);
		$this->snapshots = new SerpSnapshotRepository($db, $this->clock);
		$this->serpRuns = new SerpRunRepository($db, $this->clock, $this->snapshots);
		$this->serpSettings = new SerpSettingsRepository($db, $this->clock);
		$this->contexts = new SerpContextRepository($db, $this->clock);
		$this->competitorRepository = new CompetitorRepository($db, $this->clock);
		$this->serpReports = new SerpReports($db);
		$planner = new SerpPlanner($this->serpProvider, $this->tracked, $serpConfig, $this->market, $this->clock);
		$submitter = new SerpSubmitter($db, $this->serpProvider, $this->tracked, $this->serpRuns, $this->snapshots, $this->contexts, $this->tasks, $this->market, $planner, $this->clock, $logger);
		$collector = new SerpCollector(
			$db,
			$this->serpProvider,
			$this->snapshots,
			$this->serpRuns,
			new SerpStore($db, new SerpDictionary($db, $this->clock), $this->snapshots, $this->tracked, $this->clock),
			$serpConfig,
			$this->market,
			$this->clock,
			$logger,
		);
		$this->serp = new SerpTrackingService(
			$this->serpProvider,
			$planner,
			$submitter,
			$collector,
			$this->tracked,
			$this->serpRuns,
			$this->serpSettings,
			$this->contexts,
			$this->competitorRepository,
			$this->serpReports,
			$serpConfig,
			$this->market,
			$this->marketMetrics,
			$this->guard,
			$db,
			$this->clock,
			$logger,
		);
		$this->competitors = new CompetitorService($this->competitorRepository, $this->serpReports, $this->serp, $logger);
	}

	protected function resetSerpOptions(): void
	{
		$db = self::db();
		$db->execute("DELETE FROM `{$db->optionsTable()}` WHERE option_name LIKE %s OR option_name LIKE %s", ['%osf_seo_serp_%', '%osf_seo_rc_%']);
		wp_cache_flush();
	}

	/**
	 * Projekt (domena example.pl, rynek PL/pl) z monitorowanymi frazami dodanymi ręcznie.
	 *
	 * @param list<string> $keywords
	 */
	protected function trackedProject(array $keywords = ['buty damskie'], string $domain = 'example.pl'): ProjectContext
	{
		$context = $this->readyProject($domain);

		if ($keywords !== []) {
			$this->serp->addKeywords($context, 'manual', $keywords);
		}

		return $context;
	}

	/**
	 * Atrapa task_post: każde zadanie utworzone (20100) z `tag` z żądania; zapamiętuje identyfikatory zadań.
	 *
	 * @param array<string, int> $errors fraza → kod błędu zadania
	 */
	protected function mockSerpPost(array $errors = [], float $costPerTask = self::TOP100_COST): void
	{
		$this->google->always(self::SERP_POST, function (array $request) use ($errors, $costPerTask): array {
			$body = json_decode($request['body'], true);
			$tagErrors = [];

			foreach ($body as $task) {
				if (isset($errors[$task['keyword']])) {
					$tagErrors[$task['tag']] = $errors[$task['keyword']];
				}
			}

			$json = DataForSeoFakes::serpTasksCreated(array_column($body, 'tag'), $costPerTask, $tagErrors);

			foreach ($json['tasks'] as $index => $task) {
				if ($task['status_code'] === 20100) {
					$this->serpTasks[$task['data']['tag']] = ['id' => $task['id'], 'keyword' => $body[$index]['keyword']];
				}
			}

			return ['status' => 200, 'json' => $json];
		});
	}

	/**
	 * Wyniki task_get dla zleconych zadań: fraza → elementy SERP (zadania bez wpisu zostają „w kolejce”).
	 *
	 * @param array<string, list<array<string, mixed>>> $byKeyword
	 */
	protected function mockSerpResults(array $byKeyword, ?string $datetime = null): void
	{
		$datetime ??= $this->clock->now()->format('Y-m-d H:i:s') . ' +00:00';

		foreach ($this->serpTasks as $task) {
			if (array_key_exists($task['keyword'], $byKeyword)) {
				$this->google->json(self::SERP_GET . $task['id'], 200, DataForSeoFakes::serpResult($task['id'], $task['keyword'], $byKeyword[$task['keyword']], $datetime));
			}
		}
	}

	/** Pomiar z CLI: zakolejkowanie (bez API) i wysyłka w bieżącym procesie. */
	protected function queueAndSubmit(ProjectContext $context, ?array $only = null): SerpRun
	{
		$result = $this->serp->start($context, null, null, $only, SerpTrackingService::TRIGGER_CLI);
		self::assertSame(SerpStartResult::QUEUED, $result->status, 'Pomiar zakolejkowany: ' . $result->status . ' ' . ($result->reason ?? ''));
		$this->serp->execute($context, $result->run);

		return $this->serpRuns->findById($result->run->id);
	}

	/**
	 * Pełny pomiar: zlecenie, wyniki (fraza → elementy SERP) i odbiór po 10 minutach.
	 *
	 * @param array<string, list<array<string, mixed>>> $byKeyword
	 */
	protected function measure(ProjectContext $context, array $byKeyword, ?array $only = null): SerpRun
	{
		$this->mockSerpPost();
		$run = $this->queueAndSubmit($context, $only);
		$this->clock->advance(600);
		$this->mockSerpResults($byKeyword);
		$this->serp->collect(60.0);

		return $this->serpRuns->findById($run->id);
	}

	/**
	 * Kolejny pomiar po tygodniu (poza oknem ponownego sprawdzenia i odstępem pomiaru ręcznego).
	 *
	 * @param array<string, list<array<string, mixed>>> $byKeyword
	 */
	protected function measureNextWeek(ProjectContext $context, array $byKeyword): SerpRun
	{
		$this->clock->advance(7 * 86400);

		return $this->measure($context, $byKeyword);
	}

	/**
	 * @return list<array<string, mixed>> treści zleceń task_post (lista zadań w każdym)
	 */
	protected function serpPostBodies(): array
	{
		return array_map(static fn (array $request): array => json_decode($request['body'], true), $this->google->requestsTo(self::SERP_POST));
	}

	protected function row(ProjectContext $context, string $keyword): TrackedKeywordRow
	{
		foreach ($this->serp->positions($context, PositionsFilters::fromInput(['q' => $keyword]))['rows'] as $row) {
			if ($row->keyword === $keyword) {
				return $row;
			}
		}

		self::fail('Brak monitorowanej frazy: ' . $keyword);
	}

	/**
	 * @return list<string>
	 */
	protected function positionsList(ProjectContext $context, array $filters = []): array
	{
		return array_map(static fn (TrackedKeywordRow $row): string => $row->keyword, $this->serp->positions($context, PositionsFilters::fromInput($filters))['rows']);
	}

	/** Blokada trzymana przez inny proces (osobne połączenie z bazą); zwolnienie przez `close()`. */
	protected function holdLock(string $name): \mysqli
	{
		$other = $this->secondConnection();
		$other->query("SELECT GET_LOCK('" . $other->real_escape_string(self::db()->lockName($name)) . "', 0)");

		return $other;
	}

	/** Jawne zwolnienie (samo zamknięcie połączenia zwalnia blokadę asynchronicznie). */
	protected function releaseHeldLock(\mysqli $other, string $name): void
	{
		$other->query("SELECT RELEASE_LOCK('" . $other->real_escape_string(self::db()->lockName($name)) . "')");
		$other->close();
	}

	protected function enableTracking(ProjectContext $context, string $frequency = 'weekly', int $depth = 100): void
	{
		$this->serp->saveSettings($context, ['enabled' => '1', 'frequency' => $frequency, 'device' => 'desktop', 'depth' => (string) $depth, 'confirm' => '1']);
	}
}
