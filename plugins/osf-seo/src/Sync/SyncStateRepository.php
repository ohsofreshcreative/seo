<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Database\Connection;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Support\Clock;

/**
 * `osf_sync_state`. @internal — używane przez usługi synchronizacji (projekt już autoryzowany
 * albo zadanie systemowe); aktualizacje dotyczą wyłącznie wskazanych pól (refresh i backfill tego
 * samego datasetu nie nadpisują sobie stanu).
 */
final class SyncStateRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array<string, SyncState> dataset → stan (brakujące — stan pusty)
	 */
	public function forProject(int $projectId): array
	{
		$states = [];

		foreach (Dataset::cases() as $dataset) {
			$states[$dataset->value] = new SyncState($projectId, $dataset);
		}

		foreach ($this->db->fetchAll("SELECT * FROM `{$this->table()}` WHERE project_id = %d", [$projectId]) as $row) {
			$dataset = Dataset::tryFrom((string) $row['dataset']);

			if ($dataset !== null) {
				$states[$dataset->value] = SyncState::fromRow($row);
			}
		}

		return $states;
	}

	public function get(int $projectId, Dataset $dataset): SyncState
	{
		return $this->forProject($projectId)[$dataset->value];
	}

	/**
	 * Zmiana wybranych pól (wiersz tworzony przy pierwszym zapisie).
	 *
	 * @param array<string, int|string|null> $fields
	 */
	public function update(int $projectId, Dataset $dataset, array $fields): void
	{
		$allowed = ['status', 'newest_date', 'oldest_date', 'refresh_cursor', 'consecutive_failures', 'last_success_at', 'last_attempt_at', 'last_refresh_at', 'retry_after', 'last_error'];
		$fields = array_intersect_key($fields, array_flip($allowed));
		$fields['updated_at'] = $this->now();

		$this->db->execute(
			"INSERT IGNORE INTO `{$this->table()}` (project_id, dataset, status, updated_at) VALUES (%d, %s, 'idle', %s)",
			[$projectId, $dataset->value, $fields['updated_at']],
		);
		$this->db->update($this->table(), $fields, ['project_id' => $projectId, 'dataset' => $dataset->value]);
	}

	public function incrementFailures(int $projectId, Dataset $dataset): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET consecutive_failures = LEAST(consecutive_failures + 1, 65535) WHERE project_id = %d AND dataset = %s",
			[$projectId, $dataset->value],
		);
	}

	/** Zdjęcie przerwy po błędach (ręczne „Synchronizuj teraz”). */
	public function clearCooldown(int $projectId): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET retry_after = NULL, updated_at = %s WHERE project_id = %d",
			[$this->now(), $projectId],
		);
	}

	public function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	private function table(): string
	{
		return $this->db->table('sync_state');
	}
}
