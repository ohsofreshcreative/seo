<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Support\Base64Url;
use OsfSeo\Support\Clock;

/**
 * `state` OAuth: 32 losowe bajty, ważny TTL sekund, jednorazowy, związany z użytkownikiem WordPressa
 * i projektem. W bazie (transient) leży tylko skrót SHA-256 `state` jako klucz oraz weryfikator PKCE
 * — usuwane przy pierwszym użyciu (także nieudanym).
 */
final class OAuthStateStore
{
	public const TTL = 600;

	private const PREFIX = 'osf_seo_oauth_';

	public function __construct(private readonly Clock $clock)
	{
	}

	public function issue(int $userId, string $projectPublicId, #[\SensitiveParameter] string $codeVerifier): string
	{
		$state = Base64Url::encode(random_bytes(32));

		// Transient żyje dłużej niż TTL, żeby odróżnić „wygasł” od „nie istnieje”; o ważności decyduje expires_at.
		set_transient($this->key($state), [
			'user_id' => $userId,
			'project' => $projectPublicId,
			'verifier' => $codeVerifier,
			'expires_at' => $this->clock->now()->getTimestamp() + self::TTL,
		], self::TTL * 2);

		return $state;
	}

	/**
	 * @throws OAuthFlowException state_invalid | state_expired | state_user_mismatch
	 */
	public function consume(#[\SensitiveParameter] string $state, int $currentUserId): OAuthState
	{
		if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $state) !== 1) {
			throw new OAuthFlowException(OAuthFlowException::STATE_INVALID);
		}

		$key = $this->key($state);
		$data = get_transient($key);

		// Jednorazowość: przy równoległych callbackach wygrywa tylko żądanie, które faktycznie usunęło wpis.
		if (! is_array($data) || ! delete_transient($key)) {
			throw new OAuthFlowException(OAuthFlowException::STATE_INVALID);
		}

		if ((int) ($data['expires_at'] ?? 0) < $this->clock->now()->getTimestamp()) {
			throw new OAuthFlowException(OAuthFlowException::STATE_EXPIRED);
		}

		if ($currentUserId <= 0 || (int) ($data['user_id'] ?? 0) !== $currentUserId) {
			throw new OAuthFlowException(OAuthFlowException::STATE_USER_MISMATCH);
		}

		return new OAuthState($currentUserId, (string) ($data['project'] ?? ''), (string) ($data['verifier'] ?? ''));
	}

	private function key(string $state): string
	{
		return self::PREFIX . substr(hash('sha256', $state), 0, 40);
	}
}
