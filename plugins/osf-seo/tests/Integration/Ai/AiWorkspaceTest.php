<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Ai;

use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\AiRunNotFound;
use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Analysis\Readiness;
use OsfSeo\Ai\Budget\AiBudget;
use OsfSeo\Ai\Provider\AiProviderException;
use OsfSeo\Ai\Provider\AiRequest;
use OsfSeo\Ai\Provider\AiResponse;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Provider\OpenAiProvider;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Ai\Workspace\AiReport;
use OsfSeo\Ai\Workspace\ReportLabels;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\Roles;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Strategy\Topics\TopicStatus;
use OsfSeo\Support\Ulid;
use OsfSeo\Tests\Integration\PageIntelligence\PageTestCase;
use OsfSeo\Tests\Support\AiFakes;

/**
 * Przestrzeń robocza AI w panelu (STEP 17, faza D) na prawdziwym WordPressie i bazie: sekcja tematu i typ zgodny ze Strategią (1, 2),
 * świadomy wybór typu z osobnym potwierdzeniem (3), brak uruchomienia przy danych niewystarczających i zablokowanych (4), gotowość
 * częściowa z ujawnionymi ograniczeniami (5), podgląd bez wywołań (6), plan przeliczany po zatwierdzeniu i odmowa przy zmianie odcisku
 * albo kosztu (7–9), podwójne kliknięcie (10), równoległe kroki w tle (11), wynik niepewny bez ponowień (12), rezerwacja budżetu przy
 * zakolejkowaniu (13), brak klucza i wyłączony dostawca (14, 15), klient (24–26), IDOR (27), historia ze stronicowaniem bez N+1 (29, 30),
 * raport i eksport bez surowego JSON-a i wycieków (31–33), zero płatnych żądań poza jawnie zatwierdzonymi (35).
 */
final class AiWorkspaceTest extends PageTestCase
{
	private const TOPIC = 'pozycjonowanie stron';

	public function test_01_02_topic_section_reads_saved_data_and_recommends_type_compatible_with_strategy(): void
	{
		$context = $this->pagesProject();
		$fetches = count($this->pageFetcher->requests);

		$section = $this->workspace->topicSection($context, self::TOPIC);

		self::assertTrue($section['manage']);
		self::assertSame(['optimize', AnalysisType::PAGE_OPTIMIZATION], [$section['action'], $section['recommended']]);
		self::assertTrue($section['types'][AnalysisType::PAGE_OPTIMIZATION]['recommended']);
		self::assertTrue($section['types'][AnalysisType::PAGE_OPTIMIZATION]['readiness']['runnable']);
		self::assertSame(['blocked', Readiness::BLOCKED], [$section['types'][AnalysisType::NEW_PAGE_BRIEF]['mode'], $section['types'][AnalysisType::NEW_PAGE_BRIEF]['readiness']['state']]);
		self::assertTrue($section['types'][AnalysisType::CONTENT_GAP]['readiness']['runnable']);
		self::assertSame(['fresh', 3], [$section['evidence']['project_page']['cache'], $section['evidence']['competitors']['usable']]);
		self::assertTrue($section['fetch_target']);
		self::assertNotSame([], $section['serp_options']['results'], 'Wyniki zapisanego SERP-u do wyboru stron konkurencji.');
		self::assertNotContains(true, array_column($section['serp_options']['results'], 'project'));

		// Otwarcie tematu: zero wywołań modelu, pobrań stron i żądań HTTP; żadnego zapisu historii.
		self::assertSame([[], [], 0], [$this->fake->requests, $this->google->requests, $this->aiRunCount()]);
		self::assertCount($fetches, $this->pageFetcher->requests);

		// 2: kandydat na nową stronę / temat do sprawdzenia — zalecany brief albo wyłącznie świadomy wybór.
		$this->discoveryCandidate($context, 'pozycjonowanie lokalne', 'new', 70);
		$this->strategy->refresh($context);
		$other = $this->workspace->topicSection($context, 'pozycjonowanie lokalne');

		if ($other['action'] === 'create') {
			self::assertSame(AnalysisType::NEW_PAGE_BRIEF, $other['recommended']);
			self::assertSame('blocked', $other['types'][AnalysisType::PAGE_OPTIMIZATION]['mode']);
		} else {
			self::assertSame(['investigate', null], [$other['action'], $other['recommended']]);
			self::assertSame([true, true, true], array_column($other['types'], 'explicit'));
		}

		self::assertSame([], $this->fake->requests);
	}

	public function test_03_explicit_choice_requires_separate_confirmation_and_is_recorded_without_changing_strategy(): void
	{
		[$context, $topic, $type] = $this->explicitCase();
		$before = $this->strategy->topic($context, $topic)['topic'];

		$blocked = $this->workspace->prepare($context, $topic, $type);
		self::assertSame([Readiness::BLOCKED, false], [$blocked['readiness']['state'], $blocked['explicit']]);
		self::assertContains(ReportLabels::readinessCode('explicit_choice_required'), $blocked['readiness']['reasons']);
		$this->assertRefused('readiness_blocked', fn () => $this->workspace->queue($context, $topic, $type, $blocked['plan']['fingerprint'], FakeProvider::ID, false, false, false, false));

		$prepared = $this->workspace->prepare($context, $topic, $type, true);
		self::assertTrue($prepared['explicit']);
		self::assertTrue($prepared['plan']['runnable'], implode(', ', $prepared['plan']['blocker_codes']));
		self::assertNotSame($blocked['plan']['fingerprint'], $prepared['plan']['fingerprint'], 'Świadomy wybór to inny plan.');
		$this->assertRefused('explicit_confirmation_required', fn () => $this->workspace->queue($context, $topic, $type, $prepared['plan']['fingerprint'], FakeProvider::ID, true, false, false, false));
		self::assertSame(0, $this->aiRunCount());

		$run = $this->workspace->queue($context, $topic, $type, $prepared['plan']['fingerprint'], FakeProvider::ID, true, true, false, false);
		self::assertSame(AiRun::STATUS_QUEUED, $run->status);
		self::assertSame([true, 'explicit'], [$run->sources['explicit'], $run->sources['compatibility']], 'Decyzja zapisana w uruchomieniu.');
		self::assertSame($context->userId(), $run->requestedBy);

		$this->runWorker();
		$done = $this->aiRuns->find($context->projectId(), $run->publicId);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $done?->status, (string) $done?->errorCode);
		$after = $this->strategy->topic($context, $topic)['topic'];
		self::assertSame([$before->action, $before->status], [$after->action, $after->status], 'Świadomy wybór nie zmienia Strategii ani statusu pracy.');
		self::assertTrue($this->workspace->report($context, $run->publicId)['admin']['explicit']);
	}

	public function test_04_blocked_and_insufficient_readiness_cannot_be_queued(): void
	{
		$context = $this->aiProject();

		$prepared = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		self::assertSame(Readiness::INSUFFICIENT, $prepared['readiness']['state']);
		self::assertSame([ReportLabels::readinessCode('page_not_fetched')], $prepared['readiness']['reasons']);
		self::assertSame(['readiness_insufficient'], $prepared['plan']['blocker_codes']);
		$this->assertRefused('readiness_insufficient', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $prepared['plan']['fingerprint'], FakeProvider::ID, false, false, false, false));

		$brief = $this->workspace->prepare($context, self::TOPIC, AnalysisType::NEW_PAGE_BRIEF);
		self::assertSame(Readiness::BLOCKED, $brief['readiness']['state']);
		$this->assertRefused('readiness_blocked', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::NEW_PAGE_BRIEF, $brief['plan']['fingerprint'], FakeProvider::ID, true, true, false, false));
		self::assertSame([[], 0, []], [$this->fake->requests, $this->aiRunCount(), $this->pageFetcher->requests], 'Bez wywołań i bez automatycznego pobierania brakującej strony.');
	}

	public function test_05_partial_readiness_shows_limitations_and_report_discloses_them(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, '<!doctype html><html lang="pl"><head><title>Pozycjonowanie</title></head><body><div id="root"></div>' . str_repeat('<script src="/app.js"></script>', 6) . '</body></html>');
		$this->pageService->fetch($context, PageSelection::topic(self::TOPIC));

		$prepared = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		$limitation = ReportLabels::readinessCode('page_content_incomplete');
		self::assertSame(Readiness::PARTIAL, $prepared['readiness']['state']);
		self::assertContains($limitation, $prepared['readiness']['limitations']);

		$run = $this->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		$this->runWorker();
		$report = $this->workspace->report($context, $run->publicId);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $report['run']['status']);
		self::assertContains($limitation, $report['report']['limitations']);
		self::assertStringContainsString($limitation, $report['exports']['recommendations'], 'Kopiowanie nie gubi ograniczeń.');
	}

	public function test_06_preview_section_history_and_settings_never_call_the_model(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$requests = count($this->pageFetcher->requests);

		$this->workspace->topicSection($context, self::TOPIC);
		$prepared = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai');
		$this->workspace->prepare($context, self::TOPIC, AnalysisType::CONTENT_GAP, false, 'openai');
		$this->workspace->history($context, []);
		$settings = $this->workspace->settings();

		self::assertTrue($prepared['plan']['paid']);
		self::assertGreaterThan(0.0, (float) $prepared['plan']['max_cost']);
		self::assertSame([[], [], 0], [$this->openAiRequests(), $this->fake->requests, $this->aiRunCount()]);
		self::assertCount($requests, $this->pageFetcher->requests);
		self::assertStringNotContainsString(AiFakes::apiKey(), (string) json_encode($settings), 'Ustawienia bez wartości klucza.');
		self::assertSame(true, $settings['providers'][1]['api_key']);
	}

	public function test_07_08_plan_is_recomputed_after_approval_and_a_changed_fingerprint_is_refused(): void
	{
		$context = $this->pagesProject();
		$approved = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)['plan']['fingerprint'];

		// Zmiana treści strony po podglądzie → inny plan; zlecenie ze starym odciskiem odrzucone.
		$this->changeProjectPage($context, 'Pierwsza zmiana');
		$this->assertRefused('plan_changed', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $approved, FakeProvider::ID, false, false, false, false));
		$this->assertRefused('plan_approval_required', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'nie-odcisk', FakeProvider::ID, false, false, false, false));
		self::assertSame(0, $this->aiRunCount());

		// Zakolejkowane z aktualnym odciskiem, ale dane zmieniły się przed krokiem w tle → odmowa bez wywołania.
		$run = $this->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		$this->changeProjectPage($context, 'Druga zmiana');
		self::assertSame(['processed' => 0, 'cancelled' => 1, 'recovered' => 0], $this->runWorker());
		$done = $this->aiRuns->find($context->projectId(), $run->publicId);
		self::assertSame([AiRun::STATUS_FAILED, 'plan_changed', null], [$done?->status, $done?->errorCode, $done?->startedAt]);
		self::assertSame([], $this->fake->requests);
		self::assertSame(ReportLabels::error('plan_changed'), $this->workspace->runStatus($context, $run->publicId)['error']);
	}

	public function test_09_cost_change_after_approval_is_refused_and_reservation_released(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$this->mockOpenAiRecommendations();
		$approved = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['plan'];

		// Droższy model po podglądzie (koszt nie pochodzi z formularza — odcisk przeliczany w usłudze).
		putenv(AiConfig::PRICE_OUTPUT . '=8');
		$this->buildAi();
		$this->assertRefused('plan_changed', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $approved['fingerprint'], 'openai', false, false, true, false));

		// Zakolejkowane po aktualnym planie; cena zmienia się przed krokiem w tle → bez żądania, rezerwacja zwolniona.
		$current = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['plan'];
		$this->assertRefused('confirmation_required', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $current['fingerprint'], 'openai', false, false, false, false));
		$run = $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $current['fingerprint'], 'openai', false, false, true, false);
		self::assertSame([AiRun::STATUS_QUEUED, round((float) $current['max_cost'], 6)], [$run->status, $run->reservedCost]);
		self::assertGreaterThan(0.0, $this->ai->budget($context)['spent']['today'], 'Rezerwacja liczona do budżetu od zakolejkowania.');

		putenv(AiConfig::PRICE_OUTPUT . '=16');
		$this->buildAi();
		$this->runWorker();
		$done = $this->aiRuns->find($context->projectId(), $run->publicId);
		self::assertSame([AiRun::STATUS_FAILED, 'plan_changed', AiRun::COST_NOT_CHARGED, 0.0], [$done?->status, $done?->errorCode, $done?->costBasis, $done?->chargedCost()]);
		self::assertSame(0.0, $this->ai->budget($context)['spent']['today']);
		self::assertSame([], $this->openAiRequests());
	}

	public function test_10_double_click_creates_one_job(): void
	{
		$context = $this->pagesProject();
		$plan = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)['plan']['fingerprint'];

		$first = $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $plan, FakeProvider::ID, false, false, false, false);
		$this->assertRefused('run_in_progress', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $plan, FakeProvider::ID, false, false, false, true));
		self::assertSame(1, $this->aiRunCount());
		self::assertNotNull($this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)['active'], 'Ekran przygotowania pokazuje analizę w toku zamiast formularza.');

		$this->runWorker();
		self::assertCount(1, $this->fake->requests);
		// Ten sam plan już gotowy — bez przypadkowego powtórzenia; jawne „wygeneruj ponownie” tworzy nowe zlecenie.
		$this->assertRefused('already_generated', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $plan, FakeProvider::ID, false, false, false, false));
		self::assertSame($first->publicId, $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)['duplicate']['id']);
		$this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $plan, FakeProvider::ID, false, false, false, true);
		self::assertSame(2, $this->aiRunCount());
	}

	public function test_11_two_parallel_workers_make_one_paid_call(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$this->mockOpenAiRecommendations();
		$plan = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['plan'];
		$run = $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $plan['fingerprint'], 'openai', false, false, true, false);
		$topic = $this->strategy->topic($context, self::TOPIC)['topic'];

		// Drugi proces (inny krok w tle) trzyma blokadę zlecenia — ten nie wykonuje i nie wysyła niczego.
		$held = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
		$lock = self::db()->lockName('ai_gen_' . substr(hash('sha256', $context->projectId() . '|' . $topic->id . '|' . AnalysisType::PAGE_OPTIMIZATION), 0, 16));
		$held->query("SELECT GET_LOCK('" . $held->real_escape_string($lock) . "', 0)");

		try {
			self::assertSame(['processed' => 0, 'cancelled' => 0, 'recovered' => 0], $this->runWorker());
			self::assertSame(AiRun::STATUS_QUEUED, $this->aiRuns->find($context->projectId(), $run->publicId)?->status);
		} finally {
			$held->query("SELECT RELEASE_LOCK('" . $held->real_escape_string($lock) . "')");
			$held->close();
		}

		self::assertSame(1, $this->runWorker()['processed']);
		self::assertSame(0, $this->runWorker()['processed'], 'Kolejny krok w tle nie wykonuje gotowej analizy ponownie.');
		self::assertCount(1, $this->openAiRequests());
		$done = $this->aiRuns->find($context->projectId(), $run->publicId);
		self::assertSame([AiRun::STATUS_SUCCEEDED, AiRun::COST_USAGE], [$done?->status, $done?->costBasis], (string) $done?->errorCode);
		self::assertLessThanOrEqual((float) $plan['max_cost'], $done?->chargedCost(), 'Nigdy drożej niż zatwierdzony plan.');
	}

	public function test_12_timeout_is_uncertain_and_never_retried_by_the_worker(): void
	{
		$context = $this->pagesProject();
		$this->fakeResponder = static fn (): AiResponse => throw new AiProviderException(AiProviderException::TRANSPORT);
		$run = $this->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);

		$this->runWorker();
		$this->runWorker();
		$this->clock->advance(7200);
		$this->runWorker();

		self::assertCount(1, $this->fake->requests, 'Bez automatycznego ponowienia niepewnego wywołania.');
		$status = $this->workspace->runStatus($context, $run->publicId);
		self::assertSame([AiRun::STATUS_UNCERTAIN, false, ReportLabels::status(AiRun::STATUS_UNCERTAIN)], [$status['status'], $status['active'], $status['label']]);
		$row = $this->workspace->history($context, ['status' => 'problem'])['rows'][0];
		self::assertSame([$run->publicId, true, ReportLabels::error('transport')], [$row['id'], $row['uncertain'], $row['error']]);
		self::assertNull($this->workspace->report($context, $run->publicId)['report'], 'Brak wyniku — panel nie udaje raportu.');
	}

	public function test_13_budget_is_reserved_when_queued_and_respected_across_workers(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$this->mockOpenAiRecommendations();
		$maxCost = (float) $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['plan']['max_cost'];
		$this->configureOpenAi([AiConfig::DAILY_LIMIT => (string) round($maxCost * 1.5, 6)]);
		$this->mockOpenAiRecommendations();

		$first = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai')['plan'];
		$this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $first['fingerprint'], 'openai', false, false, true, false);
		$second = $this->workspace->prepare($context, self::TOPIC, AnalysisType::CONTENT_GAP, false, 'openai')['plan'];
		self::assertContains(AiBudget::DAILY, $second['blocker_codes'], 'Rezerwacja zakolejkowanej analizy liczona w budżecie.');
		$this->assertRefused(AiBudget::DAILY, fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::CONTENT_GAP, $second['fingerprint'], 'openai', false, false, true, false));

		self::assertSame(1, $this->runWorker()['processed']);
		self::assertCount(1, $this->openAiRequests());
		self::assertSame(1, $this->aiRunCount());
	}

	public function test_14_15_missing_key_and_disabled_paid_provider(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi([AiConfig::OPENAI_API_KEY => '']);
		$prepared = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai');
		self::assertContains('missing_api_key', $prepared['plan']['blocker_codes']);
		self::assertContains(ReportLabels::error('missing_api_key'), $prepared['plan']['blockers']);
		$this->assertRefused('missing_api_key', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $prepared['plan']['fingerprint'], 'openai', false, false, true, false));
		self::assertFalse($this->workspace->settings()['providers'][1]['api_key']);

		// Wyłączone płatne AI: dostawca płatny niedostępny w panelu (bez obejścia przez formularz).
		$this->configureOpenAi([AiConfig::ENABLED => '']);
		$prepared = $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, false, 'openai');
		self::assertSame([FakeProvider::ID], array_column($prepared['providers'], 'id'));
		self::assertSame(FakeProvider::ID, $prepared['provider'], 'Wybór niedostępnego dostawcy → dostawca testowy.');
		$this->assertRefused('provider_unknown', fn () => $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $prepared['plan']['fingerprint'], 'openai', false, false, true, false));
		self::assertSame([[], 0], [$this->openAiRequests(), $this->aiRunCount()]);
	}

	public function test_24_25_26_client_sees_only_ready_analyses_without_costs_or_diagnostics(): void
	{
		$context = $this->pagesProject();
		$ready = $this->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		$this->runWorker();
		$rejected = $this->queue($context, self::TOPIC, AnalysisType::CONTENT_GAP);
		$this->runWorker();
		$this->ai->decide($context, $rejected->publicId, AiRun::DECISION_REJECTED);
		$this->fakeResponder = static fn (): AiResponse => throw new AiProviderException(AiProviderException::TRANSPORT);
		$uncertain = $this->workspace->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, $this->workspace->prepare($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)['plan']['fingerprint'], FakeProvider::ID, false, false, false, true);
		$this->runWorker();
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);

		// 25: klient widzi gotową analizę (sekcja tematu, historia, raport) bez kosztów, dostawcy, modelu i błędów.
		$section = $this->workspace->topicSection($clientContext, self::TOPIC);
		self::assertSame([false, [$ready->publicId]], [$section['manage'], array_column($section['runs'], 'id')]);
		$history = $this->workspace->history($clientContext, []);
		self::assertSame([[$ready->publicId], 1], [array_column($history['rows'], 'id'), $history['total']]);
		self::assertSame([], $this->workspace->history($clientContext, ['status' => 'problem'])['rows'], 'Filtr statusu nie omija ograniczenia klienta.');
		$report = $this->workspace->report($clientContext, $ready->publicId);
		self::assertNull($report['admin']);
		self::assertNotNull($report['report']);

		// 26: bez kosztów i danych technicznych w danych dla klienta (także eksportach).
		foreach ([$section, $history, $report] as $data) {
			$json = (string) json_encode($data, JSON_UNESCAPED_UNICODE);

			foreach (['"cost', '"provider"', '"model"', '"error"', 'fake-analysis', 'evidence_fingerprint', 'plan_fingerprint', 'output_raw', '"tokens"'] as $needle) {
				self::assertStringNotContainsString($needle, $json, 'Dane klienta: ' . $needle);
			}
		}

		foreach ([$rejected, $uncertain] as $hidden) {
			try {
				$this->workspace->report($clientContext, $hidden->publicId);
				self::fail('Klient nie widzi analizy odrzuconej ani niepewnej.');
			} catch (AiRunNotFound) {
			}
		}

		// 24: klient nie przygotowuje, nie zleca, nie anuluje i nie sprawdza statusu analiz.
		foreach ([
			'prepare' => fn () => $this->workspace->prepare($clientContext, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION),
			'queue' => fn () => $this->workspace->queue($clientContext, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, str_repeat('a', 64), FakeProvider::ID, false, false, false, false),
			'status' => fn () => $this->workspace->runStatus($clientContext, $ready->publicId),
			'cancel' => fn () => $this->ai->cancel($clientContext, $ready->publicId),
			'decide' => fn () => $this->ai->decide($clientContext, $ready->publicId, AiRun::DECISION_REJECTED),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Klient nie może: ' . $operation);
			} catch (AccessDenied) {
			}
		}

		// Temat odrzucony przez agencję — jego analizy znikają z widoku klienta.
		$this->strategy->setStatus($context, self::TOPIC, TopicStatus::Dismissed);
		self::assertSame([], $this->workspace->history($clientContext, [])['rows']);
		self::assertSame(3, $this->aiRunCount());
		self::assertSame([], $this->openAiRequests());
	}

	public function test_27_ai_run_ids_never_cross_projects(): void
	{
		$context = $this->pagesProject();
		$run = $this->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		$this->runWorker();
		$second = $this->gapProject(['inny.pl' => 'Inny'], 'drugi-projekt.pl');

		foreach ([
			'report' => fn () => $this->workspace->report($second, $run->publicId),
			'status' => fn () => $this->workspace->runStatus($second, $run->publicId),
			'cancel' => fn () => $this->ai->cancel($second, $run->publicId),
			'decide' => fn () => $this->ai->decide($second, $run->publicId, AiRun::DECISION_REJECTED),
			'guessed id' => fn () => $this->workspace->report($context, Ulid::generate($this->clock->now())),
			'malformed id' => fn () => $this->workspace->report($context, '../../' . $run->publicId),
		] as $operation => $call) {
			try {
				$call();
				self::fail('IDOR: ' . $operation);
			} catch (AiRunNotFound) {
			}
		}

		self::assertSame(0, $this->workspace->history($second, [])['total']);
		self::assertSame(0, $this->workspace->history($context, ['topic' => $this->strategy->topic($context, self::TOPIC)['topic']->publicId . 'X'])['total']);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $this->aiRuns->find($context->projectId(), $run->publicId)?->status, 'Odmowa w innym projekcie niczego nie zmienia.');
	}

	public function test_29_30_history_is_paginated_in_sql_without_n_plus_one(): void
	{
		global $wpdb;
		$context = $this->pagesProject();

		for ($i = 0; $i < 23; $i++) {
			$this->ai->generate($context, self::TOPIC, $i % 2 === 0 ? AnalysisType::PAGE_OPTIMIZATION : AnalysisType::CONTENT_GAP, repeat: true);
			$this->clock->advance(60);
		}

		$queries = $wpdb->num_queries;
		$first = $this->workspace->history($context, []);
		$firstQueries = $wpdb->num_queries - $queries;
		$queries = $wpdb->num_queries;
		$last = $this->workspace->history($context, [], 2);
		$lastQueries = $wpdb->num_queries - $queries;

		self::assertSame([23, 2, 20, 3], [$first['total'], $first['pages'], count($first['rows']), count($last['rows'])]);
		self::assertSame([], array_intersect(array_column($first['rows'], 'id'), array_column($last['rows'], 'id')));
		self::assertLessThanOrEqual(1, $firstQueries, 'Jedno zapytanie na stronę historii (bez odbudowy kontekstu dla wierszy).');
		self::assertSame($firstQueries, $lastQueries, 'Liczba zapytań nie zależy od liczby wierszy.');
		self::assertSame(12, $this->workspace->history($context, ['type' => AnalysisType::PAGE_OPTIMIZATION])['total']);
		self::assertSame(23, $this->workspace->history($context, ['status' => 'ready'])['total']);
		self::assertSame([false], array_values(array_unique(array_column($first['rows'], 'strategy_changed'))), 'Tani wskaźnik zmian Strategii.');
	}

	public function test_31_32_33_report_is_structured_text_and_exports_do_not_leak(): void
	{
		$context = $this->pagesProject();
		$this->fakeResponder = static function (AiRequest $request): AiResponse {
			$sample = FakeProvider::recommendations($request->hints);
			$sample['summary'] = 'Podsumowanie <script>alert(1)</script> & "cudzysłów"';

			return new AiResponse((string) json_encode($sample, JSON_UNESCAPED_UNICODE), null, 'fake_xss', FakeProvider::MODEL);
		};
		$run = $this->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		$this->runWorker();
		$report = $this->workspace->report($context, $run->publicId);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $report['run']['status'], (string) $report['admin']['error']);

		// 31: dane niezaufane przekazywane jako tekst (escapowanie w widoku `{{ }}`) — bez HTML przygotowanego w usłudze.
		self::assertSame('Podsumowanie <script>alert(1)</script> & "cudzysłów"', $report['report']['summary']);

		// 32: raport to pola do wyświetlenia (etykiety dowodów zamiast kodów) — bez surowej odpowiedzi i JSON-a.
		$top = $report['report']['top'];
		self::assertNotSame([], $top['evidence']);

		foreach (array_column($top['evidence'], 'label') as $label) {
			self::assertDoesNotMatchRegularExpression('/^(kw|page|cpage|serp|site|gap|cg|opp|disc|conflict):/', $label);
		}

		self::assertArrayNotHasKey('output_raw', $report);
		self::assertNotNull($report['report']['intent'] ?? $report['report']['summary']);

		// 33: eksporty to zwykły tekst bez kosztów, dostawcy, modelu, odcisków i kodów technicznych.
		foreach ($report['exports'] as $name => $text) {
			self::assertStringNotContainsString('{"', $text, $name);

			foreach (['USD', FakeProvider::MODEL, 'fingerprint', 'page_optimization', 'evidence_refs', 'kw:', 'cpage:'] as $needle) {
				self::assertStringNotContainsString($needle, $text, $name . ': ' . $needle);
			}
		}

		self::assertStringContainsString('hipotezy do sprawdzenia', $report['exports']['summary']);
		self::assertStringContainsString($top['title'], $report['exports']['recommendations']);
		self::assertSame(AiReport::build(AnalysisType::PAGE_OPTIMIZATION, (array) $this->ai->show($context, $run->publicId)['payload']['result'], [], null)['summary'], $report['report']['summary']);
		self::assertSame([], $this->openAiRequests());
	}

	public function test_22_report_freshness_follows_evidence_changes(): void
	{
		$context = $this->pagesProject();
		$run = $this->queue($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		$this->runWorker();
		self::assertSame('current', $this->workspace->report($context, $run->publicId)['freshness']['state']);
		self::assertSame('current', $this->workspace->topicSection($context, self::TOPIC)['types'][AnalysisType::PAGE_OPTIMIZATION]['latest']['freshness']['state']);

		// Nowa treść strony docelowej → nowe dowody → wynik oznaczony jako nieaktualny (zachowany, bez zmian statusu).
		$this->changeProjectPage($context, 'Zmieniona strona');
		self::assertSame('stale', $this->workspace->report($context, $run->publicId)['freshness']['state']);
		self::assertSame('stale', $this->workspace->topicSection($context, self::TOPIC)['types'][AnalysisType::PAGE_OPTIMIZATION]['latest']['freshness']['state']);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $this->aiRuns->find($context->projectId(), $run->publicId)?->status);
		// Tani wskaźnik historii obejmuje tylko dowody Strategii (treść strony — dokładna aktualność w raporcie).
		self::assertSame([false], array_column($this->workspace->history($context, [])['rows'], 'strategy_changed'));

		// Nowe dane GSC i przeliczenie Strategii → wskaźnik zmian w historii (jedno zapytanie, bez odbudowy kontekstu).
		$this->gscKeyword($context, self::TOPIC, 5000, 4.0, self::PAGE, 400);
		$this->strategy->refresh($context, true);
		self::assertSame([true], array_column($this->workspace->history($context, [])['rows'], 'strategy_changed'));
		self::assertSame(0, $this->pageJobs->statusCounts($context->projectId())['queued'] ?? 0, 'Przeliczenie Strategii nie zleca pobrań stron.');
		self::assertSame([], $this->fake->requests === [] ? [] : array_slice($this->fake->requests, 1), 'Przeliczenie Strategii nie wywołuje modelu.');
	}

	/**
	 * Projekt z pomiarem SERP frazy tematu i zapisanymi snapshotami: strona projektu (#6) i trzy strony konkurencji (#1–#3).
	 */
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

	/**
	 * Temat z typem dostępnym wyłącznie po świadomym wyborze i z wystarczającymi danymi.
	 *
	 * @return array{0: ProjectContext, 1: string, 2: string}
	 */
	private function explicitCase(): array
	{
		$context = $this->aiProject();
		$this->discoveryCandidate($context, 'pozycjonowanie lokalne', 'new', 70);
		$this->strategy->refresh($context);
		$action = $this->strategy->topic($context, 'pozycjonowanie lokalne')['topic']->action;

		if ($action === 'investigate') {
			return [$context, 'pozycjonowanie lokalne', AnalysisType::NEW_PAGE_BRIEF];
		}

		// Temat optymalizowany z ustabilizowaną widocznością → „Monitorowanie”: luka treści tylko świadomie.
		self::markTestSkipped('Brak tematu z typem wymagającym świadomego wyboru w tym zestawie danych (działanie: ' . $action . ').');
	}

	/** Zakolejkowanie analizy dostawcą testowym z aktualnym planem (jak panel po potwierdzeniu). */
	private function queue(ProjectContext $context, string $topic, string $type): AiRun
	{
		$plan = $this->workspace->prepare($context, $topic, $type)['plan'];
		self::assertTrue($plan['runnable'], implode(', ', $plan['blocker_codes']));

		return $this->workspace->queue($context, $topic, $type, $plan['fingerprint'], FakeProvider::ID, false, false, false, false);
	}

	/**
	 * Krok w tle (jak WP-Cron): kolejka analiz AI.
	 *
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

	/** Nowa treść strony docelowej tematu (nowy snapshot) — jawne pobranie jak z CLI. */
	private function changeProjectPage(ProjectContext $context, string $heading): void
	{
		$this->afterHostInterval();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml('Pozycjonowanie stron — ' . $heading, $heading, 5));
		self::assertSame('changed', $this->pageService->fetch($context, PageSelection::topic(self::TOPIC), true)[0]['outcome']);
	}

	/** Atrapa OpenAI za HTTP: poprawna odpowiedź kontraktu v2 zbudowana z dowodów żądania (jak zrobiłby model). */
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
