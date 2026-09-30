<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use RuntimeException;

/**
 * Błąd zwrócony przez endpoint OAuth Google (token/revoke). Przechowuje tylko status HTTP
 * i kod `error` (np. `invalid_grant`) — nigdy treść odpowiedzi ani `error_description`.
 */
final class OAuthEndpointError extends RuntimeException
{
	public function __construct(private readonly int $status, private readonly string $error)
	{
		parent::__construct(sprintf('Google OAuth endpoint returned HTTP %d (%s).', $status, $error));
	}

	/**
	 * @param array<string, mixed>|null $json
	 */
	public static function fromResponse(int $status, ?array $json): self
	{
		$error = $json['error'] ?? null;

		return new self($status, is_string($error) && preg_match('/^[a-z_]{1,64}$/', $error) === 1 ? $error : 'http_' . $status);
	}

	public function status(): int
	{
		return $this->status;
	}

	public function error(): string
	{
		return $this->error;
	}

	/** Refresh token cofnięty, wygasły (np. 7 dni w trybie Testing) albo nieprawidłowy. */
	public function isInvalidGrant(): bool
	{
		return $this->error === 'invalid_grant';
	}
}
