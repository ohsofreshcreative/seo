<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Gsc\Dataset;
use OsfSeo\Support\DateRange;

/** Zadanie synchronizacji (`sync_runs`). Czasy w UTC (Y-m-d H:i:s). */
final class SyncRun
{
	public function __construct(
		public readonly int $id,
		public readonly int $projectId,
		public readonly Dataset $dataset,
		public readonly TriggerType $trigger,
		public readonly DateRange $range,
		public readonly RunStatus $status,
		public readonly int $priority,
		public readonly int $attempt,
		public readonly int $rowsFetched,
		public readonly int $rowsWritten,
		public readonly int $apiRequests,
		public readonly ?string $errorCode,
		public readonly ?string $errorMessage,
		public readonly string $queuedAt,
		public readonly ?string $availableAt,
		public readonly ?string $startedAt,
		public readonly ?string $finishedAt,
		public readonly ?string $property,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			id: (int) $row['id'],
			projectId: (int) $row['project_id'],
			dataset: Dataset::from((string) $row['dataset']),
			trigger: TriggerType::from((string) $row['trigger_type']),
			range: new DateRange((string) $row['window_start'], (string) $row['window_end']),
			status: RunStatus::from((string) $row['status']),
			priority: (int) $row['priority'],
			attempt: (int) $row['attempt'],
			rowsFetched: (int) $row['rows_fetched'],
			rowsWritten: (int) $row['rows_written'],
			apiRequests: (int) $row['api_requests'],
			errorCode: $row['error_code'],
			errorMessage: $row['error_message'],
			queuedAt: (string) $row['queued_at'],
			availableAt: $row['available_at'],
			startedAt: $row['started_at'],
			finishedAt: $row['finished_at'],
			property: $row['property'],
		);
	}

	public function isBackfill(): bool
	{
		return $this->trigger->isBackfill();
	}
}
