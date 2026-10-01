<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Gsc\Dataset;

/**
 * Stan datasetu projektu (`sync_state`). Pokrycie danych jest ciągłe: [oldestDate, newestDate].
 *
 * - newestDate: dla `site` — najnowsza data z danymi w GSC (= ostatnia dostępna data `final`),
 *   dla fraz — koniec zaimportowanego pokrycia,
 * - oldestDate: kursor backfillu (najstarsza zaimportowana data),
 * - refreshCursor: następna data cyklu odświeżania okna kroczącego (null = brak trwającego cyklu),
 * - retryAfter: do tej chwili planista nie zleca nowych zadań datasetu (po trwałym błędzie).
 */
final class SyncState
{
	public function __construct(
		public readonly int $projectId,
		public readonly Dataset $dataset,
		public readonly string $status = 'idle',
		public readonly ?string $newestDate = null,
		public readonly ?string $oldestDate = null,
		public readonly ?string $refreshCursor = null,
		public readonly int $consecutiveFailures = 0,
		public readonly ?string $lastSuccessAt = null,
		public readonly ?string $lastAttemptAt = null,
		public readonly ?string $lastRefreshAt = null,
		public readonly ?string $retryAfter = null,
		public readonly ?string $lastError = null,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			projectId: (int) $row['project_id'],
			dataset: Dataset::from((string) $row['dataset']),
			status: (string) $row['status'],
			newestDate: $row['newest_date'],
			oldestDate: $row['oldest_date'],
			refreshCursor: $row['refresh_cursor'] ?? null,
			consecutiveFailures: (int) $row['consecutive_failures'],
			lastSuccessAt: $row['last_success_at'],
			lastAttemptAt: $row['last_attempt_at'],
			lastRefreshAt: $row['last_refresh_at'] ?? null,
			retryAfter: $row['retry_after'] ?? null,
			lastError: $row['last_error'],
		);
	}

	/** Planista pomija dataset do czasu `retryAfter` (UTC, Y-m-d H:i:s). */
	public function isCoolingDown(string $now): bool
	{
		return $this->retryAfter !== null && $this->retryAfter > $now;
	}
}
