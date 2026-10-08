<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Ai;

use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\Contract\AnalysisContract;
use OsfSeo\Ai\Prompt\PromptTemplate;
use OsfSeo\Ai\Provider\AiProviderException;
use OsfSeo\Ai\Provider\AiRequest;
use OsfSeo\Ai\Provider\AiResponse;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Strategy\Topics\TopicStatus;

/**
 * Pełny przepływ analizy AI na dostawcy testowym (20) i historia (7, 8): kontekst deterministyczny z prawdziwej Strategii (1, 4, 5),
 * odpowiedź walidowana po stronie PHP, wejście / odpowiedź / walidacja / decyzja użytkownika rozdzielone, Strategia i status pracy
 * bez zmian, retencja i usuwanie — bez sieci i bez kosztów.
 */
final class AiAnalysisFlowTest extends AiTestCase
{
	public function test_20_fake_provider_runs_the_full_flow_at_zero_cost_without_network(): void
	{
		$context = $this->aiProject();
		$this->strategy->setStatus($context, 'pozycjonowanie stron', TopicStatus::Planned, 'Notatka wewnętrzna');
		$db = self::db();
		$topicsBefore = $db->fetchAll("SELECT * FROM `{$db->table('strategy_topics')}` ORDER BY id");
		$keywordsBefore = $db->fetchAll("SELECT * FROM `{$db->table('strategy_keywords')}` ORDER BY id");
		$requests = count($this->google->requests);
		$strategyContext = $this->strategy->context($context, 'pozycjonowanie stron');

		$plan = $this->ai->plan($context, 'pozycjonowanie stron');
		self::assertTrue($plan->runnable());
		self::assertSame(['fake', FakeProvider::MODEL, false, 0.0], [$plan->provider, $plan->model, $plan->paid, $plan->maxCost]);

		$run = $this->ai->run($context, 'pozycjonowanie stron www');

		self::assertSame(AiRun::STATUS_SUCCEEDED, $run->status);
		self::assertSame(['fake', FakeProvider::MODEL, false], [$run->provider, $run->model, $run->paid]);
		self::assertSame([0.0, 0.0, AiRun::COST_FREE], [$run->chargedCost(), $run->reservedCost, $run->costBasis]);
		self::assertSame([PromptTemplate::TASK, PromptTemplate::VERSION, 1, AnalysisContract::VERSION], [$run->task, $run->promptVersion, $run->contextVersion, $run->contractVersion]);
		self::assertSame($plan->context->fingerprint(), $run->contextFingerprint);
		self::assertSame($strategyContext['evidence_hash'], $run->evidenceHash);
		self::assertSame($strategyContext['topic']['id'], $run->topicPublicId);
		self::assertSame(AiRun::TRIGGER_CLI, $run->triggerType);
		self::assertNotNull($run->finishedAt);
		self::assertGreaterThan(0, (int) $run->inputTokens);
		self::assertSame($requests, count($this->google->requests), 'Dostawca testowy nie wysyła żadnego żądania HTTP.');

		// Model dostał instrukcje aplikacji osobno, a w danych — tylko kontekst tego tematu.
		self::assertCount(1, $this->fake->requests);
		self::assertSame(PromptTemplate::instructions(), $this->fake->requests[0]->instructions);
		self::assertStringContainsString('<evidence_json>', $this->fake->requests[0]->input);
		self::assertStringNotContainsString('Notatka wewnętrzna', $this->fake->requests[0]->input);

		// Wejście, odpowiedź, wynik i walidacja oddzielnie od metadanych.
		$detail = $this->ai->show($context, $run->publicId);
		$payload = $detail['payload'];
		$input = json_decode((string) $payload['input'], true);
		self::assertSame($run->contextFingerprint, $input['context']['fingerprint']);
		self::assertSame(PromptTemplate::inputHash($this->fake->requests[0]->input), $payload['input_hash']);
		self::assertStringStartsWith('[TEST]', (string) $payload['result']['summary']);
		self::assertSame([], $payload['validation']);
		self::assertIsString($payload['output_raw']);
		self::assertNull($run->decision);

		// Decyzja użytkownika osobno od wyniku; Strategia i status pracy bez zmian.
		$decided = $this->ai->decide($context, $run->publicId, AiRun::DECISION_ACCEPTED);
		self::assertSame(AiRun::DECISION_ACCEPTED, $decided->decision);
		self::assertSame($payload['result'], $this->ai->show($context, $run->publicId)['payload']['result']);
		self::assertSame($topicsBefore, $db->fetchAll("SELECT * FROM `{$db->table('strategy_topics')}` ORDER BY id"), 'Historia AI nie zmienia tematów.');
		self::assertSame($keywordsBefore, $db->fetchAll("SELECT * FROM `{$db->table('strategy_keywords')}` ORDER BY id"));
		self::assertSame(TopicStatus::Planned->value, $this->strategy->topic($context, 'pozycjonowanie stron')['topic']->status);

		self::assertSame([$run->publicId], array_map(static fn (AiRun $item): string => $item->publicId, $this->ai->runs($context, 'pozycjonowanie stron')));
		self::assertSame([], $this->ai->runs($context, 'audyt seo'));
		self::assertSame(0.0, $this->ai->budget($context)['spent']['today'], 'Dostawca testowy nie zużywa budżetu.');
	}

	public function test_1_4_5_context_is_deterministic_and_follows_evidence(): void
	{
		$context = $this->aiProject();
		$first = $this->ai->context($context, 'pozycjonowanie stron');
		$second = $this->ai->context($context, 'pozycjonowanie stron www');

		self::assertSame($first->json(), $second->json(), 'Ten sam temat (po frazie członkowskiej) — ten sam kontekst.');
		self::assertSame($first->fingerprint(), $second->fingerprint());

		// Przeliczenie bez zmian danych i upływ czasu w tym samym dniu — bez zmiany odcisku.
		$this->strategy->refresh($context, true);
		$this->clock->advance(3600);
		self::assertSame($first->fingerprint(), $this->ai->context($context, 'pozycjonowanie stron')->fingerprint());

		// Status pracy: odcisk dowodów Strategii bez zmian, kontekst AI (pełne wejście) — zmieniony.
		$this->strategy->setStatus($context, 'pozycjonowanie stron', TopicStatus::InProgress);
		$withStatus = $this->ai->context($context, 'pozycjonowanie stron');
		self::assertSame($first->evidenceHash(), $withStatus->evidenceHash());
		self::assertNotSame($first->fingerprint(), $withStatus->fingerprint());

		// Zmiana dowodów (nowe dane GSC) — zmiana odcisku dowodów i kontekstu.
		$this->gscKeyword($context, 'pozycjonowanie stron', 900, 11.0, self::PAGE, 30, '2026-01-13');
		$this->strategy->refresh($context, true);
		$changed = $this->ai->context($context, 'pozycjonowanie stron');
		self::assertNotSame($withStatus->evidenceHash(), $changed->evidenceHash());
		self::assertNotSame($withStatus->fingerprint(), $changed->fingerprint());

		// Rozdzielone pozycje i jawne braki danych (bez pomiaru SERP i importu Labs).
		$body = $changed->toArray();
		self::assertArrayHasKey('average_position_gsc', $body['keywords'][0]['gsc']);
		self::assertNull($body['evidence']['serp']);
		self::assertContains('no_serp_measurement', $changed->dataGaps());
		self::assertContains('page_content_not_fetched', $changed->dataGaps());
		self::assertContains('page_index_incomplete', $changed->dataGaps());
		self::assertSame('example.pl', $body['project']['domain']);
	}

	public function test_7_8_invalid_or_failing_responses_are_controlled_and_never_stored_as_results(): void
	{
		$context = $this->aiProject();

		// Odpowiedź niebędąca JSON-em.
		$this->fakeResponder = static fn (AiRequest $request): AiResponse => new AiResponse('Oto analiza tematu: warto pisać więcej.', null, 'fake_x', FakeProvider::MODEL);
		$invalid = $this->ai->run($context, 'pozycjonowanie stron');
		self::assertSame([AiRun::STATUS_INVALID, 'contract_invalid', 1], [$invalid->status, $invalid->errorCode, $invalid->validationErrors]);
		$payload = $this->ai->show($context, $invalid->publicId)['payload'];
		self::assertNull($payload['result']);
		self::assertSame([['path' => '$', 'code' => 'invalid_json']], $payload['validation']);
		self::assertSame('Oto analiza tematu: warto pisać więcej.', $payload['output_raw']);

		// Poprawny JSON, ale wymyślone dowody i prognoza ruchu.
		$this->fakeResponder = static function (AiRequest $request): AiResponse {
			$data = FakeProvider::sample($request->hints);
			$data['findings'][0]['evidence_refs'] = ['kw:01INNYPROJEKT0000000000000'];
			$data['recommendations'][0]['impact_rationale'] = 'Ruch wzrośnie o 40%.';

			return new AiResponse((string) json_encode($data), null);
		};
		$made = $this->ai->run($context, 'pozycjonowanie stron');
		self::assertSame(AiRun::STATUS_INVALID, $made->status);
		self::assertSame(['unknown_ref', 'evidence_without_refs', 'numeric_forecast'], array_column($this->ai->show($context, $made->publicId)['payload']['validation'], 'code'));

		// Wyjątek dostawcy (uszkodzona odpowiedź) i błąd nieoczekiwany — kontrolowane, bez wyniku.
		$this->fakeResponder = static fn (AiRequest $request): AiResponse => throw new AiProviderException(AiProviderException::INVALID_RESPONSE, 200);
		$broken = $this->ai->run($context, 'pozycjonowanie stron');
		self::assertSame([AiRun::STATUS_UNCERTAIN, AiProviderException::INVALID_RESPONSE, 0.0], [$broken->status, $broken->errorCode, $broken->chargedCost()]);
		$this->fakeResponder = static fn (AiRequest $request): AiResponse => throw new \RuntimeException('boom');
		$crashed = $this->ai->run($context, 'pozycjonowanie stron');
		self::assertSame([AiRun::STATUS_UNCERTAIN, 'internal_error'], [$crashed->status, $crashed->errorCode]);
		self::assertNull($this->ai->show($context, $crashed->publicId)['payload']['result']);

		// Decyzja tylko dla poprawnego wyniku.
		$this->expectException(AiRefused::class);
		$this->ai->decide($context, $invalid->publicId, AiRun::DECISION_ACCEPTED);
	}

	public function test_history_retention_deletion_and_background_maintenance_without_ai_calls(): void
	{
		$context = $this->aiProject();
		$old = $this->ai->run($context, 'pozycjonowanie stron');
		$this->clock->advance(200 * 86400);
		$recent = $this->ai->run($context, 'audyt seo');
		$calls = count($this->fake->requests);
		$requests = count($this->google->requests);

		// Porzucone uruchomienia: żądanie niewysłane → failed bez kosztu; w toku → uncertain (koszt = rezerwacja).
		$db = self::db();
		$stale = $this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s');
		$db->execute("UPDATE `{$db->table('ai_runs')}` SET status = 'running', started_at = %s, finished_at = NULL WHERE public_id = %s", [$stale, $recent->publicId]);

		$result = $this->ai->maintenance(true);

		self::assertSame(['recovered' => 1, 'purged' => 1], $result, 'Retencja domyślnie 180 dni; uruchomienie w toku nie jest usuwane.');
		self::assertSame($calls, count($this->fake->requests), 'Porządki nie wywołują modelu.');
		self::assertSame($requests, count($this->google->requests));
		self::assertNull($this->aiRuns->find($context->projectId(), $old->publicId));
		$recovered = $this->aiRuns->find($context->projectId(), $recent->publicId);
		self::assertSame([AiRun::STATUS_UNCERTAIN, 'interrupted'], [$recovered?->status, $recovered?->errorCode]);

		// Usunięcie pojedynczego uruchomienia z danymi.
		$this->ai->delete($context, $recent->publicId);
		self::assertSame(0, $this->aiRunCount());
		self::assertSame('0', $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('ai_run_payloads')}`"));

		// Krok w tle (bez `$force`) poza procesem systemowym nic nie robi; Strategia w tle nie tworzy uruchomień AI.
		self::assertSame(['recovered' => 0, 'purged' => null], $this->ai->maintenance());
		$this->strategy->requestRefresh($context);
		$this->strategyScheduler->runBackground(20.0, true);
		self::assertSame(0, $this->aiRunCount());
		self::assertSame($calls, count($this->fake->requests));
	}
}
