<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;

/**
 * Access tokeny Google dla połączeń. Token żyje wyłącznie w pamięci procesu (nie w bazie ani
 * w transientach) do `expires_in − 60 s`; nowy proces odświeża go z zaszyfrowanego refresh tokenu.
 *
 * `invalid_grant` → połączenie `needs_reauth` i ReauthorizationRequired (bez ponowień).
 * Błąd klucza szyfrowania (VaultException) nie zmienia statusu połączenia — to problem konfiguracji,
 * po jego naprawie połączenie działa dalej.
 */
final class AccessTokenProvider
{
	private const EXPIRY_MARGIN = 60;

	/** @var array<int, array{token: string, expires_at: int}> */
	private array $cache = [];

	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly TokenVault $vault,
		private readonly OAuthClient $oauth,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @throws ReauthorizationRequired
	 * @throws VaultException
	 * @throws OAuthEndpointError
	 * @throws \OsfSeo\Http\TransportException
	 */
	public function token(GoogleConnection $connection): string
	{
		$cached = $this->cache[$connection->id] ?? null;

		if ($cached !== null && $cached['expires_at'] > $this->clock->now()->getTimestamp()) {
			return $cached['token'];
		}

		return $this->refresh($connection);
	}

	/** Wymusza nowy access token (np. po odpowiedzi 401). */
	public function refresh(GoogleConnection $connection): string
	{
		$this->forget($connection->id);

		$current = $this->connections->find($connection->id);
		$encrypted = $this->connections->encryptedRefreshToken($connection->id);

		if ($current === null || ! $current->isActive() || $encrypted === null) {
			throw new ReauthorizationRequired($connection->id);
		}

		try {
			$refreshToken = $this->vault->decrypt($encrypted, $current->vaultContext());
		} catch (VaultException $exception) {
			$this->logger->error('Cannot decrypt refresh token of Google connection {connection} ({reason}).', [
				'connection' => $current->id,
				'reason' => $exception->reason(),
			]);

			throw $exception;
		}

		try {
			$response = $this->oauth->refreshAccessToken($refreshToken);
		} catch (OAuthEndpointError $exception) {
			if (! $exception->isInvalidGrant()) {
				throw $exception;
			}

			$this->connections->markNeedsReauth($current->id, 'invalid_grant');
			$this->logger->warning('Google connection {connection} needs re-authorization (invalid_grant).', ['connection' => $current->id]);

			throw new ReauthorizationRequired($current->id, $exception);
		}

		// Google może zwrócić nowy refresh token (rotacja) — zapisujemy go zaszyfrowanego.
		if ($response->refreshToken !== null && ! hash_equals($refreshToken, $response->refreshToken)) {
			$this->connections->updateRefreshToken($current->id, $this->vault->encrypt($response->refreshToken, $current->vaultContext()));
		}

		$this->connections->markRefreshed($current->id);
		$this->cache[$current->id] = [
			'token' => $response->accessToken,
			'expires_at' => $this->clock->now()->getTimestamp() + max(0, $response->expiresIn - self::EXPIRY_MARGIN),
		];

		return $response->accessToken;
	}

	public function forget(int $connectionId): void
	{
		unset($this->cache[$connectionId]);
	}

	public function __debugInfo(): array
	{
		return ['cached_connections' => array_keys($this->cache)];
	}
}
