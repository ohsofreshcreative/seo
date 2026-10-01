<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Database\Connection;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Gsc\ImportResult;
use OsfSeo\Support\Clock;

/**
 * Kolejka i historia zadań (`osf_sync_runs`). @internal — wyłącznie dla usług synchronizacji.
 * Komunikaty błędów są skracane; nigdy nie zawierają tokenów (pochodzą z wyjątków bez treści odpowiedzi).
 */
final class SyncRunRepository
{
	private const MESSAGE_LIMIT = 500;

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function enqueue(int $projectId, PlannedJob $job, string $property): int
	{
		$now = $this->now();

		return $this->db->insert($this->table(), [
			'project_id' => $projectId,
			'dataset' => $job->dataset->value,
			'trigger_type' => $job->trigger->value,
			'window_start' => $job->range->start,
			'window_end' => $job->range->end,
			'status' => RunStatus::Queued->value,
			'priority' => $job->priority,
			'attempt' => 1,
			'queued_at' => $now,
			'available_at' => $now,
			'property' => $property,
		]);
	}

	public function find(int $id): ?SyncRun
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE id = %d", [$id]);

		return $row === null ? null : SyncRun::fromRow($row);
	}

	/**
	 * Klucze `dataset:refresh` / `dataset:backfill` zadań oczekujących projektu.
	 *
	 * @return array<string, true>
	 */
	public function pendingKinds(int $projectId): array
	{
		$rows = $this->db->fetchAll(
			"SELECT DISTINCT dataset, trigger_type FROM `{$this->table()}` WHERE project_id = %d AND status IN (" . Connection::placeholders(RunStatus::pendingValues()) . ')',
			[$projectId, ...RunStatus::pendingValues()],
		);
		$kinds = [];

		foreach ($rows as $row) {
			$kinds[$row['dataset'] . ':' . PlannedJob::kindOf(TriggerType::from((string) $row['trigger_type']))] = true;
		}

		return $kinds;
	}

	public function pendingCount(int $projectId): int
	{
		return (int) $this->db->fetchValue(
			"SELECT COUNT(*) FROM `{$this->table()}` WHERE project_id = %d AND status IN (" . Connection::placeholders(RunStatus::pendingValues()) . ')',
			[$projectId, ...RunStatus::pendingValues()],
		);
	}

	/**
	 * Gęstość danych (wiersze/dzień) z ostatniego udanego zadania każdego datasetu — szerokość okien.
	 *
	 * @return array<string, float|null>
	 */
	public function densities(int $projectId): array
	{
		$densities = [];

		foreach ([Dataset::Query, Dataset::QueryPage] as $dataset) {
			$row = $this->db->fetchRow(
				"SELECT rows_fetched, DATEDIFF(window_end, window_start) + 1 AS days FROM `{$this->table()}`
				WHERE project_id = %d AND dataset = %s AND status = 'success' AND rows_fetched > 0 ORDER BY id DESC LIMIT 1",
				[$projectId, $dataset->value],
			);
			$densities[$dataset->value] = $row === null ? null : (int) $row['rows_fetched'] / max(1, (int) $row['days']);
		}

		return $densities;
	}

	/**
	 * Pobiera i rezerwuje następne zadanie gotowe do uruchomienia (priorytet, potem kolejność).
	 */
	public function claimNext(int $leaseSeconds, ?int $projectId = null): ?SyncRun
	{
		$now = $this->now();
		$params = [$now];
		$projectSql = '';

		if ($projectId !== null) {
			$projectSql = ' AND project_id = %d';
			$params[] = $projectId;
		}

		$id = $this->db->fetchValue(
			"SELECT id FROM `{$this->table()}` WHERE status IN ('queued', 'retrying') AND available_at <= %s{$projectSql}
			ORDER BY priority, available_at, id LIMIT 1",
			$params,
		);

		if ($id === null) {
			return null;
		}

		$claimed = $this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'running', started_at = %s, finished_at = NULL, locked_until = %s
			WHERE id = %d AND status IN ('queued', 'retrying')",
			[$now, $this->offset($leaseSeconds), (int) $id],
		);

		return $claimed === 1 ? $this->find((int) $id) : null;
	}

	public function markSuccess(int $id, ImportResult $result): void
	{
		$this->db->update($this->table(), [
			'status' => RunStatus::Success->value,
			'rows_fetched' => $result->rowsFetched,
			'rows_written' => $result->rowsWritten,
			'api_requests' => min(65535, $result->apiRequests),
			'error_code' => null,
			'error_message' => null,
			'finished_at' => $this->now(),
			'locked_until' => null,
		], ['id' => $id]);
	}

	public function markRetry(int $id, int $nextAttempt, int $delaySeconds, string $errorCode, string $message): void
	{
		$this->db->update($this->table(), [
			'status' => RunStatus::Retrying->value,
			'attempt' => $nextAttempt,
			'available_at' => $this->offset($delaySeconds),
			'error_code' => substr($errorCode, 0, 64),
			'error_message' => mb_substr($message, 0, self::MESSAGE_LIMIT),
			'finished_at' => $this->now(),
			'locked_until' => null,
		], ['id' => $id]);
	}

	public function markFinal(int $id, RunStatus $status, string $errorCode, string $message): void
	{
		$this->db->update($this->table(), [
			'status' => $status->value,
			'error_code' => substr($errorCode, 0, 64),
			'error_message' => mb_substr($message, 0, self::MESSAGE_LIMIT),
			'finished_at' => $this->now(),
			'locked_until' => null,
		], ['id' => $id]);
	}

	/** Anuluje oczekujące zadania projektu (np. reset danych przy zmianie property). */
	public function cancelPending(int $projectId, string $reason): int
	{
		return $this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'cancelled', error_code = %s, finished_at = %s, locked_until = NULL
			WHERE project_id = %d AND status IN ('queued', 'retrying')",
			[$reason, $this->now(), $projectId],
		);
	}

	/** Anuluje oczekujące zadania wszystkich projektów korzystających z połączenia Google. */
	public function cancelPendingForConnection(int $connectionId, string $reason): int
	{
		return $this->db->execute(
			"UPDATE `{$this->table()}` r JOIN `{$this->db->table('projects')}` p ON p.id = r.project_id
			SET r.status = 'cancelled', r.error_code = %s, r.finished_at = %s, r.locked_until = NULL
			WHERE p.connection_id = %d AND r.status IN ('queued', 'retrying')",
			[$reason, $this->now(), $connectionId],
		);
	}

	/**
	 * Zadania „running” bez żywego runnera (proces zakończony w trakcie). Wywoływane przez runner
	 * trzymający globalną blokadę — wtedy żadne inne zadanie nie może naprawdę trwać.
	 *
	 * @return list<SyncRun>
	 */
	public function orphanedRuns(): array
	{
		return array_map(
			static fn (array $row): SyncRun => SyncRun::fromRow($row),
			$this->db->fetchAll("SELECT * FROM `{$this->table()}` WHERE status = 'running' ORDER BY id"),
		);
	}

	/**
	 * @return list<SyncRun>
	 */
	public function recent(int $projectId, int $limit = 10): array
	{
		return array_map(
			static fn (array $row): SyncRun => SyncRun::fromRow($row),
			$this->db->fetchAll("SELECT * FROM `{$this->table()}` WHERE project_id = %d ORDER BY id DESC LIMIT %d", [$projectId, max(1, min($limit, 100))]),
		);
	}

	public function lastManualQueuedAt(int $projectId): ?string
	{
		return $this->db->fetchValue(
			"SELECT MAX(queued_at) FROM `{$this->table()}` WHERE project_id = %d AND trigger_type = 'manual'",
			[$projectId],
		);
	}

	/**
	 * Ostatnia próba i ostatni sukces projektu (dowolny dataset).
	 *
	 * @return array{last_success: ?string, last_attempt: ?string}
	 */
	public function lastTimes(int $projectId): array
	{
		$row = $this->db->fetchRow(
			"SELECT MAX(CASE WHEN status = 'success' THEN finished_at END) AS last_success, MAX(started_at) AS last_attempt
			FROM `{$this->table()}` WHERE project_id = %d",
			[$projectId],
		);

		return ['last_success' => $row['last_success'] ?? null, 'last_attempt' => $row['last_attempt'] ?? null];
	}

	/** Usuwa zakończone zadania starsze niż retencja. */
	public function purgeFinishedBefore(string $before): int
	{
		return $this->db->execute(
			"DELETE FROM `{$this->table()}` WHERE status IN ('success', 'failed', 'skipped', 'cancelled') AND queued_at < %s LIMIT 10000",
			[$before],
		);
	}

	/** Wiersze stagingu bez trwającego zadania (proces przerwany w trakcie importu). */
	public function purgeOrphanedStaging(): int
	{
		return $this->db->execute(
			"DELETE s FROM `{$this->db->table('gsc_import_staging')}` s
			LEFT JOIN `{$this->table()}` r ON r.id = s.run_id AND r.status = 'running'
			WHERE r.id IS NULL",
		);
	}

	public function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	private function offset(int $seconds): string
	{
		return $this->clock->now()->modify(sprintf('%+d seconds', $seconds))->format('Y-m-d H:i:s');
	}

	private function table(): string
	{
		return $this->db->table('sync_runs');
	}
}
