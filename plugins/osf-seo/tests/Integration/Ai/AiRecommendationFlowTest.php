<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Ai;

use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\AiRunNotFound;
use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Budget\AiBudget;
use OsfSeo\Ai\Analysis\Readiness;
use OsfSeo\Ai\Contract\RecommendationContract;
use OsfSeo\Ai\Prompt\AnalysisPrompts;
use OsfSeo\Ai\Provider\AiProviderException;
use OsfSeo\Ai\Provider\AiRequest;
use OsfSeo\Ai\Provider\AiResponse;
use OsfSeo\Ai\Provider\AiUsage;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Provider\OpenAiProvider;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\Roles;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\Topics\TopicStatus;
use OsfSeo\Tests\Integration\PageIntelligence\PageTestCase;
use OsfSeo\Tests\Support\AiFakes;

/**
 * Analizy rekomendacji (STEP 17, faza C) na prawdziwym WordPressie i bazie: pełny przepływ z dostawcą testowym (1, 34), brak i niepełny
 * snapshot strony (2, 3), brief kandydata (4, 5), luki treści z kilkoma konkurentami, bez nich i z nieaktualnym SERP-em (6–9), izolacja
 * projektów (21), uprawnienia (22), płatny dostawca bez klucza, wyłączony, ponad budżet (23–25), równoległe zlecenia (26), zmiana planu
 * między podglądem a wykonaniem (27), timeout i wynik niepewny (28), uszkodzony JSON i zła struktura (29, 30), historia (31), wynik
 * nieaktualny po zmianie dowodów (32), Strategia i status pracy bez zmian (33), zero płatnych żądań i pobrań stron (35).
 */
final class AiRecommendationFlowTest extends PageTestCase
{
	private const TOPIC = 'pozycjonowanie stron';

	public function test_1_34_page_optimization_end_to_end_with_fake_provider_and_full_history(): void
	{
		$context = $this->pagesProject();
		$before = $this->strategy->topic($context, self::TOPIC)['topic'];
		$fetches = count($this->pageFetcher->requests);

		$readiness = $this->ai->readiness($context, self::TOPIC, 'page-optimization');
		self::assertTrue($readiness->runnable(), (string) json_encode($readiness->toArray()));
		self::assertSame('optimize', $readiness->action);

		$plan = $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		self::assertTrue($plan->runnable(), implode(', ', $plan->blockers));
		self::assertSame([AnalysisType::PAGE_OPTIMIZATION, 'page-optimization.v1', RecommendationContract::VERSION, 'pl', false], [$plan->task, $plan->promptVersion, $plan->contractVersion, $plan->language, $plan->paid]);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan->fingerprint());
		self::assertSame($plan->fingerprint(), $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)->fingerprint(), 'Plan deterministyczny.');

		$run = $this->ai->generate($context, self::TOPIC, 'page-optimization', approvedPlan: $plan->fingerprint());

		self::assertSame(AiRun::STATUS_SUCCEEDED, $run->status, (string) $run->errorCode);
		self::assertSame([AnalysisType::PAGE_OPTIMIZATION, 'page-optimization.v1', 3, RecommendationContract::VERSION, $plan->fingerprint(), $readiness->state], [$run->task, $run->promptVersion, $run->contextVersion, $run->contractVersion, $run->planFingerprint, $run->readiness]);
		self::assertSame([0.0, AiRun::COST_FREE], [$run->chargedCost(), $run->costBasis]);
		self::assertStringStartsWith('page:', (string) $run->sources['page_snapshot']['ref']);
		self::assertCount(3, $run->sources['competitor_snapshots']);
		self::assertNotSame($run->sources['competitor_snapshots'][0]['serp_measured_at'], $run->sources['competitor_snapshots'][0]['fetched_at'], 'Data pomiaru SERP osobno od daty pobrania.');
		self::assertSame('optimize', $run->sources['strategy_action']);

		// Żądanie do dostawcy: instrukcje typu w języku projektu, schemat kontraktu v2, dane w osobnych blokach.
		$request = $this->fake->requests[0];
		self::assertSame(AnalysisPrompts::instructions(AnalysisType::PAGE_OPTIMIZATION, 'pl'), $request->instructions);
		self::assertSame(RecommendationContract::NAME, $request->schemaName);
		self::assertStringContainsString('<untrusted_external_texts_json>', $request->input);

		// 31: historia i ponowny odczyt — wynik zwalidowany, wejście i odcisk wejścia zapisane, wynik aktualny.
		$shown = $this->ai->show($context, $run->publicId);
		self::assertSame(AnalysisType::PAGE_OPTIMIZATION, $shown['payload']['result']['analysis_type']);
		self::assertSame([], $shown['payload']['validation']);
		self::assertSame(['stale' => false, 'reason' => null], array_intersect_key($shown['freshness'], ['stale' => 1, 'reason' => 1]));
		self::assertSame(3, json_decode((string) $shown['payload']['input'], true)['context']['context_version']);
		self::assertSame([$run->publicId], array_map(static fn (AiRun $item): string => $item->publicId, $this->ai->runs($context, self::TOPIC)));

		// 33: Strategia, decyzja i status pracy tematu bez zmian; 35: żadnych pobrań stron ani płatnych żądań.
		$after = $this->strategy->topic($context, self::TOPIC)['topic'];
		self::assertSame([$before->action, $before->status, $before->priority, $before->targetUrl, $before->evidenceHash], [$after->action, $after->status, $after->priority, $after->targetUrl, $after->evidenceHash]);
		self::assertCount($fetches, $this->pageFetcher->requests);
		self::assertSame([], $this->openAiRequests());
		self::assertSame([], $this->google->requests, 'Żadnego żądania HTTP (DataForSEO, OpenAI) przy gotowości, planie i generowaniu.');

		// Decyzja użytkownika jest osobno od wyniku i nie zmienia Strategii.
		$this->ai->decide($context, $run->publicId, AiRun::DECISION_ACCEPTED);
		self::assertSame($before->status, $this->strategy->topic($context, self::TOPIC)['topic']->status);
	}

	public function test_2_3_missing_or_incomplete_project_page_and_no_automatic_fetch(): void
	{
		$context = $this->aiProject();

		$readiness = $this->ai->readiness($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		self::assertSame([Readiness::INSUFFICIENT, ['page_not_fetched']], [$readiness->state, $readiness->reasons]);
		self::assertSame(['readiness_insufficient'], $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)->blockers);
		$this->assertRefused('readiness_insufficient', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION));
		self::assertSame([], $this->pageFetcher->requests, 'Gotowość nie pobiera brakującej strony.');
		self::assertSame([[], 0], [$this->fake->requests, $this->aiRunCount()]);

		// Snapshot niekompletny (aplikacja renderowana JavaScriptem) — gotowość częściowa, ograniczenia jawne w wyniku.
		$this->pageFetcher->html(self::PAGE, '<!doctype html><html lang="pl"><head><title>Pozycjonowanie</title></head><body><div id="root"></div>' . str_repeat('<script src="/app.js"></script>', 6) . '</body></html>');
		$this->pageService->fetch($context, PageSelection::topic(self::TOPIC));
		$readiness = $this->ai->readiness($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		self::assertSame(Readiness::PARTIAL, $readiness->state);
		self::assertContains('page_content_incomplete', $readiness->limitations);

		$run = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		self::assertSame([AiRun::STATUS_SUCCEEDED, Readiness::PARTIAL], [$run->status, $run->readiness]);
		self::assertSame('not_detected_unconfirmed', $this->ai->analysisContext($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION)->descriptionStatus());
		self::assertNotSame([], $this->ai->show($context, $run->publicId)['payload']['result']['missing_information']);
	}

	public function test_4_5_new_page_brief_requires_explicit_choice_and_is_a_candidate_page(): void
	{
		$context = $this->aiProject();
		$this->discoveryCandidate($context, 'pozycjonowanie lokalne', 'new', 70);
		$this->strategy->refresh($context);
		$topic = $this->strategy->topic($context, 'pozycjonowanie lokalne')['topic'];
		self::assertContains($topic->action, ['investigate', 'create']);

		// Optymalizowany temat: brief sprzeczny z decyzją Strategii.
		$this->assertRefused('readiness_blocked', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::NEW_PAGE_BRIEF));

		if ($topic->action === 'investigate') {
			$blocked = $this->ai->readiness($context, 'pozycjonowanie lokalne', AnalysisType::NEW_PAGE_BRIEF);
			self::assertSame([Readiness::BLOCKED, ['explicit_choice_required']], [$blocked->state, $blocked->reasons]);
		}

		$readiness = $this->ai->readiness($context, 'pozycjonowanie lokalne', AnalysisType::NEW_PAGE_BRIEF, true);
		self::assertTrue($readiness->runnable(), (string) json_encode($readiness->toArray()));
		self::assertContains('candidate_page', $readiness->notes);
		self::assertContains('page_index_incomplete', $readiness->notes);

		$aiContext = $this->ai->analysisContext($context, 'pozycjonowanie lokalne', AnalysisType::NEW_PAGE_BRIEF, true);
		self::assertSame('Kandydat na nową stronę', $aiContext->body['analysis']['page_label']);
		self::assertNotSame([], array_intersect(['target_none_not_proof', 'target_unknown'], $aiContext->dataGaps()), 'Brak znanej strony jawnie oznaczony.');
		self::assertContains(self::TOPIC, array_column($aiContext->body['site']['pages'], 'label'), 'Inne tematy projektu do kanibalizacji i linkowania.');

		$run = $this->ai->generate($context, 'pozycjonowanie lokalne', AnalysisType::NEW_PAGE_BRIEF, explicit: true);
		$result = $this->ai->show($context, $run->publicId)['payload']['result'];
		self::assertSame(AiRun::STATUS_SUCCEEDED, $run->status, (string) $run->errorCode);
		self::assertTrue($run->sources['explicit']);
		self::assertNotSame('', $result['content_outline']['h1']);
		self::assertStringContainsString('Kandydat na nową stronę', $result['warnings'][0]);
		self::assertSame($topic->action, $this->strategy->topic($context, 'pozycjonowanie lokalne')['topic']->action, 'Wybór jawny nie zmienia decyzji Strategii.');
	}

	public function test_6_7_8_9_content_gap_with_competitors_without_them_and_with_stale_serp(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$this->pageService->fetch($context, PageSelection::topic(self::TOPIC));
		$this->google->requests = [];

		// 7: bez stron konkurencji — niewystarczające (bez automatycznego pobierania SERP-u i stron).
		$readiness = $this->ai->readiness($context, self::TOPIC, AnalysisType::CONTENT_GAP);
		self::assertSame([Readiness::INSUFFICIENT, ['no_competitor_snapshots']], [$readiness->state, $readiness->reasons]);
		self::assertSame([], $this->google->requests);

		// 6: kilku konkurentów z zapisanego pomiaru SERP.
		$context = $this->pagesProject();
		$run = $this->ai->generate($context, self::TOPIC, AnalysisType::CONTENT_GAP);
		$result = $this->ai->show($context, $run->publicId)['payload']['result'];
		self::assertSame(AiRun::STATUS_SUCCEEDED, $run->status, (string) $run->errorCode);
		self::assertNotSame([], $result['content_topics']);
		self::assertSame('hypothesis', $result['content_topics'][1]['basis'], 'Brak tematu w snapshocie to hipoteza.');

		// 8, 9: pomiar SERP sprzed 60 dni — porównanie ograniczone; daty pomiaru i pobrania różne.
		$this->clock->advance(60 * 86400);
		$stale = $this->ai->readiness($context, self::TOPIC, AnalysisType::CONTENT_GAP);
		self::assertSame(Readiness::PARTIAL, $stale->state);
		self::assertContains('competitor_serp_stale', $stale->limitations);
		self::assertContains('page_snapshot_stale', $stale->limitations, 'Snapshot starszy niż okno świeżości.');
		$items = $this->ai->analysisContext($context, self::TOPIC, AnalysisType::CONTENT_GAP)->body['evidence']['competitor_pages']['items'];
		self::assertSame('stale', $items[0]['serp_freshness']);
		self::assertSame([], $this->google->requests);
	}

	public function test_21_22_isolation_and_permissions(): void
	{
		$context = $this->pagesProject();
		$run = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		$second = $this->gapProject(['inny.pl' => 'Inny'], 'drugi-projekt.pl');
		$this->gscKeyword($second, 'kurs fotografii', 400, 9.0, 'https://drugi-projekt.pl/kurs/', 5);
		$this->strategy->refresh($second);
		$topicId = (string) $run->topicPublicId;

		foreach ([
			'readiness' => fn () => $this->ai->readiness($second, $topicId, AnalysisType::PAGE_OPTIMIZATION),
			'context' => fn () => $this->ai->analysisContext($second, $topicId, AnalysisType::PAGE_OPTIMIZATION),
			'plan' => fn () => $this->ai->planAnalysis($second, $topicId, AnalysisType::PAGE_OPTIMIZATION),
			'generate' => fn () => $this->ai->generate($second, $topicId, AnalysisType::PAGE_OPTIMIZATION),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Temat innego projektu: ' . $operation);
			} catch (StrategyNotFound) {
			}
		}

		try {
			$this->ai->show($second, $run->publicId);
			self::fail('Analiza innego projektu.');
		} catch (AiRunNotFound) {
		}

		$foreign = $this->ai->analysisContext($second, 'kurs fotografii', AnalysisType::PAGE_OPTIMIZATION, true)->json();

		foreach (['pozycjonowanie', 'example.pl', 'wynik-1.example', $topicId] as $value) {
			self::assertStringNotContainsString($value, $foreign, 'Kontekst drugiego projektu bez danych pierwszego: ' . $value);
		}

		self::assertSame([], $this->ai->freshness($second, [$run]), 'Aktualność tylko dla uruchomień projektu.');

		// 22: klient projektu nie planuje ani nie uruchamia analiz (także bezpłatnych).
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);

		foreach ([
			'types' => fn () => $this->ai->readiness($clientContext, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION),
			'plan' => fn () => $this->ai->planAnalysis($clientContext, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai'),
			'generate' => fn () => $this->ai->generate($clientContext, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', confirmed: true),
			'fake' => fn () => $this->ai->generate($clientContext, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Klient nie może: ' . $operation);
			} catch (AccessDenied) {
			}
		}

		self::assertSame(1, $this->aiRunCount());
	}

	public function test_23_24_25_paid_provider_refused_without_key_when_disabled_or_over_budget(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi([AiConfig::ENABLED => '']);
		self::assertContains('ai_disabled', $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai')->blockers);

		$this->configureOpenAi([AiConfig::OPENAI_API_KEY => '']);
		$plan = $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai');
		self::assertContains('missing_api_key', $plan->blockers);
		$this->assertRefused('missing_api_key', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', approvedPlan: $plan->fingerprint(), confirmed: true));

		$this->configureOpenAi([AiConfig::MAX_RUN_COST => '0.0001']);
		$plan = $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai');
		self::assertContains(AiBudget::RUN_LIMIT, $plan->blockers);
		$this->assertRefused(AiBudget::RUN_LIMIT, fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', approvedPlan: $plan->fingerprint(), confirmed: true));

		// Bez potwierdzenia albo bez zatwierdzonego planu — odmowa przed jakimkolwiek żądaniem.
		$this->configureOpenAi();
		$plan = $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai');
		self::assertTrue($plan->runnable(), implode(', ', $plan->blockers));
		self::assertGreaterThan(0.0, (float) $plan->maxCost);
		$this->assertRefused('confirmation_required', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', approvedPlan: $plan->fingerprint()));
		$this->assertRefused('plan_approval_required', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', confirmed: true));
		self::assertSame([[], 0], [$this->openAiRequests(), $this->aiRunCount()]);

		// Zatwierdzony plan — jedno żądanie (atrapa HTTP), rozliczenie z zużycia w budżecie AI.
		$this->google->always(OpenAiProvider::ENDPOINT, static fn (array $request): array => ['status' => 200, 'json' => AiFakes::openAiResponse((string) json_encode(self::modelRecommendation($request), JSON_UNESCAPED_UNICODE), 7000, 900)]);
		$run = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', approvedPlan: $plan->fingerprint(), confirmed: true);
		self::assertSame([AiRun::STATUS_SUCCEEDED, AiRun::COST_USAGE], [$run->status, $run->costBasis], (string) $run->errorCode);
		self::assertLessThanOrEqual((float) $plan->maxCost, $run->chargedCost(), 'Nigdy drożej niż zatwierdzony plan.');
		self::assertCount(1, $this->openAiRequests());
		self::assertStringNotContainsString(AiFakes::apiKey(), $this->openAiRequests()[0]['body']);
	}

	public function test_26_parallel_requests_are_refused_and_identical_plan_is_not_repeated_by_accident(): void
	{
		$context = $this->pagesProject();
		$topic = $this->strategy->topic($context, self::TOPIC)['topic'];

		// Drugi proces trzyma blokadę zlecenia (ten sam temat i typ).
		$held = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
		$lock = self::db()->lockName('ai_gen_' . substr(hash('sha256', $context->projectId() . '|' . $topic->id . '|' . AnalysisType::PAGE_OPTIMIZATION), 0, 16));
		$held->query("SELECT GET_LOCK('" . $held->real_escape_string($lock) . "', 0)");

		try {
			$this->assertRefused('run_in_progress', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION));
			// Inny typ tego samego tematu nie jest blokowany.
			self::assertSame(AiRun::STATUS_SUCCEEDED, $this->ai->generate($context, self::TOPIC, AnalysisType::CONTENT_GAP)->status);
		} finally {
			$held->query("SELECT RELEASE_LOCK('" . $held->real_escape_string($lock) . "')");
			$held->close();
		}

		// Uruchomienie w toku w historii (np. proces przerwany w trakcie) — odmowa do czasu odzyskania.
		$this->fakeResponder = function (AiRequest $request) use ($context): AiResponse {
			$this->assertRefused('run_in_progress', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, repeat: true));

			return (new FakeProvider())->generate($request);
		};
		$first = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $first->status);
		$this->fakeResponder = null;

		// Ten sam odcisk planu już wygenerowany — bez przypadkowego powtórzenia; jawne powtórzenie dozwolone.
		$this->assertRefused('already_generated', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION));
		self::assertSame(AiRun::STATUS_SUCCEEDED, $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, repeat: true)->status);
		self::assertSame(3, $this->aiRunCount());
	}

	public function test_27_plan_change_between_preview_and_execution_is_refused(): void
	{
		$context = $this->pagesProject();
		$this->configureOpenAi();
		$this->google->always(OpenAiProvider::ENDPOINT, static fn (array $request): array => ['status' => 200, 'json' => AiFakes::openAiResponse((string) json_encode(self::modelRecommendation($request), JSON_UNESCAPED_UNICODE), 7000, 900)]);
		$approved = $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai')->fingerprint();

		// Zmiana cen (droższy model) po podglądzie.
		putenv(AiConfig::PRICE_OUTPUT . '=8');
		$this->buildAi();
		$this->assertRefused('plan_changed', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', approvedPlan: $approved, confirmed: true));

		// Zmiana modelu.
		putenv(AiConfig::PRICE_OUTPUT . '=4');
		putenv(AiConfig::MODEL . '=test-model-2');
		$this->buildAi();
		$this->assertRefused('plan_changed', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', approvedPlan: $approved, confirmed: true));

		// Zmiana kontekstu (nowy snapshot strony po podglądzie).
		putenv(AiConfig::MODEL . '=test-model-1');
		$this->buildAi();
		self::assertSame($approved, $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai')->fingerprint());
		$this->afterHostInterval();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml('Pozycjonowanie stron — nowa wersja', 'Pozycjonowanie stron dla firm', 4));
		$this->pageService->fetch($context, PageSelection::topic(self::TOPIC), true);
		$this->assertRefused('plan_changed', fn () => $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', approvedPlan: $approved, confirmed: true));

		// Zmiana celu (focus) i zmiana typu też dają inny plan.
		$fresh = $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai');
		self::assertNotSame($fresh->fingerprint(), $this->ai->planAnalysis($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', 'tytuł strony')->fingerprint());
		self::assertSame([[], 0], [$this->openAiRequests(), $this->aiRunCount()], 'Żadnego płatnego wywołania po zmianie planu.');

		$run = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, 'openai', approvedPlan: $fresh->fingerprint(), confirmed: true);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $run->status, (string) $run->errorCode);
	}

	public function test_28_29_30_timeout_uncertain_bad_json_and_wrong_structure_are_controlled(): void
	{
		$context = $this->pagesProject();
		$cases = [
			'timeout' => [static fn (): AiResponse => throw new AiProviderException(AiProviderException::TRANSPORT), AiRun::STATUS_UNCERTAIN, AiProviderException::TRANSPORT],
			'bad json' => [static fn (): AiResponse => new AiResponse('{"contract_version": 2, "analysis_type": "page_', new AiUsage(5000, 20), 'fake_1', FakeProvider::MODEL), AiRun::STATUS_INVALID, 'contract_invalid'],
			'wrong structure' => [static fn (AiRequest $request): AiResponse => new AiResponse((string) json_encode(['contract_version' => 2, 'analysis_type' => 'page_optimization', 'summary' => 'x', 'content_score' => 100]), new AiUsage(5000, 20), 'fake_2', FakeProvider::MODEL), AiRun::STATUS_INVALID, 'contract_invalid'],
			'invented refs' => [static function (AiRequest $request): AiResponse {
				$sample = FakeProvider::recommendations($request->hints);
				$sample['recommendations'][0]['evidence_refs'] = ['page:01ZZZZZZZZZZZZZZZZZZZZZZZZ'];

				return new AiResponse((string) json_encode($sample), new AiUsage(5000, 800), 'fake_3', FakeProvider::MODEL);
			}, AiRun::STATUS_INVALID, 'contract_invalid'],
		];
		$calls = 0;

		foreach ($cases as $label => [$responder, $status, $error]) {
			$this->fakeResponder = $responder;
			$run = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION, repeat: true);
			$calls++;
			$payload = $this->ai->show($context, $run->publicId)['payload'];

			self::assertSame([$status, $error], [$run->status, $run->errorCode], $label);
			self::assertCount($calls, $this->fake->requests, $label . ': jedno wywołanie, bez ponowień i bez „naprawiania” JSON-a.');
			self::assertNull($payload['result'], $label . ': wynik nieopublikowany.');
			self::assertSame([AiRun::COST_FREE, 0.0], [$run->costBasis, $run->chargedCost()], $label);
		}

		self::assertContains(['path' => '$.recommendations[0].evidence_refs[0]', 'code' => 'unknown_ref'], $this->ai->show($context, $this->ai->runs($context)[0]->publicId)['payload']['validation']);
	}

	public function test_32_33_evidence_change_marks_result_stale_and_never_changes_workflow(): void
	{
		$context = $this->pagesProject();
		$run = $this->ai->generate($context, self::TOPIC, AnalysisType::PAGE_OPTIMIZATION);
		self::assertFalse($this->ai->show($context, $run->publicId)['freshness']['stale']);

		// Zmiana statusu pracy tematu nie jest zmianą dowodów.
		$this->strategy->setStatus($context, self::TOPIC, TopicStatus::InProgress);
		self::assertFalse($this->ai->show($context, $run->publicId)['freshness']['stale']);

		// Nowy snapshot strony docelowej = nowe dowody → wynik nieaktualny (zachowany, tylko oznaczony).
		$this->afterHostInterval();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml('Pozycjonowanie stron — zmieniona strona', 'Nowy nagłówek strony', 5));
		$this->pageService->fetch($context, PageSelection::topic(self::TOPIC), true);
		$freshness = $this->ai->show($context, $run->publicId)['freshness'];
		self::assertSame([true, 'evidence_changed'], [$freshness['stale'], $freshness['reason']]);
		self::assertSame(AiRun::STATUS_SUCCEEDED, $this->aiRuns->find($context->projectId(), $run->publicId)?->status);
		self::assertSame([true], array_column($this->ai->freshness($context, $this->ai->runs($context)), 'stale'));
		self::assertSame(TopicStatus::InProgress->value, $this->strategy->topic($context, self::TOPIC)['topic']->status, 'Analiza nie zmienia statusu pracy.');
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
		$results = $this->pageService->fetch($context, PageSelection::serp(self::TOPIC, [1, 2, 3, 6]));
		self::assertSame(['created', 'created', 'created', 'created'], array_column($results, 'outcome'));
		$this->google->requests = [];

		return $context;
	}

	/**
	 * „Model” za atrapą HTTP: poprawna odpowiedź kontraktu v2 zbudowana z dowodów żądania (jak zrobiłby model).
	 *
	 * @param array{body: string} $request
	 * @return array<string, mixed>
	 */
	private static function modelRecommendation(array $request): array
	{
		$input = (string) (json_decode($request['body'], true)['input'] ?? '');
		preg_match('#<evidence_json>\n(.*)\n</evidence_json>#s', $input, $match);
		$evidence = (array) json_decode($match[1] ?? '', true);

		return FakeProvider::recommendations([
			'analysis_type' => $evidence['analysis']['type'] ?? null,
			'refs' => $evidence['refs'] ?? [],
			'action' => $evidence['decision']['action'] ?? null,
			'limitations' => array_column((array) ($evidence['analysis']['limitations'] ?? []), 'code'),
		]);
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
