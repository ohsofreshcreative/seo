<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Stan Strategii projektu (`strategy_settings`): rewizja wpisów ręcznych, klucz danych i wynik ostatniego przeliczenia.
 */
final class StrategySettings
{
	/**
	 * @param array<string, mixed> $stats
	 */
	public function __construct(
		public readonly int $projectId,
		public readonly int $revision = 0,
		public readonly ?string $dataKey = null,
		public readonly ?string $refreshedAt = null,
		public readonly ?int $refreshMs = null,
		public readonly array $stats = [],
		public readonly ?int $locationCode = null,
		public readonly ?string $languageCode = null,
		public readonly ?string $updatedAt = null,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$stats = json_decode((string) ($row['stats'] ?? ''), true);

		return new self(
			(int) $row['project_id'],
			(int) $row['revision'],
			$row['data_key_hex'] ?? null,
			$row['refreshed_at'],
			$row['refresh_ms'] === null ? null : (int) $row['refresh_ms'],
			is_array($stats) ? $stats : [],
			$row['location_code'] === null ? null : (int) $row['location_code'],
			$row['language_code'],
			$row['updated_at'],
		);
	}
}
