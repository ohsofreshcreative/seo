<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Przebiegi pomiaru (`serp_runs`). `slot_key` (UNIQUE z projektem) chroni przed dwoma przebiegami tego samego terminu
 * harmonogramu (nakładające się crony); przebiegi pominięte nie zajmują terminu (`slot_key` = NULL).
 */
final class SerpRunRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
		private readonly SerpSnapshotRepository $snapshots,
	) {
	}

	/**
	 * @return int|null identyfikator; null — termin harmonogramu jest już zajęty
	 */
	public function create(int $projectId, int $contextId, string $trigger, ?string $slotKey, string $status, int $planned, int $skipped, float $estimatedCost, ?int $userId, ?string $skipReason = null): ?int
	{
		$now = $this->now();
		$affected = $this->db->execute(
			"INSERT IGNORE INTO `{$this->table()}` (public_id, project_id, context_id, trigger_type, slot_key, status, skip_reason, keywords_planned, keywords_skipped, estimated_cost, created_by, created_at, finished_at, updated_at)
			VALUES (%s, %d, %d, %s, " . ($slotKey === null ? 'NULL' : '%s') . ', %s, ' . ($skipReason === null ? 'NULL' : '%s') . ', %d, %d, %f, ' . ($userId === null ? 'NULL' : '%d') . ', %s, ' . ($status === SerpRun::SKIPPED ? '%s' : 'NULL') . ', %s)',
			array_values(array_filter([
				Ulid::generate(), $projectId, $contextId, $trigger, $slotKey, $status, $skipReason, $planned, $skipped, round($estimatedCost, 6), $userId, $now,
				$status === SerpRun::SKIPPED ? $now : null, $now,
			], static fn (mixed $value): bool => $value !== null)),
		);

		return $affected === 1 ? (int) $this->db->fetchValue('SELECT LAST_INSERT_ID()') : null;
	}

	public function find(int $projectId, string $publicId): ?SerpRun
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE project_id = %d AND public_id = %s", [$projectId, $publicId]);

		return $row === null ? null : SerpRun::fromRow($row);
	}

	public function findById(int $id): ?SerpRun
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE id = %d", [$id]);

		return $row === null ? null : SerpRun::fromRow($row);
	}

	/**
	 * @return list<SerpRun>
	 */
	public function recent(int $projectId, int $limit = 10): array
	{
		return array_map(
			static fn (array $row): SerpRun => SerpRun::fromRow($row),
			$this->db->fetchAll("SELECT * FROM `{$this->table()}` WHERE project_id = %d ORDER BY id DESC LIMIT %d", [$projectId, max(1, $limit)]),
		);
	}

	public function active(int $projectId): ?SerpRun
	{
		$row = $this->db->fetchRow(
			"SELECT * FROM `{$this->table()}` WHERE project_id = %d AND status IN ('queued', 'submitting', 'submitted') ORDER BY id DESC LIMIT 1",
			[$projectId],
		);

		return $row === null ? null : SerpRun::fromRow($row);
	}

	public function markSubmitting(int $runId): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'submitting', submitted_at = COALESCE(submitted_at, %s), updated_at = %s WHERE id = %d AND status = 'queued'",
			[$this->now(), $this->now(), $runId],
		);
	}

	public function recordError(int $runId, string $code, string $message): void
	{
		$this->db->update($this->table(), ['error_code' => $code, 'error_message' => mb_substr($message, 0, 250), 'updated_at' => $this->now()], ['id' => $runId]);
	}

	/**
	 * Liczniki i stan przebiegu z jego pomiarów: wszystkie wysłane → `submitted`; wszystkie zakończone → `completed`,
	 * `partial` (część bez wyniku) albo `failed` (żaden wynik).
	 */
	public function refresh(int $runId): ?string
	{
		$counts = $this->snapshots->runCounts($runId);
		$n = static fn (string $status): int => $counts[$status] ?? 0;
		$queued = $n('queued');
		$open = $n('uncertain') + $n('submitted');
		$completed = $n('completed');
		$failed = $n('failed') + $n('expired');
		$cancelled = $n('cancelled');
		$cost = 0;

		foreach ($counts as $key => $value) {
			$cost += str_starts_with($key, 'cost:') ? $value : 0;
		}

		$status = match (true) {
			$queued > 0 => null,
			$open > 0 => SerpRun::SUBMITTED,
			$completed > 0 && $failed + $cancelled === 0 => SerpRun::COMPLETED,
			$completed > 0 => SerpRun::PARTIAL,
			$failed > 0 => SerpRun::FAILED,
			default => SerpRun::CANCELLED,
		};
		$finished = in_array($status, [SerpRun::COMPLETED, SerpRun::PARTIAL, SerpRun::FAILED, SerpRun::CANCELLED], true);

		$this->db->execute(
			"UPDATE `{$this->table()}` SET tasks_submitted = %d, tasks_completed = %d, tasks_failed = %d, cost = %f,
				status = " . ($status === null ? 'status' : '%s') . ',
				finished_at = ' . ($finished ? 'COALESCE(finished_at, %s)' : 'NULL') . ", updated_at = %s
			WHERE id = %d AND status IN ('queued', 'submitting', 'submitted')",
			array_values(array_filter([
				$completed + $failed + $open, $completed, $failed + $cancelled, $cost / 1000000,
				$status, $finished ? $this->now() : null, $this->now(), $runId,
			], static fn (mixed $value): bool => $value !== null)),
		);

		return $status;
	}

	/**
	 * Anulowanie: zaplanowane, jeszcze niewysłane pomiary nie zostaną zlecone (rezerwacja kosztu zwolniona).
	 *
	 * @return list<int> anulowane pomiary
	 */
	public function cancelQueued(int $runId): array
	{
		$ids = array_map(static fn (array $row): int => (int) $row['id'], $this->db->fetchAll(
			"SELECT id FROM `{$this->db->table('serp_snapshots')}` WHERE run_id = %d AND status = 'queued'",
			[$runId],
		));
		$this->snapshots->markCancelled($ids, 'cancelled');

		return $ids;
	}

	/**
	 * Przebiegi z niewysłanymi pomiarami (do wznowienia w kolejnym przebiegu tła).
	 *
	 * @return list<int>
	 */
	public function withQueued(int $limit): array
	{
		return array_map(static fn (array $row): int => (int) $row['id'], $this->db->fetchAll(
			"SELECT id FROM `{$this->table()}` WHERE status IN ('queued', 'submitting') ORDER BY id LIMIT %d",
			[max(1, $limit)],
		));
	}

	/**
	 * @return list<int>
	 */
	public function openIds(int $limit = 200): array
	{
		return array_map(static fn (array $row): int => (int) $row['id'], $this->db->fetchAll(
			"SELECT id FROM `{$this->table()}` WHERE status IN ('queued', 'submitting', 'submitted') ORDER BY id LIMIT %d",
			[max(1, $limit)],
		));
	}

	private function table(): string
	{
		return $this->db->table('serp_runs');
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
