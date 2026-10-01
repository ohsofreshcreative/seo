<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Połączenie z kontem Google (bez tokenów — zaszyfrowany refresh token czyta wyłącznie
 * AccessTokenProvider przez ConnectionRepository::encryptedRefreshToken()).
 */
final class GoogleConnection
{
	/**
	 * @param list<string> $scopes
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $ownerUserId,
		public readonly string $googleSub,
		public readonly string $email,
		public readonly ConnectionStatus $status,
		public readonly array $scopes,
		public readonly ?string $lastError,
		public readonly ?DateTimeImmutable $lastRefreshedAt,
		public readonly DateTimeImmutable $createdAt,
		public readonly DateTimeImmutable $updatedAt,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self
	{
		$utc = new DateTimeZone('UTC');
		$date = static fn (?string $value): ?DateTimeImmutable => $value === null ? null : new DateTimeImmutable($value, $utc);

		return new self(
			(int) $row['id'],
			(int) $row['owner_user_id'],
			(string) $row['google_sub'],
			(string) $row['email'],
			ConnectionStatus::from((string) $row['status']),
			array_values(array_filter(explode(' ', (string) ($row['scopes'] ?? '')))),
			$row['last_error'] === null ? null : (string) $row['last_error'],
			$date($row['last_refreshed_at'] ?? null),
			$date((string) $row['created_at']),
			$date((string) $row['updated_at']),
		);
	}

	public function isActive(): bool
	{
		return $this->status === ConnectionStatus::Active;
	}

	/** Kontekst AAD szyfrogramu refresh tokenu — wiąże go z właścicielem i kontem Google. */
	public function vaultContext(): string
	{
		return self::vaultContextFor($this->ownerUserId, $this->googleSub);
	}

	public static function vaultContextFor(int $ownerUserId, string $googleSub): string
	{
		return 'google_refresh_token|' . $ownerUserId . '|' . $googleSub;
	}
}
