<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Google;

use OsfSeo\Google\GoogleConfig;
use OsfSeo\Google\GoogleNotConfigured;
use OsfSeo\Google\OAuthClient;
use OsfSeo\Google\OAuthEndpointError;
use OsfSeo\Google\TokenResponse;
use OsfSeo\Http\HttpResponse;
use OsfSeo\Support\Config;
use OsfSeo\Tests\Support\FakeHttpTransport;
use OsfSeo\Tests\Support\GoogleFakes;
use OsfSeo\Tests\Support\GoogleEnv;
use PHPUnit\Framework\TestCase;

final class OAuthClientTest extends TestCase
{
	use GoogleEnv;

	private FakeHttpTransport $http;

	private OAuthClient $client;

	protected function setUp(): void
	{
		$this->http = new FakeHttpTransport();
		$this->client = new OAuthClient($this->configureGoogle(), $this->http);
	}

	protected function tearDown(): void
	{
		$this->clearGoogleEnv();
	}

	public function test_authorization_url_uses_pkce_offline_access_and_minimal_scopes(): void
	{
		$url = $this->client->authorizationUrl('state-value_123', 'challenge-value');
		$parts = parse_url($url);
		parse_str($parts['query'], $query);

		self::assertSame('https://accounts.google.com/o/oauth2/v2/auth', $parts['scheme'] . '://' . $parts['host'] . $parts['path']);
		self::assertSame([
			'client_id' => GoogleFakes::CLIENT_ID,
			'redirect_uri' => 'https://seo.example.test/oauth/google/callback',
			'response_type' => 'code',
			'scope' => 'https://www.googleapis.com/auth/webmasters.readonly openid email',
			'access_type' => 'offline',
			'prompt' => 'consent',
			'state' => 'state-value_123',
			'code_challenge' => 'challenge-value',
			'code_challenge_method' => 'S256',
		], $query);
		self::assertStringNotContainsString($this->clientSecret, $url);
		self::assertSame([], $this->http->requests, 'Budowanie adresu nie wysyła żądań.');
	}

	public function test_code_exchange_posts_code_verifier_and_client_credentials(): void
	{
		$access = GoogleFakes::accessToken();
		$refresh = GoogleFakes::refreshToken();
		$this->http->pushJson(200, GoogleFakes::tokenResponse(time(), $access, $refresh));

		$tokens = $this->client->exchangeCode('4/test-code', 'verifier-123');

		self::assertSame('POST', $this->http->requests[0]['method']);
		self::assertSame('https://oauth2.googleapis.com/token', $this->http->requests[0]['url']);
		self::assertSame('application/x-www-form-urlencoded', $this->http->requests[0]['headers']['Content-Type']);
		self::assertSame([
			'grant_type' => 'authorization_code',
			'code' => '4/test-code',
			'code_verifier' => 'verifier-123',
			'redirect_uri' => 'https://seo.example.test/oauth/google/callback',
			'client_id' => GoogleFakes::CLIENT_ID,
			'client_secret' => $this->clientSecret,
		], $this->http->formBody(0));
		self::assertSame($access, $tokens->accessToken);
		self::assertSame($refresh, $tokens->refreshToken);
		self::assertSame(3599, $tokens->expiresIn);
		self::assertTrue($tokens->hasScope(GoogleConfig::SCOPE_SEARCH_CONSOLE));
		self::assertNotNull($tokens->idToken);
	}

	public function test_refresh_uses_refresh_token_grant(): void
	{
		$refresh = GoogleFakes::refreshToken();
		$this->http->pushJson(200, ['access_token' => GoogleFakes::accessToken(), 'expires_in' => 3599, 'scope' => GoogleConfig::SCOPE_SEARCH_CONSOLE]);

		$tokens = $this->client->refreshAccessToken($refresh);

		self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => GoogleFakes::CLIENT_ID, 'client_secret' => $this->clientSecret], $this->http->formBody(0));
		self::assertNull($tokens->refreshToken);
		self::assertNull($tokens->idToken);
	}

	public function test_invalid_grant_is_reported_without_response_details(): void
	{
		$this->http->pushJson(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']);

		try {
			$this->client->refreshAccessToken(GoogleFakes::refreshToken());
			self::fail('Expected OAuthEndpointError.');
		} catch (OAuthEndpointError $error) {
			self::assertTrue($error->isInvalidGrant());
			self::assertSame(400, $error->status());
			self::assertStringNotContainsString('expired or revoked', $error->getMessage());
		}
	}

	public function test_unexpected_error_payloads_are_normalized(): void
	{
		$this->http->push(new HttpResponse(502, '<html>Bad gateway</html>'));
		$this->http->pushJson(400, ['error' => 'Weird Error With <html>']);
		$this->http->pushJson(200, ['token_type' => 'Bearer']);

		foreach (['http_502', 'http_400', 'invalid_response'] as $expected) {
			try {
				$this->client->exchangeCode('4/test-code', 'verifier');
				self::fail('Expected OAuthEndpointError.');
			} catch (OAuthEndpointError $error) {
				self::assertSame($expected, $error->error());
				self::assertFalse($error->isInvalidGrant());
			}
		}
	}

	public function test_revoke_treats_already_invalid_token_as_success(): void
	{
		$token = GoogleFakes::refreshToken();
		$this->http->push(new HttpResponse(200, ''));
		$this->http->pushJson(400, ['error' => 'invalid_token']);
		$this->http->pushJson(503, ['error' => 'backend_error']);

		$this->client->revoke($token);
		$this->client->revoke($token);

		self::assertSame('https://oauth2.googleapis.com/revoke', $this->http->requests[0]['url']);
		self::assertSame(['token' => $token], $this->http->formBody(0));

		$this->expectException(OAuthEndpointError::class);
		$this->client->revoke($token);
	}

	public function test_missing_client_secret_blocks_token_requests(): void
	{
		$this->clearGoogleEnv();
		$client = new OAuthClient($this->configureGoogle(false), $this->http);

		$this->expectException(GoogleNotConfigured::class);
		$client->exchangeCode('4/test-code', 'verifier');
	}

	public function test_token_response_debug_output_hides_tokens(): void
	{
		$access = GoogleFakes::accessToken();
		$refresh = GoogleFakes::refreshToken();
		$tokens = TokenResponse::fromArray(['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => '120']);
		$dump = print_r($tokens, true);

		self::assertSame(120, $tokens->expiresIn);
		self::assertStringNotContainsString($access, $dump);
		self::assertStringNotContainsString($refresh, $dump);
		self::assertSame(3600, TokenResponse::fromArray(['access_token' => $access])->expiresIn);
	}
}
