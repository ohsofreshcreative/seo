<?php

declare(strict_types=1);

namespace OsfSeo\Google;

/** Odpowiedź endpointu tokenów Google. Tokeny tylko w pamięci — `__debugInfo` je maskuje. */
final class TokenResponse
{
	/**
	 * @param list<string> $scopes
	 */
	public function __construct(
		#[\SensitiveParameter] public readonly string $accessToken,
		public readonly int $expiresIn,
		#[\SensitiveParameter] public readonly ?string $refreshToken,
		public readonly array $scopes,
		#[\SensitiveParameter] public readonly ?string $idToken,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(#[\SensitiveParameter] array $data): self
	{
		$optional = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

		return new self(
			(string) $data['access_token'],
			isset($data['expires_in']) && is_numeric($data['expires_in']) ? max(0, (int) $data['expires_in']) : 3600,
			$optional('refresh_token'),
			array_values(array_filter(explode(' ', (string) ($optional('scope') ?? '')))),
			$optional('id_token'),
		);
	}

	public function hasScope(string $scope): bool
	{
		return in_array($scope, $this->scopes, true);
	}

	public function __debugInfo(): array
	{
		return [
			'accessToken' => '[REDACTED]',
			'expiresIn' => $this->expiresIn,
			'refreshToken' => $this->refreshToken === null ? null : '[REDACTED]',
			'scopes' => $this->scopes,
			'idToken' => $this->idToken === null ? null : '[REDACTED]',
		];
	}
}
