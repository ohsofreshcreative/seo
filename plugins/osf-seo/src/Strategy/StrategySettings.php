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
		/** Ręcznie zlecone przeliczenie (panel) — wykona je krok w tle albo CLI; czyszczone po przeliczeniu, które zaczęło się później. */
		public readonly ?string $refreshRequestedAt = null,
		public readonly ?int $refreshRequestedBy = null,
		/** Stan zadania przeliczenia w tle (faza E, `StrategyRefreshQueue`): idle, queued, running, failed. */
		public readonly string $refreshStatus = StrategyRefreshQueue::STATUS_IDLE,
		public readonly ?string $refreshSource = null,
		public readonly ?string $refreshDueAt = null,
		public readonly ?string $refreshStartedAt = null,
		public readonly ?string $refreshLeaseUntil = null,
		public readonly ?string $refreshFinishedAt = null,
		public readonly int $refreshAttempts = 0,
		/** Kod ostatniego błędu (bez treści wyjątku — szczegóły tylko w logu) i jego chwila; zostają po udanym przeliczeniu (diagnostyka). */
		public readonly ?string $refreshError = null,
		public readonly ?string $refreshErrorAt = null,
		/** Klucz danych, na którym wyczerpały się próby — automatyczne zlecenie dopiero po zmianie danych. */
		public readonly ?string $refreshFailedKey = null,
		/** Wykrywanie zmian (debounce): ostatnio widziany nowy klucz danych, od kiedy jest stabilny i od kiedy dane są nieaktualne. */
		public readonly ?string $refreshSeenKey = null,
		public readonly ?string $refreshSeenAt = null,
		public readonly ?string $refreshDirtySince = null,
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
			$row['refresh_requested_at'] ?? null,
			isset($row['refresh_requested_by']) ? (int) $row['refresh_requested_by'] : null,
			(string) ($row['refresh_status'] ?? StrategyRefreshQueue::STATUS_IDLE),
			$row['refresh_source'] ?? null,
			$row['refresh_due_at'] ?? null,
			$row['refresh_started_at'] ?? null,
			$row['refresh_lease_until'] ?? null,
			$row['refresh_finished_at'] ?? null,
			(int) ($row['refresh_attempts'] ?? 0),
			$row['refresh_error'] ?? null,
			$row['refresh_error_at'] ?? null,
			$row['failed_key_hex'] ?? null,
			$row['seen_key_hex'] ?? null,
			$row['refresh_seen_at'] ?? null,
			$row['refresh_dirty_since'] ?? null,
		);
	}
}
