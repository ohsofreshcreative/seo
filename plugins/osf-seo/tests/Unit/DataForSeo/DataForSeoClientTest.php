<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\DataForSeo;

use InvalidArgumentException;
use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\Http\HttpResponse;
use OsfSeo\Http\TransportException;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Support\Logger;
use OsfSeo\Tests\Support\DataForSeoFakes;
use OsfSeo\Tests\Support\FakeHttpTransport;
use OsfSeo\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataForSeoClientTest extends TestCase
{
	private const PATH = 'keywords_data/google_ads/search_volume/task_post';

	private FakeHttpTransport $http;

	private RecordingSleeper $sleeper;

	/** @var list<string> */
	private array $logLines = [];

	private string $login = '';

	private string $password = '';

	protected function setUp(): void
	{
		[$this->login, $this->password] = DataForSeoFakes::configure();
		$this->http = new FakeHttpTransport();
		$this->sleeper = new RecordingSleeper();
		$this->logLines = [];
	}

	protected function tearDown(): void
	{
		DataForSeoFakes::clear();
	}

	private function client(): DataForSeoClient
	{
		return new DataForSeoClient(new DataForSeoConfig(), $this->http, $this->sleeper, new Logger(Logger::DEBUG, function (string $line): void {
			$this->logLines[] = $line;
		}));
	}

	public function test_basic_auth_header_is_built_from_configured_credentials_for_the_api_host_only(): void
	{
		$this->http->pushJson(200, DataForSeoFakes::taskCreated(DataForSeoFakes::taskId()));

		$this->client()->post(self::PATH, [['keywords' => ['buty'], 'location_code' => 2616, 'language_code' => 'pl']]);

		$request = $this->http->requests[0];
		self::assertSame('POST', $request['method']);
		self::assertSame('https://api.dataforseo.com/v3/' . self::PATH, $request['url']);
		self::assertSame('Basic ' . base64_encode($this->login . ':' . $this->password), $request['headers']['Authorization']);
		self::assertSame('application/json', $request['headers']['Content-Type']);
		self::assertSame([['keywords' => ['buty'], 'location_code' => 2616, 'language_code' => 'pl']], json_decode((string) $request['body'], true));
	}

	public function test_missing_credentials_fail_without_any_request(): void
	{
		DataForSeoFakes::clear();
		putenv(DataForSeoConfig::LOGIN . '=' . DataForSeoFakes::login());

		self::assertSame([DataForSeoConfig::PASSWORD], (new DataForSeoConfig())->missing());

		try {
			$this->client()->post(self::PATH, [['keywords' => ['buty']]]);
			self::fail('Oczekiwano wyjątku.');
		} catch (ProviderException $exception) {
			self::assertSame(ProviderErrorCategory::NotConfigured, $exception->category());
		}

		self::assertSame([], $this->http->requests);
	}

	public function test_only_relative_api_paths_are_allowed(): void
	{
		foreach (['https://evil.example/v3/x', '../secret', 'keywords_data//x', 'keywords_data/x?y=1', '/keywords_data/x'] as $path) {
			try {
				DataForSeoClient::url($path);
				self::fail('Ścieżka powinna zostać odrzucona: ' . $path);
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}

		self::assertSame('https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/task_get/abc-123', DataForSeoClient::url('keywords_data/google_ads/search_volume/task_get/abc-123'));
	}

	/**
	 * @return iterable<string, array{0: int, 1: array<string, mixed>|string, 2: ProviderErrorCategory}>
	 */
	public static function permanentErrors(): iterable
	{
		yield 'HTTP 401 bez treści' => [401, '', ProviderErrorCategory::Authentication];
		yield 'HTTP 401 ze statusem 40100' => [401, ['status_code' => 40100, 'status_message' => 'You are not authorized to access this resource.'], ProviderErrorCategory::Authentication];
		yield 'status 40100 przy HTTP 200' => [200, ['status_code' => 40100, 'status_message' => 'Wrong login or password.'], ProviderErrorCategory::Authentication];
		yield 'konto niezweryfikowane 40104' => [200, ['status_code' => 40104, 'status_message' => 'Please verify your account.'], ProviderErrorCategory::Authentication];
		yield 'brak środków 40210' => [200, ['status_code' => 40210, 'status_message' => 'Insufficient Funds.'], ProviderErrorCategory::Billing];
		yield 'brak środków 40200' => [402, ['status_code' => 40200, 'status_message' => 'Payment Required.'], ProviderErrorCategory::Billing];
		yield 'limit kosztów w panelu 40203' => [200, ['status_code' => 40203, 'status_message' => 'The cost limit has been exceeded.'], ProviderErrorCategory::Billing];
		yield 'nieprawidłowe pole 40501' => [200, ['status_code' => 40501, 'status_message' => 'Invalid Field.'], ProviderErrorCategory::InvalidRequest];
		yield 'HTTP 400' => [400, '', ProviderErrorCategory::InvalidRequest];
	}

	/**
	 * @param array<string, mixed>|string $body
	 */
	#[DataProvider('permanentErrors')]
	public function test_permanent_errors_are_classified_and_never_retried(int $status, array|string $body, ProviderErrorCategory $expected): void
	{
		$this->http->push(new HttpResponse($status, is_array($body) ? (string) json_encode($body) : $body));

		try {
			$this->client()->get('keywords_data/google_ads/search_volume/task_get/' . DataForSeoFakes::taskId());
			self::fail('Oczekiwano wyjątku.');
		} catch (ProviderException $exception) {
			self::assertSame($expected, $exception->category());
			self::assertFalse($exception->isRetryable());
		}

		self::assertCount(1, $this->http->requests, 'Błąd trwały nie jest ponawiany (nawet bezpłatny GET).');
		self::assertSame([], $this->sleeper->sleeps);
	}

	public function test_rate_limit_on_paid_post_is_retried_with_backoff(): void
	{
		$this->http->pushJson(200, ['status_code' => 40202, 'status_message' => 'Rate limit per minute exceeded.']);
		$this->http->pushJson(200, DataForSeoFakes::taskCreated(DataForSeoFakes::taskId()));

		$envelope = $this->client()->post(self::PATH, [['keywords' => ['buty']]]);

		self::assertSame(20000, $envelope['status_code']);
		self::assertCount(2, $this->http->requests);
		self::assertSame([1.0], $this->sleeper->sleeps);
	}

	public function test_retry_after_is_honoured_and_long_delays_are_left_to_the_next_run(): void
	{
		$this->http->push(new HttpResponse(429, '', ['retry-after' => '7']));
		$this->http->pushJson(200, DataForSeoFakes::taskCreated(DataForSeoFakes::taskId()));

		$this->client()->post(self::PATH, [['keywords' => ['buty']]]);
		self::assertSame([7.0], $this->sleeper->sleeps);

		$this->http->push(new HttpResponse(429, '', ['retry-after' => '120']));

		try {
			$this->client()->post(self::PATH, [['keywords' => ['buty']]]);
			self::fail('Oczekiwano wyjątku.');
		} catch (ProviderException $exception) {
			self::assertSame(ProviderErrorCategory::RateLimited, $exception->category());
			self::assertSame(120, $exception->retryAfter());
		}

		self::assertSame([7.0], $this->sleeper->sleeps, 'Retry-After dłuższy niż 10 s — bez czekania w żądaniu.');
	}

	public function test_rate_limit_retries_are_bounded(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->http->pushJson(200, ['status_code' => 40209, 'status_message' => 'Too many simultaneous queries.']);
		}

		try {
			$this->client()->post(self::PATH, [['keywords' => ['buty']]]);
			self::fail('Oczekiwano wyjątku.');
		} catch (ProviderException $exception) {
			self::assertSame(ProviderErrorCategory::RateLimited, $exception->category());
		}

		self::assertCount(3, $this->http->requests);
		self::assertSame([1.0, 3.0], $this->sleeper->sleeps);
	}

	public function test_paid_post_is_not_retried_after_server_or_network_error(): void
	{
		$this->http->push(new HttpResponse(500, 'Internal Server Error'));

		try {
			$this->client()->post(self::PATH, [['keywords' => ['buty']]]);
			self::fail('Oczekiwano wyjątku.');
		} catch (ProviderException $exception) {
			self::assertSame(ProviderErrorCategory::Transient, $exception->category());
			self::assertTrue($exception->isRetryable(), 'Kolejny przebieg może spróbować ponownie.');
		}

		$this->http->push(new TransportException('timeout'));

		try {
			$this->client()->post(self::PATH, [['keywords' => ['buty']]]);
			self::fail('Oczekiwano wyjątku.');
		} catch (ProviderException $exception) {
			self::assertSame(ProviderErrorCategory::Network, $exception->category());
		}

		self::assertCount(2, $this->http->requests, 'Zlecenie mogło zostać utworzone i opłacone — bez ponowienia w żądaniu.');
		self::assertSame([], $this->sleeper->sleeps);
	}

	public function test_free_get_is_retried_after_transient_errors(): void
	{
		$this->http->push(new TransportException('timeout'));
		$this->http->pushJson(200, ['status_code' => 50000, 'status_message' => 'Internal Error.']);
		$this->http->pushJson(200, DataForSeoFakes::taskInQueue(DataForSeoFakes::taskId()));

		$envelope = $this->client()->get('keywords_data/google_ads/search_volume/task_get/' . DataForSeoFakes::taskId());

		self::assertSame(40602, $envelope['tasks'][0]['status_code']);
		self::assertCount(3, $this->http->requests);
		self::assertSame([1.0, 3.0], $this->sleeper->sleeps);
	}

	public function test_malformed_response_is_rejected(): void
	{
		foreach (['<html>Bad gateway</html>', '{"status_code":20000}', '[]', '{"status_code":"20000","tasks":[]}'] as $body) {
			$this->http->push(new HttpResponse(200, $body));

			try {
				$this->client()->post(self::PATH, [['keywords' => ['buty']]]);
				self::fail('Oczekiwano wyjątku dla: ' . $body);
			} catch (ProviderException $exception) {
				self::assertSame(ProviderErrorCategory::MalformedResponse, $exception->category());
			}
		}
	}

	public function test_credentials_never_appear_in_exceptions_or_logs(): void
	{
		$responses = [
			new HttpResponse(401, (string) json_encode(['status_code' => 40100, 'status_message' => 'You are not authorized to access this resource.'])),
			new HttpResponse(200, (string) json_encode(['status_code' => 40210, 'status_message' => 'Insufficient Funds.'])),
			new HttpResponse(500, ''),
			new HttpResponse(200, 'not json'),
		];
		$messages = [];

		foreach ($responses as $response) {
			$this->http->push($response);

			try {
				$this->client()->post(self::PATH, [['keywords' => ['buty']]]);
			} catch (ProviderException $exception) {
				$messages[] = $exception->getMessage() . ' ' . print_r($exception, true);
			}
		}

		$haystack = implode("\n", [...$messages, ...$this->logLines, print_r(new DataForSeoConfig(), true)]);
		self::assertNotSame('', $haystack);
		self::assertStringNotContainsString($this->password, $haystack);
		self::assertStringNotContainsString($this->login, $haystack);
		self::assertStringNotContainsString(base64_encode($this->login . ':' . $this->password), $haystack);
	}
}
