<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Google;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Google\ConnectionStatus;
use OsfSeo\Google\GoogleConfig;
use OsfSeo\Google\OAuthFlowException;
use OsfSeo\Google\OAuthStateStore;
use OsfSeo\Google\Pkce;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Tests\Support\GoogleFakes;
use WP_Error;

final class OAuthFlowTest extends GoogleTestCase
{
	public function test_full_flow_connects_project_with_pkce_and_encrypted_refresh_token(): void
	{
		$context = $this->adminProject();
		$url = $this->flow->start($context);
		$query = self::query($url);

		self::assertStringStartsWith(GoogleConfig::AUTHORIZATION_ENDPOINT . '?', $url);
		self::assertSame('S256', $query['code_challenge_method']);
		self::assertSame(GoogleFakes::CLIENT_ID, $query['client_id']);
		self::assertSame('https://seo.example.test/oauth/google/callback', $query['redirect_uri']);
		self::assertSame('https://www.googleapis.com/auth/webmasters.readonly openid email', $query['scope']);
		self::assertSame(['offline', 'consent'], [$query['access_type'], $query['prompt']]);
		self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['state']);
		self::assertSame([], self::databaseOccurrences($query['state']), 'W bazie jest tylko skrót state.');

		$code = '4/test-code-' . bin2hex(random_bytes(8));
		$access = GoogleFakes::accessToken();
		$refresh = GoogleFakes::refreshToken();
		$verifier = null;
		$this->google->on(self::TOKEN_URL, function (array $request) use (&$verifier, $query, $code, $access, $refresh): array {
			$verifier = $request['form']['code_verifier'];

			// Weryfikator z callbacku musi pasować do challenge wysłanego do Google (wiązanie PKCE).
			self::assertSame($query['code_challenge'], Pkce::challenge($verifier));
			self::assertSame('authorization_code', $request['form']['grant_type']);
			self::assertSame($code, $request['form']['code']);
			self::assertSame($this->clientSecret, $request['form']['client_secret']);
			self::assertSame('https://seo.example.test/oauth/google/callback', $request['form']['redirect_uri']);

			return ['status' => 200, 'json' => $this->tokenResponse($access, $refresh, GoogleFakes::idToken($this->now(), ['sub' => '1000123', 'email' => 'seo@example.test']))];
		});

		$connected = $this->flow->complete(['state' => $query['state'], 'code' => $code, 'scope' => $query['scope']], $context->userId());

		$connectionId = $connected->project()->connectionId;
		self::assertNotNull($connectionId);
		$connection = $this->connections->find($connectionId);
		self::assertSame($context->userId(), $connection->ownerUserId);
		self::assertSame('1000123', $connection->googleSub);
		self::assertSame('seo@example.test', $connection->email);
		self::assertSame(ConnectionStatus::Active, $connection->status);
		self::assertTrue(in_array(GoogleConfig::SCOPE_SEARCH_CONSOLE, $connection->scopes, true));

		$encrypted = $this->connections->encryptedRefreshToken($connectionId);
		self::assertStringStartsWith('v1.', $encrypted);
		self::assertSame($refresh, $this->vault->decrypt($encrypted, $connection->vaultContext()));

		$this->assertNotStoredOrLogged($refresh, 'Refresh token');
		$this->assertNotStoredOrLogged(substr($refresh, 3), 'Refresh token (bez prefiksu)');
		$this->assertNotStoredOrLogged($access, 'Access token');
		$this->assertNotStoredOrLogged($code, 'Kod autoryzacyjny');
		$this->assertNotStoredOrLogged($verifier, 'Weryfikator PKCE');
		$this->assertNotStoredOrLogged($query['state'], 'State');
		$this->assertNotStoredOrLogged($this->clientSecret, 'Client secret');
		self::assertCount(1, $this->google->requests);
	}

	public function test_state_cannot_be_reused(): void
	{
		$context = $this->adminProject();
		$state = self::query($this->flow->start($context))['state'];
		$this->google->json(self::TOKEN_URL, 200, $this->tokenResponse(null, GoogleFakes::refreshToken()));

		$this->flow->complete(['state' => $state, 'code' => '4/one'], $context->userId());

		$this->assertFlowFails(OAuthFlowException::STATE_INVALID, ['state' => $state, 'code' => '4/two'], $context->userId());
		self::assertCount(1, $this->google->requests, 'Drugi callback nie wymienia kodu.');
	}

	public function test_unknown_or_malformed_state_is_rejected_without_calling_google(): void
	{
		$context = $this->adminProject();
		$this->flow->start($context);

		$this->assertFlowFails(OAuthFlowException::STATE_INVALID, ['state' => str_repeat('A', 43), 'code' => '4/x'], $context->userId());
		$this->assertFlowFails(OAuthFlowException::STATE_INVALID, ['state' => 'short', 'code' => '4/x'], $context->userId());
		$this->assertFlowFails(OAuthFlowException::STATE_INVALID, ['state' => ['array'], 'code' => '4/x'], $context->userId());
		$this->assertFlowFails(OAuthFlowException::STATE_INVALID, ['code' => '4/x'], $context->userId());
		self::assertSame([], $this->google->requests);
	}

	public function test_expired_state_is_rejected_and_burned(): void
	{
		$context = $this->adminProject();
		$state = self::query($this->flow->start($context))['state'];

		$this->clock->advance(OAuthStateStore::TTL + 1);
		$this->assertFlowFails(OAuthFlowException::STATE_EXPIRED, ['state' => $state, 'code' => '4/x'], $context->userId());

		$this->clock->advance(-OAuthStateStore::TTL);
		$this->assertFlowFails(OAuthFlowException::STATE_INVALID, ['state' => $state, 'code' => '4/x'], $context->userId());
		self::assertSame([], $this->google->requests);
	}

	public function test_state_is_bound_to_the_user_who_started_the_flow(): void
	{
		$context = $this->adminProject();
		$otherAdmin = $this->createUser('administrator');
		$state = self::query($this->flow->start($context))['state'];

		$this->assertFlowFails(OAuthFlowException::STATE_USER_MISMATCH, ['state' => $state, 'code' => '4/x'], $otherAdmin);
		$this->assertFlowFails(OAuthFlowException::STATE_INVALID, ['state' => $state, 'code' => '4/x'], $context->userId());
		$this->assertFlowFails(OAuthFlowException::STATE_USER_MISMATCH, ['state' => self::query($this->flow->start($context))['state']], 0);
		self::assertSame([], $this->google->requests);
	}

	public function test_google_error_callback_does_not_exchange_code(): void
	{
		$context = $this->adminProject();
		$state = self::query($this->flow->start($context))['state'];

		$exception = $this->assertFlowFails(OAuthFlowException::ACCESS_DENIED, ['state' => $state, 'error' => 'access_denied'], $context->userId());

		self::assertSame($context->publicId(), $exception->context()?->publicId());
		self::assertSame([], $this->google->requests);
		self::assertSame(['active' => 0, 'needs_reauth' => 0, 'revoked' => 0], $this->connections->statusCounts());
	}

	public function test_token_exchange_errors_leave_no_connection(): void
	{
		$context = $this->adminProject();
		$this->google->json(self::TOKEN_URL, 400, ['error' => 'invalid_grant', 'error_description' => 'Bad Request']);
		$this->google->on(self::TOKEN_URL, new WP_Error('http_request_failed', 'cURL error 28: timeout'));
		$this->google->json(self::TOKEN_URL, 500, ['error' => 'internal_failure']);

		foreach ([1, 2, 3] as $attempt) {
			$state = self::query($this->flow->start($context))['state'];
			$this->assertFlowFails(OAuthFlowException::EXCHANGE_FAILED, ['state' => $state, 'code' => '4/x' . $attempt], $context->userId());
		}

		$this->assertFlowFails(OAuthFlowException::EXCHANGE_FAILED, ['state' => self::query($this->flow->start($context))['state']], $context->userId());
		self::assertCount(3, $this->google->requestsTo(self::TOKEN_URL));
		self::assertNull($this->projects->reload($context->project())->connectionId);
		self::assertSame(0, array_sum($this->connections->statusCounts()));
	}

	public function test_missing_search_console_scope_is_rejected_and_token_revoked(): void
	{
		$context = $this->adminProject();
		$refresh = GoogleFakes::refreshToken();
		$state = self::query($this->flow->start($context))['state'];
		$this->google->json(self::TOKEN_URL, 200, $this->tokenResponse(null, $refresh, null, 'openid https://www.googleapis.com/auth/userinfo.email'));
		$this->google->json(self::REVOKE_URL, 200, []);

		$this->assertFlowFails(OAuthFlowException::SCOPE_MISSING, ['state' => $state, 'code' => '4/x'], $context->userId());

		self::assertSame($refresh, $this->google->requestsTo(self::REVOKE_URL)[0]['form']['token']);
		self::assertSame(0, array_sum($this->connections->statusCounts()));
		$this->assertNotStoredOrLogged($refresh, 'Refresh token');
	}

	public function test_invalid_id_token_is_rejected(): void
	{
		$context = $this->adminProject();
		$state = self::query($this->flow->start($context))['state'];
		$this->google->json(self::TOKEN_URL, 200, $this->tokenResponse(null, GoogleFakes::refreshToken(), GoogleFakes::idToken($this->now(), ['aud' => 'someone-else'])));
		$this->google->json(self::REVOKE_URL, 200, []);

		$this->assertFlowFails(OAuthFlowException::ID_TOKEN_INVALID, ['state' => $state, 'code' => '4/x'], $context->userId());
		self::assertSame(0, array_sum($this->connections->statusCounts()));
	}

	public function test_missing_refresh_token_reuses_only_an_existing_active_connection(): void
	{
		$context = $this->adminProject();
		$idToken = GoogleFakes::idToken($this->now(), ['sub' => '1000999']);

		$state = self::query($this->flow->start($context))['state'];
		$this->google->json(self::TOKEN_URL, 200, $this->tokenResponse(null, null, $idToken));
		$this->assertFlowFails(OAuthFlowException::NO_REFRESH_TOKEN, ['state' => $state, 'code' => '4/x'], $context->userId());

		$existing = $this->storeConnection($context->userId(), GoogleFakes::refreshToken(), '1000999');
		$state = self::query($this->flow->start($context))['state'];
		$this->google->json(self::TOKEN_URL, 200, $this->tokenResponse(null, null, $idToken));

		self::assertSame($existing->id, $this->flow->complete(['state' => $state, 'code' => '4/y'], $context->userId())->project()->connectionId);
	}

	public function test_reconnecting_the_same_account_updates_the_existing_connection(): void
	{
		$context = $this->adminProject();
		$idToken = GoogleFakes::idToken($this->now(), ['sub' => '1000888']);
		$ids = [];
		$refresh = '';

		foreach ([1, 2] as $round) {
			$refresh = GoogleFakes::refreshToken();
			$state = self::query($this->flow->start($context))['state'];
			$this->google->json(self::TOKEN_URL, 200, $this->tokenResponse(null, $refresh, $idToken));
			$ids[] = $this->flow->complete(['state' => $state, 'code' => '4/r' . $round], $context->userId())->project()->connectionId;
		}

		self::assertSame($ids[0], $ids[1]);
		$connection = $this->connections->find($ids[1]);
		self::assertSame($refresh, $this->vault->decrypt($this->connections->encryptedRefreshToken($ids[1]), $connection->vaultContext()));
	}

	public function test_start_requires_manage_connections_and_configuration(): void
	{
		$context = $this->adminProject();
		$client = $this->createUser('osf_seo_client');
		$this->service->assignUser($context, $client, ProjectRole::Manager);

		try {
			$this->flow->start($this->guard->authorize($context->publicId(), $client));
			self::fail('Klient nie może łączyć projektu z Google.');
		} catch (AccessDenied) {
			$this->addToAssertionCount(1);
		}

		$this->clearGoogleEnv();
		$exception = $this->assertThrowsFlow(OAuthFlowException::NOT_CONFIGURED, fn () => $this->flow->start($context));
		self::assertStringContainsString('wp-config.php', $exception->userMessage());
	}

	public function test_callback_rechecks_project_access(): void
	{
		$context = $this->adminProject();
		$staff = $this->createUser('osf_seo_admin');
		$staffContext = $this->guard->authorize($context->publicId(), $staff);
		$state = self::query($this->flow->start($staffContext))['state'];

		// Między startem a callbackiem użytkownik stracił uprawnienia (rola klienta, brak przypisania).
		(new \WP_User($staff))->set_role('osf_seo_client');

		$this->expectException(ProjectNotFound::class);

		try {
			$this->flow->complete(['state' => $state, 'code' => '4/x'], $staff);
		} finally {
			self::assertSame([], $this->google->requests);
		}
	}

	public function test_callback_requires_manage_connections_on_visible_project(): void
	{
		$context = $this->adminProject();
		$staff = $this->createUser('osf_seo_admin');
		$state = self::query($this->flow->start($this->guard->authorize($context->publicId(), $staff)))['state'];

		(new \WP_User($staff))->set_role('osf_seo_client');
		$this->service->assignUser($context, $staff);

		$this->expectException(AccessDenied::class);
		$this->flow->complete(['state' => $state, 'code' => '4/x'], $staff);
	}

	/**
	 * @param array<string, mixed> $query
	 */
	private function assertFlowFails(string $reason, array $query, int $userId): OAuthFlowException
	{
		return $this->assertThrowsFlow($reason, fn () => $this->flow->complete($query, $userId));
	}

	private function assertThrowsFlow(string $reason, callable $callback): OAuthFlowException
	{
		try {
			$callback();
		} catch (OAuthFlowException $exception) {
			self::assertSame($reason, $exception->reason());
			self::assertNotSame('', $exception->userMessage());

			return $exception;
		}

		self::fail('Expected OAuthFlowException: ' . $reason);
	}
}
