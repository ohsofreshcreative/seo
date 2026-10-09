<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Ai;

use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Provider\OpenAiProvider;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\PageIntelligence\PageJob;
use OsfSeo\PageIntelligence\PageRefused;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\Sync\SyncScheduler;
use OsfSeo\Tests\Integration\PageIntelligence\PageTestCase;
use OsfSeo\Tests\Support\AiFakes;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Niezawodność i gotowość produkcyjna (STEP 17, faza E) na prawdziwym WordPressie i bazie — bez żadnego prawdziwego wywołania
 * (dostawca testowy, OpenAI za atrapą `pre_http_request`, strony za atrapą transportu):
 *
 * - wyłącznik `OSF_SEO_AI_ENABLED` zatrzymuje zakolejkowane płatne analizy bez wywołania (rezerwacja zwolniona),
 * - tryb kontrolowanego testu (`OSF_SEO_AI_ALLOWED_PROJECTS`, `OSF_SEO_AI_ALLOWED_TYPES`) — przy planie i przy wykonaniu z kolejki,
 * - jedno zatwierdzenie planu = jedno płatne wywołanie (także po wyniku niepewnym), odmowa bez kosztu nie zużywa zatwierdzenia,
 * - błąd jednej pozycji kolejki nie blokuje kolejnych, projekt zarchiwizowany nie wykonuje zleceń,
 * - usunięcie płatnej analizy z bieżącego miesiąca nie zwalnia budżetu, równoległe `ai:run` płacą raz,
 * - zlecenia pobrań: wyłącznie zatwierdzony adres, wygasanie, anulowanie, projekt zarchiwizowany,
 * - diagnostyka zablokowanej kolejki (brak znaku życia tła).
 */
final class AiReliabilityTest extends PageTestCase
{
	private const TOPIC = 'pozycjonowanie stron';

	public function test_kill_switch_stops_queued_paid_runs_without_a_call_and_releases_the_reservation(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$this->mockOpenAiRecommendations();
		$run = $this->queuePaid($context, AnalysisType::PAGE_OPTIMIZATION);
		self::assertGreaterThan(0.0, $this->ai->budget($context)['spent']['today']);

		// Natychmiastowe wyłączenie: OSF_SEO_AI_ENABLED=false w wp-config.php (bez wdrożenia kodu).
		putenv(AiConfig::ENABLED . '=0');
		$this->buildAi();
		$this->runWorker();

		$done = $this->aiRuns->find($context->projectId(), $run->publicId);
		self::assertSame([AiRun::STATUS_FAILED, 'ai_disabled', AiRun::COST_NOT_CHARGED, 0.0, null], [$done?->status, $done?->errorCode, $done?->costBasis, $done?->chargedCost(), $done?->startedAt]);
		self::assertSame(0.0, $this->ai->budget($context)['spent']['today']);
		self::assertSame([], $this->openAiRequests());

		// Powrót do dostawcy testowego bez kosztów — działa niezależnie od wyłącznika.
		$fake = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)['plan'];
		self::assertTrue($fake['runnable']);
		self::assertFalse($fake['paid']);
	}

	public function test_live_test_mode_allows_paid_runs_only_for_the_chosen_project_and_type(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi([AiConfig::ALLOWED_PROJECTS => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
		$plan = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['plan'];
		self::assertContains('project_not_allowed', $plan['blocker_codes']);

		putenv(AiConfig::ALLOWED_PROJECTS . '=' . strtolower($context->publicId()));
		putenv(AiConfig::ALLOWED_TYPES . '=content-gap');
		$this->buildAi();
		$blocked = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['plan'];
		self::assertSame(['type_not_allowed'], array_values(array_intersect($blocked['blocker_codes'], ['project_not_allowed', 'type_not_allowed'])));
		$allowed = $this->workspace->prepare($context, self::TOPIC, AnalysisType::CONTENT_GAP, false, 'openai')['plan'];
		self::assertTrue($allowed['runnable'], implode(', ', $allowed['blocker_codes']));
		self::assertTrue($this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)['plan']['runnable'], 'Dostawca testowy bez ograniczeń trybu testu.');

		// Zakolejkowane w dozwolonym zakresie, zakres zawężony przed krokiem w tle → bez wywołania, rezerwacja zwolniona.
		$this->mockOpenAiRecommendations();
		$run = $this->workspace->queue($context, self::TOPIC, AnalysisType::CONTENT_GAP, $allowed['fingerprint'], 'openai', false, false, true, false);
		putenv(AiConfig::ALLOWED_TYPES . '=page-optimization');
		$this->buildAi();
		$this->runWorker();
		$done = $this->aiRuns->find($context->projectId(), $run->publicId);
		self::assertSame([AiRun::STATUS_FAILED, AiRun::COST_NOT_CHARGED], [$done?->status, $done?->costBasis]);
		self::assertContains($done?->errorCode, ['type_not_allowed', 'plan_changed']);
		self::assertSame([], $this->openAiRequests());
	}

	public function test_one_approval_means_one_paid_call_even_after_an_uncertain_result(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$plan = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['plan']['fingerprint'];

		// Odmowa dostawcy bez kosztu (429) nie zużywa zatwierdzenia — ten sam plan można wysłać ponownie.
		$this->google->on(OpenAiProvider::ENDPOINT, ['status' => 429, 'json' => ['error' => ['code' => 'rate_limit_exceeded', 'type' => 'requests', 'message' => 'x']]]);
		$limited = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', null, false, $plan, true);
		self::assertSame([AiRun::STATUS_FAILED, AiRun::COST_NOT_CHARGED], [$limited->status, $limited->costBasis]);

		// Wynik niepewny (błąd serwera po wysłaniu) — rezerwacja naliczona; ponowne przesłanie tego samego planu odrzucone.
		$this->google->on(OpenAiProvider::ENDPOINT, ['status' => 500, 'json' => ['error' => ['code' => 'server_error', 'type' => 'server_error', 'message' => 'x']]]);
		$uncertain = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', null, false, $plan, true);
		self::assertSame([AiRun::STATUS_UNCERTAIN, AiRun::COST_RESERVATION], [$uncertain->status, $uncertain->costBasis]);
		self::assertCount(2, $this->openAiRequests());

		$this->assertRefused('plan_already_used', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', null, false, $plan, true));
		$this->assertRefused('plan_already_used', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $plan, 'openai', false, false, true, false));
		self::assertSame($uncertain->publicId, $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['used']['id']);
		self::assertCount(2, $this->openAiRequests(), 'Bez trzeciego żądania.');

		// Świadome ponowienie (nowy koszt) — tylko jawnie.
		$this->mockOpenAiRecommendations();
		$repeated = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', null, false, $plan, true, true);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $repeated->status, (string) $repeated->errorCode);
		self::assertCount(3, $this->openAiRequests());
	}

	public function test_a_failing_queue_item_does_not_block_the_next_one(): void
	{
		$context = $this->pagesProject();
		$broken = $this->queueFake($context, AnalysisType::PAGE_OPTIMIZATION);
		$healthy = $this->queueFake($context, AnalysisType::CONTENT_GAP);

		// Uszkodzony odczyt danych pierwszego zlecenia (np. błąd bazy) — wyjątek spoza obsługiwanych odmów.
		$marker = 'run_id = ' . $broken->id . ' AND';
		$filter = static function (string $query) use ($marker): string {
			if (str_contains($query, 'ai_run_payloads') && str_contains($query, $marker)) {
				throw new \RuntimeException('Simulated storage failure.');
			}

			return $query;
		};
		add_filter('query', $filter);

		try {
			$result = $this->runWorker();
		} finally {
			remove_filter('query', $filter);
		}

		self::assertSame(1, $result['processed']);
		$failed = $this->aiRuns->find($context->projectId(), $broken->publicId);
		self::assertSame([AiRun::STATUS_FAILED, 'internal_error', null], [$failed?->status, $failed?->errorCode, $failed?->startedAt]);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $this->aiRuns->find($context->projectId(), $healthy->publicId)?->status);
		self::assertCount(1, $this->fake->requests, 'Zlecenie z błędem nie wywołało dostawcy.');
	}

	public function test_archived_project_does_not_execute_queued_ai_runs_or_page_jobs(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$this->mockOpenAiRecommendations();
		$run = $this->queuePaid($context, AnalysisType::PAGE_OPTIMIZATION);
		$this->afterHostInterval();
		$job = $this->pageJobs->queue($context, PageSelection::topic(self::TOPIC), true, self::TOPIC)['job'];
		$requests = count($this->pageFetcher->requests);
		$db = self::db();
		$db->execute("UPDATE `{$db->table('projects')}` SET status = 'archived' WHERE id = %d", [$context->projectId()]);

		$this->runWorker();
		$this->runPageWorker();

		$done = $this->aiRuns->find($context->projectId(), $run->publicId);
		self::assertSame([AiRun::STATUS_FAILED, 'project_unavailable', AiRun::COST_NOT_CHARGED], [$done?->status, $done?->errorCode, $done?->costBasis]);
		self::assertSame([], $this->openAiRequests());
		$jobRow = $this->pageJobRow($context, $job->publicId);
		self::assertSame([PageJob::STATUS_FAILED, 'project_unavailable'], [$jobRow['status'], $jobRow['error_code']]);
		self::assertCount($requests, $this->pageFetcher->requests);
	}

	public function test_deleting_a_paid_run_of_the_current_month_does_not_free_the_budget(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$this->mockOpenAiRecommendations();
		$plan = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['plan']['fingerprint'];
		$paid = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', null, false, $plan, true);
		$spent = $this->ai->budget($context)['spent']['month'];
		self::assertGreaterThan(0.0, $spent);

		$this->assertRefused('run_counts_toward_budget', fn () => $this->ai->delete($context, $paid->publicId));
		self::assertSame($spent, $this->ai->budget($context)['spent']['month']);

		// Analiza bez kosztu (dostawca testowy) — usuwanie bez ograniczeń.
		$free = $this->ai->generate($context, self::TOPIC, AnalysisType::CONTENT_GAP);
		$this->ai->delete($context, $free->publicId);
		self::assertNull($this->aiRuns->find($context->projectId(), $free->publicId));

		// Po zamknięciu miesiąca koszt nie liczy się już do limitów — usunięcie dozwolone.
		$this->clock->advance(40 * 86400);
		$this->ai->delete($context, $paid->publicId);
		self::assertNull($this->aiRuns->find($context->projectId(), $paid->publicId));
	}

	public function test_parallel_topic_analysis_from_cli_pays_once(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$this->mockOpenAiSuccess();
		$topic = $this->strategy->topic($context, self::TOPIC)['topic'];
		$held = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
		$lock = self::db()->lockName('ai_gen_' . substr(hash('sha256', $context->projectId() . '|' . $topic->id . '|topic_analysis'), 0, 16));
		$held->query("SELECT GET_LOCK('" . $held->real_escape_string($lock) . "', 0)");

		try {
			$this->assertRefused('run_in_progress', fn () => $this->ai->run($context, self::TOPIC, 'openai', null, true));
		} finally {
			$held->query("SELECT RELEASE_LOCK('" . $held->real_escape_string($lock) . "')");
			$held->close();
		}

		self::assertSame([], $this->openAiRequests());
		self::assertSame(AiRun::STATUS_SUCCEEDED, $this->ai->run($context, self::TOPIC, 'openai', null, true)->status);
		self::assertCount(1, $this->openAiRequests());
	}

	public function test_page_job_fetches_only_the_confirmed_address(): void
	{
		$context = $this->pagesProject();
		$this->clock->advance(2 * 86400);
		$job = $this->pageJobs->queue($context, PageSelection::serp(self::TOPIC, [1]), true, self::TOPIC)['job'];
		self::assertSame('https://wynik-1.example/seo/', $job->items[0]['url']);

		// Nowszy pomiar SERP przed krokiem w tle: na pozycji 1 jest inna witryna — nie pobieramy niepotwierdzonego adresu.
		$items = [];

		for ($rank = 1; $rank <= 10; $rank++) {
			$items[] = $rank === 6
				? DataForSeoFakes::serpOrganic($rank, 'example.pl', self::PAGE, $rank)
				: DataForSeoFakes::serpOrganic($rank, 'inny-' . $rank . '.example', 'https://inny-' . $rank . '.example/seo/', $rank);
		}

		$this->measureNextWeek($context, [self::TOPIC => $items]);
		// Pomiar „za tydzień” (odstęp pomiaru ręcznego) — zlecenie traktujemy jak złożone tuż przed nim (inaczej wygasłoby po 24 h).
		$db = self::db();
		$now = $this->clock->now()->format('Y-m-d H:i:s');
		$db->execute("UPDATE `{$db->table('page_jobs')}` SET created_at = %s, run_after = %s WHERE public_id = %s", [$now, $now, $job->publicId]);
		$requests = count($this->pageFetcher->requests);
		$this->runPageWorker();

		$row = $this->pageJobs->job($context, $job->publicId);
		self::assertSame([PageJob::STATUS_COMPLETED, 'refused', 'selection_changed'], [$row->status, $row->items[0]['outcome'], $row->items[0]['error']]);
		self::assertCount($requests, $this->pageFetcher->requests, 'Bez żadnego pobrania.');
	}

	public function test_page_jobs_can_be_cancelled_and_expire_instead_of_waiting_forever(): void
	{
		$context = $this->pagesProject();
		$this->clock->advance(2 * 86400);
		$requests = count($this->pageFetcher->requests);

		$cancelled = $this->pageJobs->queue($context, PageSelection::serp(self::TOPIC, [1]), true, self::TOPIC)['job'];
		self::assertSame(PageJob::STATUS_CANCELLED, $this->pageJobs->cancel($context, $cancelled->publicId)->status);

		try {
			$this->pageJobs->cancel($context, $cancelled->publicId);
			self::fail('Zakończonego zlecenia nie można anulować.');
		} catch (PageRefused $refused) {
			self::assertSame('job_finished', $refused->reason());
		}

		$stale = $this->pageJobs->queue($context, PageSelection::serp(self::TOPIC, [2]), true, self::TOPIC)['job'];
		$this->clock->advance(25 * 3600);
		$this->runPageWorker();

		$row = $this->pageJobRow($context, $stale->publicId);
		self::assertSame([PageJob::STATUS_FAILED, 'job_expired'], [$row['status'], $row['error_code']]);
		self::assertCount($requests, $this->pageFetcher->requests, 'Anulowane i wygasłe zlecenia nic nie pobrały.');
	}

	public function test_stuck_queue_is_visible_when_the_background_step_does_not_run(): void
	{
		$context = $this->pagesProject();
		delete_option(SyncScheduler::BACKGROUND_HEARTBEAT_OPTION);
		$run = $this->queueFake($context, AnalysisType::PAGE_OPTIMIZATION);
		self::assertFalse($this->ai->queueHealth()['stuck'], 'Świeże zlecenie nie jest „zablokowane”.');

		$this->clock->advance(1200);
		$health = $this->ai->queueHealth();
		self::assertSame([1, true, true], [$health['queued'], $health['background']['stale'], $health['stuck']]);

		update_option(SyncScheduler::BACKGROUND_HEARTBEAT_OPTION, $this->clock->now()->format('Y-m-d H:i:s'), false);
		self::assertFalse($this->ai->queueHealth()['stuck'], 'Tło działa — zlecenie po prostu czeka.');
		self::assertSame(AiRun::STATUS_QUEUED, $this->aiRuns->find($context->projectId(), $run->publicId)?->status);
	}

	private function pagesProject(): ProjectContext
	{
		$context = $this->serpProject();

		foreach ([1, 2, 3] as $rank) {
			$this->pageFetcher->html('https://wynik-' . $rank . '.example/seo/', $this->competitorHtml($rank));
		}

		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$this->clock->advance(3600);
		self::assertSame(['created', 'created', 'created', 'created'], array_column($this->pageService->fetch($context, PageSelection::serp(self::TOPIC, [1, 2, 3, 6])), 'outcome'));
		$this->google->requests = [];

		return $context;
	}

	private function queueFake(ProjectContext $context, string $type): AiRun
	{
		$plan = $this->workspace->prepare($context, self::TOPIC, $type)['plan'];
		self::assertTrue($plan['runnable'], implode(', ', $plan['blocker_codes']));

		return $this->workspace->queue($context, self::TOPIC, $type, $plan['fingerprint'], FakeProvider::ID, false, false, false, false);
	}

	private function queuePaid(ProjectContext $context, string $type): AiRun
	{
		$plan = $this->workspace->prepare($context, self::TOPIC, $type, false, 'openai')['plan'];
		self::assertTrue($plan['runnable'], implode(', ', $plan['blocker_codes']));

		return $this->workspace->queue($context, self::TOPIC, $type, $plan['fingerprint'], 'openai', false, false, true, false);
	}

	/**
	 * @return array{processed: int, cancelled: int, recovered: int}
	 */
	private function runWorker(): array
	{
		add_filter('wp_doing_cron', '__return_true');

		try {
			return $this->ai->runQueued(30.0);
		} finally {
			remove_filter('wp_doing_cron', '__return_true');
		}
	}

	/**
	 * @return array{recovered: int, jobs: int, items: int}
	 */
	private function runPageWorker(): array
	{
		add_filter('wp_doing_cron', '__return_true');

		try {
			return $this->pageJobs->runBackground(30.0);
		} finally {
			remove_filter('wp_doing_cron', '__return_true');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pageJobRow(ProjectContext $context, string $jobId): array
	{
		$db = self::db();

		return (array) $db->fetchRow("SELECT status, error_code FROM `{$db->table('page_jobs')}` WHERE project_id = %d AND public_id = %s", [$context->projectId(), $jobId]);
	}

	private function mockOpenAiRecommendations(): void
	{
		$this->google->always(OpenAiProvider::ENDPOINT, static function (array $request): array {
			$input = (string) (json_decode($request['body'], true)['input'] ?? '');
			preg_match('#<evidence_json>\n(.*)\n</evidence_json>#s', $input, $match);
			$evidence = (array) json_decode($match[1] ?? '', true);
			$result = FakeProvider::recommendations([
				'analysis_type' => $evidence['analysis']['type'] ?? null,
				'refs' => $evidence['refs'] ?? [],
				'action' => $evidence['decision']['action'] ?? null,
				'limitations' => array_column((array) ($evidence['analysis']['limitations'] ?? []), 'code'),
			]);

			return ['status' => 200, 'json' => AiFakes::openAiResponse((string) json_encode($result, JSON_UNESCAPED_UNICODE), 7000, 900)];
		});
	}

	private function assertRefused(string $code, \Closure $call): void
	{
		try {
			$call();
			self::fail('Oczekiwana odmowa: ' . $code);
		} catch (AiRefused $refused) {
			self::assertSame($code, $refused->code(), implode(', ', $refused->blockers()));
		}
	}
}
