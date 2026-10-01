<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Gsc;

use OsfSeo\Google\OAuthEndpointError;
use OsfSeo\Google\ReauthorizationRequired;
use OsfSeo\Gsc\ErrorCategory;
use OsfSeo\Gsc\GscApiException;
use OsfSeo\Gsc\GscClient;
use OsfSeo\Http\HttpResponse;
use OsfSeo\Http\TransportException;
use OsfSeo\Support\Logger;
use OsfSeo\Tests\Support\FakeApiRequester;
use OsfSeo\Tests\Support\RecordingSleeper;
use OsfSeo\Tests\Support\SecretSamples;
use PHPUnit\Framework\TestCase;

final class GscClientSitesTest extends TestCase
{
	private FakeApiRequester $api;

	private RecordingSleeper $sleeper;

	/** @var list<string> */
	private array $logs = [];

	private GscClient $client;

	protected function setUp(): void
	{
		$this->api = new FakeApiRequester();
		$this->sleeper = new RecordingSleeper();
		$this->client = new GscClient($this->api, $this->sleeper, new Logger(Logger::DEBUG, function (string $line): void {
			$this->logs[] = $line;
		}));
	}

	public function test_lists_domain_and_url_prefix_properties(): void
	{
		$this->api->json(200, ['siteEntry' => [
			['siteUrl' => 'sc-domain:example.pl', 'permissionLevel' => 'siteOwner'],
			['siteUrl' => 'https://www.example.pl/', 'permissionLevel' => 'siteFullUser'],
			['siteUrl' => 'http://shop.example.pl/', 'permissionLevel' => 'siteUnverifiedUser'],
		]]);

		$properties = $this->client->listSites(FakeApiRequester::connection());

		self::assertSame(['sc-domain:example.pl', 'https://www.example.pl/', 'http://shop.example.pl/'], array_map(static fn ($p) => $p->siteUrl, $properties));
		self::assertSame(['siteOwner', 'siteFullUser', 'siteUnverifiedUser'], array_map(static fn ($p) => $p->permissionLevel, $properties));
		self::assertSame([['method' => 'GET', 'url' => 'https://www.googleapis.com/webmasters/v3/sites', 'json' => null]], $this->api->requests);
		self::assertSame(1, $this->client->requestCount());
	}

	public function test_account_without_properties_returns_empty_list(): void
	{
		$this->api->json(200, []);

		self::assertSame([], $this->client->listSites(FakeApiRequester::connection()));
	}

	public function test_invalid_entries_are_skipped_but_valid_ones_kept(): void
	{
		$this->api->json(200, ['siteEntry' => [
			['siteUrl' => 'sc-domain:example.pl', 'permissionLevel' => 'siteOwner'],
			['siteUrl' => 'javascript:alert(1)', 'permissionLevel' => 'siteOwner'],
		]]);

		self::assertCount(1, $this->client->listSites(FakeApiRequester::connection()));
		self::assertStringContainsString('Skipped an invalid Search Console siteEntry', implode("\n", $this->logs));
	}

	public function test_malformed_response_is_reported(): void
	{
		$this->api->json(200, ['siteEntry' => ['not-an-object']]);

		try {
			$this->client->listSites(FakeApiRequester::connection());
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::Malformed, $exception->category());
			self::assertTrue($exception->isRetryable());
		}

		$this->api->push(new HttpResponse(200, '<html>not json</html>'));
		$this->expectExceptionObject(GscApiException::malformed('Response body is not a JSON object.'));
		$this->client->listSites(FakeApiRequester::connection());
	}

	public function test_rate_limit_and_server_errors_are_retried_with_bounded_backoff(): void
	{
		$this->api
			->json(429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded.']])
			->json(503, ['error' => ['code' => 503, 'status' => 'UNAVAILABLE']])
			->json(200, ['siteEntry' => [['siteUrl' => 'sc-domain:example.pl', 'permissionLevel' => 'siteOwner']]]);

		self::assertCount(1, $this->client->listSites(FakeApiRequester::connection()));
		self::assertSame([1.0, 3.0], $this->sleeper->sleeps);
		self::assertSame(3, $this->client->requestCount());
	}

	public function test_retries_stop_after_limit_and_error_keeps_category(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->api->json(500, ['error' => ['code' => 500, 'status' => 'INTERNAL']]);
		}

		try {
			$this->client->listSites(FakeApiRequester::connection());
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::Transient, $exception->category());
			self::assertSame(500, $exception->status());
			self::assertTrue($exception->isRetryable(), 'Kolejka może ponowić zadanie później.');
		}

		self::assertCount(3, $this->api->requests);
		self::assertSame([1.0, 3.0], $this->sleeper->sleeps);
	}

	public function test_short_retry_after_is_honoured_and_long_one_is_left_to_the_queue(): void
	{
		$this->api
			->json(429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], ['retry-after' => '7'])
			->json(200, []);
		$this->client->listSites(FakeApiRequester::connection());
		self::assertSame([7.0], $this->sleeper->sleeps);

		$this->api->json(429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], ['retry-after' => '120']);

		try {
			$this->client->listSites(FakeApiRequester::connection());
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::RateLimited, $exception->category());
			self::assertSame(120, $exception->retryAfter());
		}

		self::assertSame([7.0], $this->sleeper->sleeps, 'Długi Retry-After nie usypia procesu.');
	}

	public function test_permission_error_is_not_retried_but_quota_403_is(): void
	{
		$this->api->json(403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => "User does not have sufficient permission for site 'https://x.pl/'.", 'errors' => [['reason' => 'forbidden']]]]);

		try {
			$this->client->listSites(FakeApiRequester::connection());
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::PermissionDenied, $exception->category());
			self::assertSame('forbidden', $exception->reason());
			self::assertFalse($exception->isRetryable());
		}

		self::assertSame([], $this->sleeper->sleeps);

		$this->api
			->json(403, ['error' => ['code' => 403, 'errors' => [['reason' => 'userRateLimitExceeded']]]])
			->json(200, []);
		$this->client->listSites(FakeApiRequester::connection());
		self::assertSame([1.0], $this->sleeper->sleeps);
	}

	public function test_client_errors_are_classified(): void
	{
		$cases = [400 => ErrorCategory::BadRequest, 401 => ErrorCategory::Unauthorized, 404 => ErrorCategory::NotFound, 418 => ErrorCategory::Http];

		foreach ($cases as $status => $category) {
			$this->api->json($status, ['error' => ['code' => $status]]);

			try {
				$this->client->listSites(FakeApiRequester::connection());
				self::fail('Expected GscApiException for ' . $status);
			} catch (GscApiException $exception) {
				self::assertSame($category, $exception->category(), (string) $status);
				self::assertFalse($exception->isRetryable());
			}
		}

		self::assertCount(4, $this->api->requests, 'Bez ponowień dla błędów klienta.');
	}

	public function test_network_errors_are_retried_then_reported(): void
	{
		$this->api
			->push(new TransportException('HTTP request to www.googleapis.com failed (http_request_failed).'))
			->push(new TransportException('HTTP request to www.googleapis.com failed (http_request_failed).'))
			->push(new TransportException('HTTP request to www.googleapis.com failed (http_request_failed).'));

		try {
			$this->client->listSites(FakeApiRequester::connection());
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::Network, $exception->category());
			self::assertInstanceOf(TransportException::class, $exception->getPrevious());
		}
	}

	public function test_token_endpoint_failures_are_mapped_and_reauth_propagates(): void
	{
		$this->api->push(new OAuthEndpointError(503, 'backend_error'))->json(200, []);
		$this->client->listSites(FakeApiRequester::connection());
		self::assertSame([1.0], $this->sleeper->sleeps);

		$this->api->push(new OAuthEndpointError(400, 'invalid_client'));

		try {
			$this->client->listSites(FakeApiRequester::connection());
			self::fail('Expected GscApiException.');
		} catch (GscApiException $exception) {
			self::assertSame(ErrorCategory::Unauthorized, $exception->category());
		}

		$this->api->push(new ReauthorizationRequired(1));
		$this->expectException(ReauthorizationRequired::class);
		$this->client->listSites(FakeApiRequester::connection());
	}

	public function test_logs_contain_no_secrets_or_response_bodies(): void
	{
		$token = SecretSamples::googleAccessToken();
		$this->api->json(403, ['error' => ['code' => 403, 'message' => 'Bearer ' . $token, 'errors' => [['reason' => 'forbidden']]]]);

		try {
			$this->client->listSites(FakeApiRequester::connection());
		} catch (GscApiException $exception) {
			// Komunikat wyjątku może trafić do logu przez Logger (redakcja) — sam log nie zawiera treści odpowiedzi.
		}

		self::assertNotSame([], $this->logs);
		self::assertStringNotContainsString($token, implode("\n", $this->logs));
		self::assertStringNotContainsString('Bearer', implode("\n", $this->logs));
	}

	public function test_site_url_is_encoded_as_single_path_segment(): void
	{
		self::assertSame('https://www.googleapis.com/webmasters/v3/sites/sc-domain%3Aexample.pl', GscClient::siteUrlPath('sc-domain:example.pl'));
		self::assertSame('https://www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fwww.example.pl%2F', GscClient::siteUrlPath('https://www.example.pl/'));
	}
}
