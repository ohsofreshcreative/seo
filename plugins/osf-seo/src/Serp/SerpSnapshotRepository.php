<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Pomiary (`serp_snapshots`) — jeden pomiar = jedno zadanie dostawcy. Cykl życia:
 *
 * `queued` (zaplanowany, koszt zarezerwowany) → `uncertain` (zlecenie w drodze) → `submitted` (zadanie przyjęte)
 * → `completed` (wynik zapisany) albo `failed` / `expired` / `cancelled`.
 *
 * `uncertain` zostaje, gdy odpowiedź na zlecenie nie dotarła: zadania nie wysyłamy ponownie (mogło zostać opłacone),
 * odzyskuje je lista gotowych zadań po `tag` (= `public_id`).
 */
final class SerpSnapshotRepository
{
	public const RECOVERABLE = ['uncertain', 'submitted'];

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Zaplanowane pomiary jednej paczki zlecenia (do 100) — w transakcji z wierszem rejestru kosztów.
	 *
	 * @param list<array{id: int, market_keyword_id: int}> $keywords
	 */
	public function createQueued(int $projectId, int $runId, int $contextId, int $marketTaskId, string $trigger, string $provider, int $depth, float $estimatedCost, array $keywords): int
	{
		$insert = new BulkInsert(
			$this->db,
			$this->table(),
			['public_id', 'project_id', 'tracked_keyword_id', 'market_keyword_id', 'context_id', 'run_id', 'market_task_id', 'provider', 'status', 'trigger_type', 'requested_depth', 'estimated_cost', 'created_at'],
			['%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%s'],
			'',
			500,
		);
		$now = $this->now();

		foreach ($keywords as $keyword) {
			$insert->add([Ulid::generate(), $projectId, $keyword['id'], $keyword['market_keyword_id'], $contextId, $runId, $marketTaskId, $provider, 'queued', $trigger, $depth, round($estimatedCost, 6), $now]);
		}

		$insert->flush();

		return $insert->affectedRows();
	}

	/**
	 * Paczki zlecenia czekające na wysłanie (wiersz rejestru kosztów = paczka), od najstarszych przebiegów.
	 *
	 * @return list<array{market_task_id: int, run_id: int, project_id: int}>
	 */
	public function queuedBatches(int $limit, ?int $runId = null): array
	{
		return array_map(static fn (array $row): array => [
			'market_task_id' => (int) $row['market_task_id'],
			'run_id' => (int) $row['run_id'],
			'project_id' => (int) $row['project_id'],
		], $this->db->fetchAll(
			"SELECT market_task_id, MIN(run_id) AS run_id, MIN(project_id) AS project_id FROM `{$this->table()}`
			WHERE status = 'queued'" . ($runId === null ? '' : ' AND run_id = %d') . ' GROUP BY market_task_id ORDER BY MIN(run_id), market_task_id LIMIT %d',
			$runId === null ? [max(1, $limit)] : [$runId, max(1, $limit)],
		));
	}

	/**
	 * Pomiary paczki do wysłania z frazą (postać znormalizowana).
	 *
	 * @return list<array{id: int, public_id: string, tracked_keyword_id: int, context_id: int, keyword: string, estimated_cost: float}>
	 */
	public function batch(int $marketTaskId): array
	{
		return array_map(static fn (array $row): array => [
			'id' => (int) $row['id'],
			'public_id' => (string) $row['public_id'],
			'tracked_keyword_id' => (int) $row['tracked_keyword_id'],
			'context_id' => (int) $row['context_id'],
			'keyword' => (string) $row['keyword'],
			'estimated_cost' => (float) $row['estimated_cost'],
		], $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN s.id, s.public_id, s.tracked_keyword_id, s.context_id, s.estimated_cost, m.keyword
			FROM `{$this->table()}` s JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			WHERE s.market_task_id = %d AND s.status = 'queued' ORDER BY s.id",
			[$marketTaskId],
		));
	}

	/**
	 * Zlecenie w drodze — zapisane PRZED wysłaniem: jeśli proces padnie po wysłaniu, pomiar nie zostanie zlecony ponownie.
	 *
	 * @param list<int> $ids
	 */
	public function markUncertain(array $ids): void
	{
		$this->updateIds($ids, "status = 'uncertain', submitted_at = %s", [$this->now()], "status = 'queued'");
	}

	public function markSubmitted(int $id, string $taskId, ?float $cost, string $nextCheckAt): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'submitted', provider_task_id = %s, cost = " . ($cost === null ? 'NULL' : '%f') . ", next_check_at = %s, error_code = NULL
			WHERE id = %d AND status IN ('uncertain', 'queued')",
			$cost === null ? [$taskId, $nextCheckAt, $id] : [$taskId, round($cost, 6), $nextCheckAt, $id],
		);
	}

	/** Niepewne zadanie odnalezione na liście gotowych zadań (po `tag`) — od teraz odbierane normalnie. */
	public function recover(int $id, string $taskId): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'submitted', provider_task_id = %s, next_check_at = %s WHERE id = %d AND status = 'uncertain'",
			[$taskId, $this->now(), $id],
		);
	}

	/**
	 * @param list<int> $ids
	 * @param bool $charged czy dostawca mógł naliczyć koszt (wtedy zostaje szacunek)
	 */
	public function markFailed(array $ids, string $errorCode, bool $charged = false): void
	{
		$this->updateIds(
			$ids,
			'status = \'failed\', error_code = %s, next_check_at = NULL' . ($charged ? '' : ', cost = 0'),
			[$errorCode],
			"status IN ('queued', 'uncertain', 'submitted')",
		);
	}

	/**
	 * @param list<int> $ids
	 */
	public function markCancelled(array $ids, string $reason): void
	{
		$this->updateIds($ids, "status = 'cancelled', error_code = %s, cost = 0", [$reason], "status = 'queued'");
	}

	/** Zlecenie wróciło do kolejki (dostawca nic nie wykonał: limit żądań, wstrzymanie po błędzie konta). */
	public function requeue(array $ids): void
	{
		$this->updateIds($ids, "status = 'queued', submitted_at = NULL", [], "status = 'uncertain'");
	}

	/**
	 * Zadania do odbioru (bezpłatny `task_get`).
	 *
	 * @return list<array<string, string|null>>
	 */
	public function due(int $limit): array
	{
		return $this->db->fetchAll(
			"SELECT id, public_id, project_id, tracked_keyword_id, context_id, run_id, provider_task_id, attempts, submitted_at, requested_depth
			FROM `{$this->table()}` WHERE status = 'submitted' AND next_check_at <= %s ORDER BY next_check_at, id LIMIT %d",
			[$this->now(), max(1, $limit)],
		);
	}

	public function hasPending(): bool
	{
		return $this->db->fetchValue("SELECT 1 FROM `{$this->table()}` WHERE status IN ('uncertain', 'submitted') LIMIT 1") !== null;
	}

	/**
	 * Dopasowanie listy gotowych zadań do pomiarów tej instalacji (po identyfikatorze zadania albo `tag`).
	 * Zadania nieznane (np. innej instalacji na tym samym koncie) są ignorowane — nigdy ich nie odbieramy.
	 *
	 * @param list<SerpReadyTask> $ready
	 * @return array{ready: int, recovered: int}
	 */
	public function markReady(array $ready): array
	{
		$result = ['ready' => 0, 'recovered' => 0];

		foreach (array_chunk($ready, 500) as $chunk) {
			$ids = array_map(static fn (SerpReadyTask $task): string => $task->id, $chunk);
			$result['ready'] += $this->db->execute(
				"UPDATE `{$this->table()}` SET next_check_at = %s WHERE status = 'submitted' AND provider_task_id IN (" . Connection::placeholders($ids) . ')',
				[$this->now(), ...$ids],
			);
			$tags = array_values(array_filter(array_map(static fn (SerpReadyTask $task): ?string => $task->tag, $chunk), static fn (?string $tag): bool => $tag !== null && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $tag) === 1));

			if ($tags === []) {
				continue;
			}

			$byTag = [];

			foreach ($chunk as $task) {
				if ($task->tag !== null) {
					$byTag[$task->tag] = $task->id;
				}
			}

			foreach ($this->db->fetchAll(
				"SELECT id, public_id FROM `{$this->table()}` WHERE status = 'uncertain' AND public_id IN (" . Connection::placeholders($tags) . ')',
				$tags,
			) as $row) {
				$this->recover((int) $row['id'], $byTag[(string) $row['public_id']]);
				$result['recovered']++;
			}
		}

		return $result;
	}

	public function reschedule(int $id, string $nextCheckAt, ?string $errorCode = null): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET attempts = LEAST(attempts + 1, 255), next_check_at = %s, error_code = NULLIF(%s, '') WHERE id = %d AND status = 'submitted'",
			[$nextCheckAt, $errorCode ?? '', $id],
		);
	}

	public function markExpired(int $id): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'expired', error_code = 'expired', next_check_at = NULL WHERE id = %d AND status = 'submitted'",
			[$id],
		);
	}

	/**
	 * Niepewne zlecenia, których lista gotowych zadań nie zwróciła w oknie odzyskiwania — kończymy je bez ponawiania.
	 *
	 * @return list<array{id: int, run_id: int}>
	 */
	public function closeUncertain(string $before): array
	{
		$rows = $this->db->fetchAll("SELECT id, run_id FROM `{$this->table()}` WHERE status = 'uncertain' AND submitted_at < %s LIMIT 1000", [$before]);
		$ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
		$this->markFailed($ids, 'interrupted', true);

		return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'run_id' => (int) $row['run_id']], $rows);
	}

	/**
	 * Przejęcie pomiaru do zapisu wyniku (w transakcji zapisu): tylko jeden proces zapisze wynik danego zadania.
	 */
	public function claim(int $id, string $checkedAt): bool
	{
		return $this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'completed', checked_at = %s, collected_at = %s, next_check_at = NULL, error_code = NULL
			WHERE id = %d AND status IN ('submitted', 'uncertain')",
			[$checkedAt, $this->now(), $id],
		) === 1;
	}

	/**
	 * @param array<string, int|float|string|null> $data
	 */
	public function update(int $id, array $data): void
	{
		$this->db->update($this->table(), $data, ['id' => $id]);
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public function find(int $id): ?array
	{
		return $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE id = %d", [$id]);
	}

	/**
	 * @return array<string, int> status → liczba pomiarów przebiegu
	 */
	public function runCounts(int $runId): array
	{
		$counts = [];

		foreach ($this->db->fetchAll("SELECT status, COUNT(*) AS n, COALESCE(SUM(COALESCE(cost, estimated_cost)), 0) AS cost FROM `{$this->table()}` WHERE run_id = %d GROUP BY status", [$runId]) as $row) {
			$counts[(string) $row['status']] = (int) $row['n'];
			$counts['cost:' . $row['status']] = (int) round((float) $row['cost'] * 1000000);
		}

		return $counts;
	}

	/**
	 * @param list<int> $ids
	 * @param list<int|float|string> $params
	 */
	private function updateIds(array $ids, string $set, array $params, string $condition): void
	{
		foreach (array_chunk(array_values($ids), 500) as $chunk) {
			$this->db->execute(
				"UPDATE `{$this->table()}` SET {$set} WHERE {$condition} AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[...$params, ...$chunk],
			);
		}
	}

	private function table(): string
	{
		return $this->db->table('serp_snapshots');
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
