<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Ai;

use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\Contract\AnalysisContract;
use OsfSeo\Ai\Provider\AiProviderException;
use OsfSeo\Ai\Provider\AiRequest;
use OsfSeo\Ai\Provider\OpenAiProvider;
use OsfSeo\Http\HttpResponse;
use OsfSeo\Http\TransportException;
use OsfSeo\Tests\Support\AiFakes;
use OsfSeo\Tests\Support\FakeHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Adapter OpenAI (Responses API) na atrapie transportu — bez klucza produkcyjnego i bez sieci: format żądania, brak klucza bez żądania (10),
 * mapowanie błędów na rodzaje rozliczenia (8, 14), odpowiedź strukturalna, odmowa, odpowiedź niepełna, brak sekretów w wyjątkach (15).
 */
final class OpenAiProviderTest extends TestCase
{
	private const ENV = [AiConfig::OPENAI_API_KEY, AiConfig::MODEL, AiConfig::REASONING_EFFORT, AiConfig::PRICE_INPUT, AiConfig::PRICE_OUTPUT, AiConfig::PRICE_CACHED_INPUT, AiConfig::PRICE_CACHE_WRITE];

	protected function tearDown(): void
	{
		foreach (self::ENV as $name) {
			putenv($name);
		}
	}

	public function test_request_body_uses_responses_api_with_strict_schema_and_no_hints(): void
	{
		putenv(AiConfig::OPENAI_API_KEY . '=' . AiFakes::apiKey());
		$http = (new FakeHttpTransport())->pushJson(200, AiFakes::openAiResponse('{"ok":true}', 1200, 300, 200));
		$response = (new OpenAiProvider(new AiConfig(), $http))->generate($this->request(['refs' => ['topic']]));

		self::assertCount(1, $http->requests);
		self::assertSame(['POST', 'https://api.openai.com/v1/responses'], [$http->requests[0]['method'], $http->requests[0]['url']]);
		self::assertSame('Bearer ' . AiFakes::apiKey(), $http->requests[0]['headers']['Authorization']);
		$body = json_decode((string) $http->requests[0]['body'], true);
		self::assertSame(['model', 'instructions', 'input', 'text', 'max_output_tokens', 'store', 'service_tier'], array_keys($body));
		self::assertSame('test-model-1', $body['model']);
		self::assertFalse($body['store']);
		self::assertSame('default', $body['service_tier'], 'Ceny z konfiguracji dotyczą przetwarzania standardowego (nie `auto` z ustawień projektu).');
		self::assertSame(2000, $body['max_output_tokens']);
		self::assertSame(['type' => 'json_schema', 'name' => AnalysisContract::NAME, 'schema' => AnalysisContract::schema(), 'strict' => true], $body['text']['format']);
		self::assertStringNotContainsString('hints', (string) $http->requests[0]['body']);
		self::assertStringNotContainsString(AiFakes::apiKey(), (string) $http->requests[0]['body']);

		self::assertSame('{"ok":true}', $response->text);
		self::assertSame(['input' => 1200, 'cached' => 200, 'cache_write' => 0, 'output' => 300, 'reasoning' => 100], $response->usage?->toArray());
		self::assertSame('test-model-1', $response->model);
		self::assertStringStartsWith('resp_', (string) $response->responseId);
	}

	public function test_temperature_is_sent_only_when_configured(): void
	{
		$request = new AiRequest('test-model-1', 'i', 'x', AnalysisContract::NAME, AnalysisContract::schema(), 500, 0.2);
		self::assertSame(0.2, json_decode(OpenAiProvider::body($request), true)['temperature']);
		self::assertArrayNotHasKey('temperature', json_decode(OpenAiProvider::body($this->request()), true));
	}

	/** Faza E: `reasoning.effort` tylko z konfiguracji (wartości ze specyfikacji), niepoprawna wartość blokuje płatne wywołanie. */
	public function test_reasoning_effort_is_sent_only_when_configured_and_validated(): void
	{
		$request = new AiRequest('test-model-1', 'i', 'x', AnalysisContract::NAME, AnalysisContract::schema(), 500, null, [], 'low');
		self::assertSame(['effort' => 'low'], json_decode(OpenAiProvider::body($request), true)['reasoning']);
		self::assertArrayNotHasKey('reasoning', json_decode(OpenAiProvider::body($this->request()), true));

		putenv(AiConfig::REASONING_EFFORT . '=LOW');
		self::assertSame('low', (new AiConfig())->reasoningEffort());
		putenv(AiConfig::REASONING_EFFORT . '=turbo');
		$config = new AiConfig();
		self::assertNull($config->reasoningEffort());
		self::assertContains('invalid_reasoning_effort', (new OpenAiProvider($config, new FakeHttpTransport()))->problems());
	}

	/** Faza E: wiadomość `commentary` nie jest częścią odpowiedzi — tylko `final_answer`, gdy model oznacza fazy. */
	public function test_only_final_answer_messages_form_the_output(): void
	{
		putenv(AiConfig::OPENAI_API_KEY . '=' . AiFakes::apiKey());
		$json = AiFakes::openAiResponse('{"ok":true}');
		$json['output'][1]['phase'] = 'final_answer';
		array_splice($json['output'], 1, 0, [['type' => 'message', 'id' => 'msg_0', 'role' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => 'Sprawdzam dane…']]]]);
		$json['service_tier'] = 'default';
		$response = (new OpenAiProvider(new AiConfig(), (new FakeHttpTransport())->pushJson(200, $json)))->generate($this->request());

		self::assertSame('{"ok":true}', $response->text);
		self::assertSame('default', $response->serviceTier);
	}

	/** Faza E: zapis do cache (`cache_write_tokens`) jest częścią wejścia — osobna cena tylko z konfiguracji, rezerwacja jako górna granica. */
	public function test_cache_write_tokens_are_parsed_and_priced(): void
	{
		$usage = \OsfSeo\Ai\Provider\AiUsage::fromArray(['input_tokens' => 10000, 'input_tokens_details' => ['cached_tokens' => 2000, 'cache_write_tokens' => 3000], 'output_tokens' => 1000]);
		self::assertSame(['input' => 10000, 'cached' => 2000, 'cache_write' => 3000, 'output' => 1000, 'reasoning' => 0], $usage?->toArray());

		putenv(AiConfig::PRICE_INPUT . '=1');
		putenv(AiConfig::PRICE_OUTPUT . '=4');
		putenv(AiConfig::PRICE_CACHED_INPUT . '=0.1');
		$pricing = new \OsfSeo\Ai\Budget\AiPricing(new AiConfig());
		self::assertSame(round((8000 * 1 + 2000 * 0.1 + 1000 * 4) / 1e6, 6), $pricing->actualCost($usage), 'Bez ceny zapisu — jak zwykłe wejście.');
		self::assertArrayNotHasKey('cache_write', $pricing->prices(), 'Odcisk planu bez nowej ceny pozostaje bez zmian.');

		putenv(AiConfig::PRICE_CACHE_WRITE . '=1.5');
		$pricing = new \OsfSeo\Ai\Budget\AiPricing(new AiConfig());
		self::assertSame(round((5000 * 1 + 2000 * 0.1 + 3000 * 1.5 + 1000 * 4) / 1e6, 6), $pricing->actualCost($usage));
		self::assertSame(round((10000 * 1.5 + 2000 * 4) / 1e6, 6), $pricing->maxCost(10000, 2000), 'Rezerwacja: całe wejście po wyższej cenie.');
	}

	/** Faza E: błąd kodowania treści (niepoprawny UTF-8) — odmowa przed wysłaniem, nigdy puste ciało płatnego żądania. */
	public function test_unencodable_request_is_refused_before_sending(): void
	{
		putenv(AiConfig::OPENAI_API_KEY . '=' . AiFakes::apiKey());
		$http = new FakeHttpTransport();

		try {
			(new OpenAiProvider(new AiConfig(), $http))->generate(new AiRequest('test-model-1', 'i', "zły \xB1 bajt", AnalysisContract::NAME, AnalysisContract::schema(), 500));
			self::fail('Oczekiwana odmowa.');
		} catch (AiProviderException $exception) {
			self::assertSame([AiProviderException::CONFIG, 'request_encoding'], [$exception->kind(), $exception->providerCode()]);
		}

		self::assertSame([], $http->requests);
	}

	public function test_10_missing_key_means_no_request(): void
	{
		putenv(AiConfig::MODEL . '=test-model-1');
		$http = new FakeHttpTransport();
		$provider = new OpenAiProvider(new AiConfig(), $http);

		self::assertSame(['missing_api_key'], $provider->problems());

		try {
			$provider->generate($this->request());
			self::fail('Brak klucza musi przerwać przed żądaniem.');
		} catch (AiProviderException $exception) {
			self::assertSame(AiProviderException::CONFIG, $exception->kind());
			self::assertTrue($exception->notExecuted());
		}

		self::assertSame([], $http->requests);
	}

	/**
	 * @return iterable<string, array{0: HttpResponse|TransportException, 1: string, 2: bool, 3: bool}>
	 */
	public static function failures(): iterable
	{
		$error = static fn (int $status, string $code): HttpResponse => new HttpResponse($status, (string) json_encode(['error' => ['message' => 'Incorrect API key provided: ' . AiFakes::apiKey(), 'type' => 'invalid_request_error', 'param' => null, 'code' => $code]]));
		yield '401 auth' => [$error(401, 'invalid_api_key'), AiProviderException::AUTH, true, false];
		yield '403 auth' => [$error(403, 'unsupported_country_region_territory'), AiProviderException::AUTH, true, false];
		yield '429 rate limit' => [$error(429, 'rate_limit_exceeded'), AiProviderException::RATE_LIMITED, true, false];
		yield '400 rejected' => [$error(400, 'invalid_json_schema'), AiProviderException::REJECTED, true, false];
		yield '404 model' => [$error(404, 'model_not_found'), AiProviderException::REJECTED, true, false];
		yield '500 server' => [$error(500, 'server_error'), AiProviderException::SERVER, false, true];
		yield '503 server' => [new HttpResponse(503, '<html>busy</html>'), AiProviderException::SERVER, false, true];
		yield 'timeout' => [new TransportException('HTTP request to api.openai.com failed (http_request_failed).'), AiProviderException::TRANSPORT, false, true];
		yield '200 not json' => [new HttpResponse(200, '<html>proxy</html>'), AiProviderException::INVALID_RESPONSE, false, true];
	}

	#[DataProvider('failures')]
	public function test_8_14_15_errors_map_to_billing_kinds_without_secrets(HttpResponse|TransportException $reply, string $kind, bool $notExecuted, bool $uncertain): void
	{
		putenv(AiConfig::OPENAI_API_KEY . '=' . AiFakes::apiKey());
		$http = (new FakeHttpTransport())->push($reply);

		try {
			(new OpenAiProvider(new AiConfig(), $http))->generate($this->request());
			self::fail('Oczekiwany wyjątek dostawcy.');
		} catch (AiProviderException $exception) {
			self::assertSame($kind, $exception->kind());
			self::assertSame($notExecuted, $exception->notExecuted());
			self::assertSame($uncertain, $exception->uncertain());
			self::assertStringNotContainsString(AiFakes::apiKey(), $exception->getMessage());
			self::assertStringNotContainsString('Incorrect API key', $exception->getMessage());
		}

		self::assertCount(1, $http->requests, 'Bez ponowień.');
	}

	public function test_model_level_outcomes_carry_usage(): void
	{
		putenv(AiConfig::OPENAI_API_KEY . '=' . AiFakes::apiKey());
		$refusal = AiFakes::openAiResponse('');
		$refusal['output'][1]['content'] = [['type' => 'refusal', 'refusal' => 'I cannot help with that.']];
		$incomplete = AiFakes::openAiResponse('{"summary":', 4000, 2000, 0, 'incomplete');
		$incomplete['incomplete_details'] = ['reason' => 'max_output_tokens'];
		$failed = AiFakes::openAiResponse('', 4000, 0, 0, 'failed');
		$failed['error'] = ['code' => 'server_error', 'message' => 'x'];
		$empty = AiFakes::openAiResponse('   ');

		foreach ([[$refusal, AiProviderException::REFUSED], [$incomplete, AiProviderException::INCOMPLETE], [$failed, AiProviderException::FAILED], [$empty, AiProviderException::INVALID_RESPONSE]] as [$json, $kind]) {
			try {
				(new OpenAiProvider(new AiConfig(), (new FakeHttpTransport())->pushJson(200, $json)))->generate($this->request());
				self::fail('Oczekiwany wyjątek: ' . $kind);
			} catch (AiProviderException $exception) {
				self::assertSame($kind, $exception->kind());
				self::assertNotNull($exception->usage(), 'Zgłoszone zużycie jest rozliczane.');
				self::assertFalse($exception->uncertain());
			}
		}
	}

	/**
	 * @param array<string, mixed> $hints
	 */
	private function request(array $hints = []): AiRequest
	{
		return new AiRequest('test-model-1', 'Instructions', 'Input', AnalysisContract::NAME, AnalysisContract::schema(), 2000, null, $hints);
	}
}
