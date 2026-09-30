<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Google;

use OsfSeo\Google\ConnectionStatus;
use OsfSeo\Google\OAuthEndpointError;
use OsfSeo\Google\ReauthorizationRequired;
use OsfSeo\Google\VaultException;
use OsfSeo\Http\TransportException;
use OsfSeo\Tests\Support\GoogleFakes;
use WP_Error;

final class AccessTokenTest extends GoogleTestCase
{
	private const SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';

	public function test_access_token_is_refreshed_cached_in_memory_and_never_persisted(): void
	{
		$refresh = GoogleFakes::refreshToken();
		$connection = $this->storeConnection($this->createUser('administrator'), $refresh);
		$first = GoogleFakes::accessToken();
		$second = GoogleFakes::accessToken();
		$this->google->json(self::TOKEN_URL, 200, ['access_token' => $first, 'expires_in' => 3599, 'scope' => 'x']);
		$this->google->json(self::TOKEN_URL, 200, ['access_token' => $second, 'expires_in' => 3599, 'scope' => 'x']);

		self::assertSame($first, $this->tokens->token($connection));
		self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => GoogleFakes::CLIENT_ID, 'client_secret' => $this->clientSecret], $this->google->requests[0]['form']);

		$this->clock->advance(3000);
		self::assertSame($first, $this->tokens->token($connection), 'Token z pamięci do expires_in − 60 s.');
		self::assertCount(1, $this->google->requests);

		$this->clock->advance(600);
		self::assertSame($second, $this->tokens->token($connection));
		self::assertCount(2, $this->google->requests);

		self::assertNotNull($this->connections->find($connection->id)->lastRefreshedAt);
		$this->assertNotStoredOrLogged($first, 'Access token');
		$this->assertNotStoredOrLogged($second, 'Access token');
		$this->assertNotStoredOrLogged($refresh, 'Refresh token');
	}

	public function test_rotated_refresh_token_is_stored_encrypted(): void
	{
		$connection = $this->storeConnection($this->createUser('administrator'), GoogleFakes::refreshToken());
		$rotated = GoogleFakes::refreshToken();
		$this->google->json(self::TOKEN_URL, 200, ['access_token' => GoogleFakes::accessToken(), 'expires_in' => 3599, 'refresh_token' => $rotated]);

		$this->tokens->token($connection);

		self::assertSame($rotated, $this->vault->decrypt($this->connections->encryptedRefreshToken($connection->id), $connection->vaultContext()));
		$this->assertNotStoredOrLogged($rotated, 'Nowy refresh token');
	}

	public function test_invalid_grant_marks_connection_for_reauthorization_without_retries(): void
	{
		$connection = $this->storeConnection($this->createUser('administrator'), GoogleFakes::refreshToken());
		$this->google->json(self::TOKEN_URL, 400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']);

		try {
			$this->tokens->token($connection);
			self::fail('Expected ReauthorizationRequired.');
		} catch (ReauthorizationRequired $exception) {
			self::assertSame($connection->id, $exception->connectionId());
		}

		$stored = $this->connections->find($connection->id);
		self::assertSame(ConnectionStatus::NeedsReauth, $stored->status);
		self::assertSame('invalid_grant', $stored->lastError);

		$this->expectException(ReauthorizationRequired::class);

		try {
			$this->tokens->token($stored);
		} finally {
			self::assertCount(1, $this->google->requests, 'Po invalid_grant nie ma kolejnych prób.');
		}
	}

	public function test_temporary_google_errors_do_not_change_connection_status(): void
	{
		$connection = $this->storeConnection($this->createUser('administrator'), GoogleFakes::refreshToken());
		$this->google->json(self::TOKEN_URL, 503, ['error' => 'backend_error']);
		$this->google->on(self::TOKEN_URL, new WP_Error('http_request_failed', 'timeout'));

		foreach ([OAuthEndpointError::class, TransportException::class] as $expected) {
			try {
				$this->tokens->token($connection);
				self::fail('Expected ' . $expected);
			} catch (OAuthEndpointError | TransportException $exception) {
				self::assertInstanceOf($expected, $exception);
			}
		}

		self::assertSame(ConnectionStatus::Active, $this->connections->find($connection->id)->status);
	}

	public function test_changed_encryption_key_is_a_configuration_error_not_a_revocation(): void
	{
		$refresh = GoogleFakes::refreshToken();
		$connection = $this->storeConnection($this->createUser('administrator'), $refresh);
		putenv('OSF_SEO_ENCRYPTION_KEY=' . GoogleFakes::encryptionKey());

		try {
			$this->tokens->token($connection);
			self::fail('Expected VaultException.');
		} catch (VaultException $exception) {
			self::assertSame(VaultException::KEY_MISMATCH, $exception->reason());
		}

		self::assertSame([], $this->google->requests);
		self::assertSame(ConnectionStatus::Active, $this->connections->find($connection->id)->status);
		self::assertStringContainsString('key_mismatch', implode("\n", $this->logLines));
		$this->assertNotStoredOrLogged($refresh, 'Refresh token');
	}

	public function test_api_401_refreshes_the_token_and_retries_once(): void
	{
		$connection = $this->storeConnection($this->createUser('administrator'), GoogleFakes::refreshToken());
		$stale = GoogleFakes::accessToken();
		$fresh = GoogleFakes::accessToken();
		$this->google->json(self::TOKEN_URL, 200, ['access_token' => $stale, 'expires_in' => 3599]);
		$this->google->json(self::TOKEN_URL, 200, ['access_token' => $fresh, 'expires_in' => 3599]);
		$this->google->json(self::SITES_URL, 401, ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']]);
		$this->google->json(self::SITES_URL, 200, ['siteEntry' => []]);

		$response = $this->api->request($connection, 'GET', self::SITES_URL);

		self::assertSame(200, $response->status);
		$calls = $this->google->requestsTo(self::SITES_URL);
		self::assertCount(2, $calls);
		self::assertSame('Bearer ' . $stale, $calls[0]['headers']['Authorization']);
		self::assertSame('Bearer ' . $fresh, $calls[1]['headers']['Authorization']);
		self::assertCount(2, $this->google->requestsTo(self::TOKEN_URL));

		// Po odświeżeniu kolejne żądanie używa nowego tokenu z pamięci (bez kolejnego odświeżenia).
		$this->google->json(self::SITES_URL, 200, ['siteEntry' => []]);
		$this->api->request($connection, 'GET', self::SITES_URL);
		self::assertSame('Bearer ' . $fresh, $this->google->requestsTo(self::SITES_URL)[2]['headers']['Authorization']);
		self::assertCount(2, $this->google->requestsTo(self::TOKEN_URL));
	}

	public function test_api_second_401_is_returned_without_looping(): void
	{
		$connection = $this->storeConnection($this->createUser('administrator'), GoogleFakes::refreshToken());
		$this->google->json(self::TOKEN_URL, 200, ['access_token' => GoogleFakes::accessToken(), 'expires_in' => 3599]);
		$this->google->json(self::TOKEN_URL, 200, ['access_token' => GoogleFakes::accessToken(), 'expires_in' => 3599]);
		$this->google->json(self::SITES_URL, 401, ['error' => ['code' => 401]]);
		$this->google->json(self::SITES_URL, 401, ['error' => ['code' => 401]]);

		self::assertSame(401, $this->api->request($connection, 'GET', self::SITES_URL)->status);
		self::assertCount(4, $this->google->requests);
	}

	public function test_api_401_with_revoked_grant_requires_reauthorization(): void
	{
		$connection = $this->storeConnection($this->createUser('administrator'), GoogleFakes::refreshToken());
		$this->google->json(self::TOKEN_URL, 200, ['access_token' => GoogleFakes::accessToken(), 'expires_in' => 3599]);
		$this->google->json(self::SITES_URL, 401, ['error' => ['code' => 401]]);
		$this->google->json(self::TOKEN_URL, 400, ['error' => 'invalid_grant']);

		$this->expectException(ReauthorizationRequired::class);

		try {
			$this->api->request($connection, 'GET', self::SITES_URL);
		} finally {
			self::assertSame(ConnectionStatus::NeedsReauth, $this->connections->find($connection->id)->status);
		}
	}
}
