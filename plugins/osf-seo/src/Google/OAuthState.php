<?php

declare(strict_types=1);

namespace OsfSeo\Google;

/** Zweryfikowany i zużyty `state` OAuth. */
final class OAuthState
{
	public function __construct(
		public readonly int $userId,
		public readonly string $projectPublicId,
		public readonly string $codeVerifier,
	) {
	}

	public function __debugInfo(): array
	{
		return ['userId' => $this->userId, 'projectPublicId' => $this->projectPublicId, 'codeVerifier' => '[REDACTED]'];
	}
}
