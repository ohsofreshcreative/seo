<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Http\TransportException;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Łączenie projektu z kontem Google (OAuth 2.0 Authorization Code + PKCE S256).
 *
 * start()      → `state` (jednorazowy, TTL, użytkownik + projekt) i adres ekranu zgody Google,
 * complete()   → weryfikacja `state`, ponowna autoryzacja projektu, wymiana kodu, kontrola scope,
 *                tożsamość z id_token, zaszyfrowany refresh token, podpięcie połączenia do projektu,
 * disconnect() → odpięcie; nieużywane połączenie jest unieważniane w Google i usuwane.
 *
 * Kody autoryzacyjne, weryfikatory PKCE i tokeny nie trafiają do logów.
 */
final class OAuthFlow
{
	public function __construct(
		private readonly GoogleConfig $config,
		private readonly OAuthClient $oauth,
		private readonly OAuthStateStore $states,
		private readonly TokenVault $vault,
		private readonly ConnectionRepository $connections,
		private readonly ProjectRepository $projects,
		private readonly ProjectGuard $guard,
		private readonly AccessTokenProvider $tokens,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @return string adres ekranu zgody Google (przekierowanie przeglądarki)
	 *
	 * @throws \OsfSeo\Auth\AccessDenied brak osf_seo_manage_connections
	 * @throws OAuthFlowException not_configured
	 */
	public function start(ProjectContext $context): string
	{
		$context->assertCan(Capabilities::MANAGE_CONNECTIONS);

		if (! $this->config->isConfigured()) {
			throw new OAuthFlowException(OAuthFlowException::NOT_CONFIGURED, $context);
		}

		$verifier = Pkce::verifier();
		$state = $this->states->issue($context->userId(), $context->publicId(), $verifier);

		$this->logger->info('Google OAuth started for project {project} by user {user}.', [
			'project' => $context->publicId(),
			'user' => $context->userId(),
		]);

		return $this->oauth->authorizationUrl($state, Pkce::challenge($verifier));
	}

	/**
	 * Obsługa callbacku Google.
	 *
	 * @param array<string, mixed> $query parametry zapytania callbacku (`state`, `code`, `error`)
	 *
	 * @throws OAuthFlowException
	 * @throws \OsfSeo\Auth\ProjectNotFound projekt ze `state` nie jest już widoczny dla użytkownika
	 * @throws \OsfSeo\Auth\AccessDenied użytkownik utracił osf_seo_manage_connections
	 */
	public function complete(array $query, int $currentUserId): ProjectContext
	{
		$state = $this->states->consume(self::param($query, 'state'), $currentUserId);
		$context = $this->guard->authorize($state->projectPublicId, $currentUserId, Capabilities::MANAGE_CONNECTIONS);

		$error = self::param($query, 'error');

		if ($error !== '') {
			$this->logger->info('Google OAuth for project {project} was not granted ({error_code}).', [
				'project' => $context->publicId(),
				'error_code' => preg_match('/^[a-z_]{1,64}$/', $error) === 1 ? $error : 'other',
			]);

			throw new OAuthFlowException(OAuthFlowException::ACCESS_DENIED, $context);
		}

		$code = self::param($query, 'code');

		if ($code === '') {
			throw new OAuthFlowException(OAuthFlowException::EXCHANGE_FAILED, $context);
		}

		try {
			$tokens = $this->oauth->exchangeCode($code, $state->codeVerifier);
		} catch (OAuthEndpointError | TransportException | GoogleNotConfigured $exception) {
			$this->logger->warning('Google OAuth code exchange failed for project {project}: {error}', [
				'project' => $context->publicId(),
				'error' => $exception->getMessage(),
			]);

			throw new OAuthFlowException(OAuthFlowException::EXCHANGE_FAILED, $context, $exception);
		}

		// Ekran zgody Google pozwala odznaczyć pojedyncze uprawnienia — bez Search Console połączenie jest bezużyteczne.
		if (! $tokens->hasScope(GoogleConfig::SCOPE_SEARCH_CONSOLE)) {
			$this->revokeQuietly($tokens->refreshToken ?? $tokens->accessToken);

			throw new OAuthFlowException(OAuthFlowException::SCOPE_MISSING, $context);
		}

		try {
			$identity = IdToken::identity($tokens->idToken ?? '', $this->config->clientId(), $this->clock->now()->getTimestamp());
		} catch (OAuthFlowException $exception) {
			$this->revokeQuietly($tokens->refreshToken ?? $tokens->accessToken);

			throw new OAuthFlowException($exception->reason(), $context, $exception);
		}

		$connectionId = $this->storeConnection($context, $currentUserId, $identity, $tokens);

		$this->projects->setConnection($context->projectId(), $connectionId);
		$this->tokens->forget($connectionId);

		$this->logger->info('Google connection {connection} linked to project {project} by user {user}.', [
			'connection' => $connectionId,
			'project' => $context->publicId(),
			'user' => $currentUserId,
		]);

		return $context->withProject($this->projects->reload($context->project()));
	}

	/**
	 * @throws \OsfSeo\Auth\AccessDenied brak osf_seo_manage_connections
	 */
	public function disconnect(ProjectContext $context): DisconnectResult
	{
		$context->assertCan(Capabilities::MANAGE_CONNECTIONS);

		$connectionId = $context->project()->connectionId;

		if ($connectionId === null) {
			return new DisconnectResult(false, false, false);
		}

		$this->projects->setConnection($context->projectId(), null);

		if ($this->projects->countByConnection($connectionId) > 0) {
			$this->logger->info('Project {project} detached from Google connection {connection} (still used by other projects).', [
				'project' => $context->publicId(),
				'connection' => $connectionId,
			]);

			return new DisconnectResult(true, false, false);
		}

		$revoked = false;
		$connection = $this->connections->find($connectionId);
		$encrypted = $this->connections->encryptedRefreshToken($connectionId);

		if ($connection !== null && $encrypted !== null) {
			try {
				$this->oauth->revoke($this->vault->decrypt($encrypted, $connection->vaultContext()));
				$revoked = true;
			} catch (Throwable $exception) {
				$this->logger->warning('Could not revoke Google connection {connection} ({exception}); removing it locally anyway.', [
					'connection' => $connectionId,
					'exception' => $exception::class,
				]);
			}
		}

		// Lokalnie usuwamy zawsze — przechowywanie tokenu, którego nie chcemy używać, to tylko ryzyko.
		$this->connections->delete($connectionId);
		$this->tokens->forget($connectionId);

		$this->logger->info('Google connection {connection} removed by user {user} (revoked at Google: {revoked}).', [
			'connection' => $connectionId,
			'user' => $context->userId(),
			'revoked' => $revoked ? 'yes' : 'no',
		]);

		return new DisconnectResult(true, true, $revoked);
	}

	/**
	 * @param array{sub: string, email: string} $identity
	 */
	private function storeConnection(ProjectContext $context, int $ownerUserId, array $identity, TokenResponse $tokens): int
	{
		if ($tokens->refreshToken === null) {
			// Bez nowego refresh tokenu da się użyć tylko istniejącego, aktywnego połączenia tego samego konta.
			$existing = $this->connections->findByAccount($ownerUserId, $identity['sub']);

			if ($existing === null || ! $existing->isActive()) {
				throw new OAuthFlowException(OAuthFlowException::NO_REFRESH_TOKEN, $context);
			}

			return $existing->id;
		}

		return $this->connections->save(
			$ownerUserId,
			$identity['sub'],
			$identity['email'],
			$this->vault->encrypt($tokens->refreshToken, GoogleConnection::vaultContextFor($ownerUserId, $identity['sub'])),
			$tokens->scopes,
		);
	}

	private function revokeQuietly(#[\SensitiveParameter] string $token): void
	{
		try {
			$this->oauth->revoke($token);
		} catch (Throwable) {
			// Nieudane unieważnienie niepotrzebnego tokenu nie zmienia wyniku — token nie jest nigdzie zapisany.
		}
	}

	/**
	 * @param array<string, mixed> $query
	 */
	private static function param(array $query, string $name): string
	{
		$value = $query[$name] ?? '';

		return is_string($value) ? trim($value) : '';
	}
}
