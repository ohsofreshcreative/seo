<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Monitorowane frazy projektu (`serp_tracked_keywords`) i ich bieżący stan pozycji.
 *
 * Tożsamość frazy = wspólna fraza rynkowa (`market_keywords`) — bez duplikatów między GSC, Nowymi frazami i wpisem ręcznym.
 * Usunięcie jest miękkie (status `removed`): historia pomiarów zostaje, ponowne dodanie przywraca tę samą frazę.
 * Status `analysis` (STEP 16): jednorazowa analiza Strategii — poza harmonogramem, listą Pozycji i miękkim limitem; dodanie frazy
 * do monitorowania zmienia go na `active` (ta sama fraza, ta sama historia pomiarów).
 * Bieżący stan (ostatni pomiar, poprzedni porównywalny, zmiana) jest przeliczany przy zapisie pomiaru — lista pozycji
 * czyta tylko tę tabelę, bez skanowania historii.
 */
final class TrackedKeywordRepository
{
	public const SOURCES = ['manual', 'gsc', 'discovery', 'gap', 'strategy'];

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Dodanie fraz (bez żadnego żądania do API). Istniejąca aktywna fraza nie jest duplikowana, usunięta wraca, analizowana
	 * (`analysis`) przechodzi do monitorowania — obie liczone jako przywrócone (historia pomiarów zostaje).
	 *
	 * @param array<int, string> $entries market_keyword_id → źródło
	 * @return array{added: int, restored: int, existing: int}
	 */
	public function add(int $projectId, array $entries, ?int $userId): array
	{
		$result = ['added' => 0, 'restored' => 0, 'existing' => 0];

		if ($entries === []) {
			return $result;
		}

		return $this->db->transaction(function () use ($projectId, $entries, $userId, $result): array {
			$ids = array_keys($entries);
			$existing = [];

			foreach (array_chunk($ids, 500) as $chunk) {
				foreach ($this->db->fetchAll(
					"SELECT id, market_keyword_id, status FROM `{$this->table()}` WHERE project_id = %d AND market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ') FOR UPDATE',
					[$projectId, ...$chunk],
				) as $row) {
					$existing[(int) $row['market_keyword_id']] = $row;
				}
			}

			$now = $this->now();
			$restore = [];
			$insert = new BulkInsert(
				$this->db,
				$this->table(),
				['public_id', 'project_id', 'market_keyword_id', 'source', 'status', 'added_by', 'added_at', 'updated_at'],
				['%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s'],
				'',
				500,
			);

			foreach ($entries as $marketKeywordId => $source) {
				$row = $existing[$marketKeywordId] ?? null;

				if ($row === null) {
					$insert->add([Ulid::generate(), $projectId, $marketKeywordId, in_array($source, self::SOURCES, true) ? $source : 'manual', 'active', (int) $userId, $now, $now]);
					$result['added']++;
				} elseif ($row['status'] === 'removed' || $row['status'] === 'analysis') {
					$restore[] = (int) $row['id'];
				} else {
					$result['existing']++;
				}
			}

			$insert->flush();

			foreach (array_chunk($restore, 500) as $chunk) {
				$result['restored'] += $this->db->execute(
					"UPDATE `{$this->table()}` SET status = 'active', removed_at = NULL, updated_at = %s WHERE project_id = %d AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
					[$now, $projectId, ...$chunk],
				);
			}

			return $result;
		});
	}

	/**
	 * @param list<string> $publicIds
	 */
	public function remove(int $projectId, array $publicIds): int
	{
		$publicIds = array_values(array_filter($publicIds, static fn (string $id): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1));

		if ($publicIds === []) {
			return 0;
		}

		return $this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'removed', removed_at = %s, updated_at = %s WHERE project_id = %d AND status = 'active' AND public_id IN (" . Connection::placeholders($publicIds) . ')',
			[$this->now(), $this->now(), $projectId, ...$publicIds],
		);
	}

	public function activeCount(int $projectId): int
	{
		return (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table()}` WHERE project_id = %d AND status = 'active'", [$projectId]);
	}

	/**
	 * Frazy do pomiaru: aktywne, których pomiaru nie zlecono od $cutoff (ochrona przed nakładaniem się crona, ręcznych
	 * pomiarów i ponowień). Opcjonalnie tylko wskazane frazy.
	 *
	 * @param list<string>|null $publicIds
	 * @return array{eligible: list<array{id: int, market_keyword_id: int, keyword: string, location_code: int, language_code: string}>, recent: int}
	 */
	public function eligible(int $projectId, string $cutoff, ?array $publicIds = null): array
	{
		$filter = '';
		$params = [$projectId];

		if ($publicIds !== null) {
			if ($publicIds === []) {
				return ['eligible' => [], 'recent' => 0];
			}

			$filter = ' AND t.public_id IN (' . Connection::placeholders($publicIds) . ')';
			$params = [...$params, ...$publicIds];
		}

		$eligible = [];
		$recent = 0;

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN t.id, t.market_keyword_id, t.last_requested_at, m.keyword, m.location_code, m.language_code
			FROM `{$this->table()}` t JOIN `{$this->db->table('market_keywords')}` m ON m.id = t.market_keyword_id
			WHERE t.project_id = %d AND t.status = 'active'{$filter} ORDER BY t.id",
			$params,
		) as $row) {
			if ($row['last_requested_at'] !== null && (string) $row['last_requested_at'] >= $cutoff) {
				$recent++;

				continue;
			}

			$eligible[] = [
				'id' => (int) $row['id'],
				'market_keyword_id' => (int) $row['market_keyword_id'],
				'keyword' => (string) $row['keyword'],
				'location_code' => (int) $row['location_code'],
				'language_code' => (string) $row['language_code'],
			];
		}

		return ['eligible' => $eligible, 'recent' => $recent];
	}

	/**
	 * Zajęcie fraz do pomiaru: warunkowo (tylko jeśli nikt ich nie zajął od $cutoff) — drugi równoległy plan ich nie dostanie.
	 *
	 * @param list<int> $ids
	 * @return list<int> faktycznie zajęte
	 */
	public function claim(array $ids, string $cutoff): array
	{
		$claimed = [];
		$now = $this->now();

		foreach (array_chunk($ids, 500) as $chunk) {
			$rows = $this->db->fetchAll(
				"SELECT id FROM `{$this->table()}` WHERE id IN (" . Connection::placeholders($chunk, '%d') . ") AND status IN ('active', 'analysis')
				AND (last_requested_at IS NULL OR last_requested_at < %s) FOR UPDATE",
				[...$chunk, $cutoff],
			);
			$free = array_map(static fn (array $row): int => (int) $row['id'], $rows);

			if ($free !== []) {
				$this->db->execute(
					"UPDATE `{$this->table()}` SET last_requested_at = %s WHERE id IN (" . Connection::placeholders($free, '%d') . ')',
					[$now, ...$free],
				);
				$claimed = [...$claimed, ...$free];
			}
		}

		return $claimed;
	}

	/**
	 * Frazy do jednorazowej analizy Strategii (bez żadnego żądania): brakujące — nowe wiersze `analysis` (źródło `strategy`), usunięte
	 * z monitorowania — `analysis` (historia zostaje), monitorowane i analizowane — bez zmian (pomiar analizy ich nie zmienia).
	 *
	 * @param list<int> $marketKeywordIds
	 * @return array{rows: array<int, array{id: int, status: string, last_requested_at: ?string}>, created: list<int>, changed: list<int>}
	 */
	public function prepareAnalysis(int $projectId, array $marketKeywordIds, ?int $userId): array
	{
		$marketKeywordIds = array_values(array_unique(array_map('intval', $marketKeywordIds)));

		if ($marketKeywordIds === []) {
			return ['rows' => [], 'created' => [], 'changed' => []];
		}

		return $this->db->transaction(function () use ($projectId, $marketKeywordIds, $userId): array {
			$existing = $this->rowsFor($projectId, $marketKeywordIds, true);
			$now = $this->now();
			$insert = new BulkInsert(
				$this->db,
				$this->table(),
				['public_id', 'project_id', 'market_keyword_id', 'source', 'status', 'added_by', 'added_at', 'updated_at'],
				['%s', '%d', '%d', '%s', '%s', 'NULLIF(%d, 0)', '%s', '%s'],
				'',
				500,
			);
			$changed = [];

			foreach ($marketKeywordIds as $marketKeywordId) {
				$row = $existing[$marketKeywordId] ?? null;

				if ($row === null) {
					$insert->add([Ulid::generate(), $projectId, $marketKeywordId, 'strategy', 'analysis', max(0, (int) $userId), $now, $now]);
				} elseif ($row['status'] === 'removed') {
					$changed[] = $row['id'];
				}
			}

			$insert->flush();

			foreach (array_chunk($changed, 500) as $chunk) {
				$this->db->execute(
					"UPDATE `{$this->table()}` SET status = 'analysis', updated_at = %s WHERE project_id = %d AND status = 'removed' AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
					[$now, $projectId, ...$chunk],
				);
			}

			$rows = $this->rowsFor($projectId, $marketKeywordIds, false);
			$created = [];

			foreach ($rows as $marketKeywordId => $row) {
				if (! isset($existing[$marketKeywordId])) {
					$created[] = $row['id'];
				}
			}

			return ['rows' => $rows, 'created' => $created, 'changed' => $changed];
		});
	}

	/**
	 * Cofnięcie przygotowania analizy, której nie zakolejkowano (limit, nic do zrobienia): usunięcie wierszy utworzonych przez to
	 * przygotowanie (bez pomiarów) i powrót usuniętych fraz do `removed`.
	 *
	 * @param list<int> $created
	 * @param list<int> $changed
	 */
	public function rollbackAnalysis(int $projectId, array $created, array $changed): void
	{
		foreach (array_chunk($created, 500) as $chunk) {
			$this->db->execute(
				"DELETE t FROM `{$this->table()}` t WHERE t.project_id = %d AND t.status = 'analysis' AND t.last_snapshot_id IS NULL AND t.last_requested_at IS NULL
				AND t.id IN (" . Connection::placeholders($chunk, '%d') . ")
				AND NOT EXISTS (SELECT 1 FROM `{$this->db->table('serp_snapshots')}` s WHERE s.tracked_keyword_id = t.id)",
				[$projectId, ...$chunk],
			);
		}

		foreach (array_chunk($changed, 500) as $chunk) {
			$this->db->execute(
				"UPDATE `{$this->table()}` SET status = 'removed', updated_at = %s WHERE project_id = %d AND status = 'analysis' AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$this->now(), $projectId, ...$chunk],
			);
		}
	}

	/**
	 * Wiersze fraz projektu (każdy status): id frazy rynkowej → id, status, ostatnie zlecenie.
	 *
	 * @param list<int> $marketKeywordIds
	 * @return array<int, array{id: int, status: string, last_requested_at: ?string}>
	 */
	public function rowsFor(int $projectId, array $marketKeywordIds, bool $forUpdate = false): array
	{
		$result = [];

		foreach (array_chunk(array_values(array_unique(array_map('intval', $marketKeywordIds))), 500) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, market_keyword_id, status, last_requested_at FROM `{$this->table()}` WHERE project_id = %d AND market_keyword_id IN ("
				. Connection::placeholders($chunk, '%d') . ')' . ($forUpdate ? ' FOR UPDATE' : ''),
				[$projectId, ...$chunk],
			) as $row) {
				$result[(int) $row['market_keyword_id']] = ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'last_requested_at' => $row['last_requested_at']];
			}
		}

		return $result;
	}

	/** Zwolnienie zajęcia (pomiar nie został wysłany — np. anulowany przed zleceniem). */
	public function release(array $ids): void
	{
		foreach (array_chunk($ids, 500) as $chunk) {
			$this->db->execute("UPDATE `{$this->table()}` SET last_requested_at = NULL WHERE id IN (" . Connection::placeholders($chunk, '%d') . ')', $chunk);
		}
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public function find(int $projectId, string $publicId): ?array
	{
		return $this->db->fetchRow(
			"SELECT * FROM `{$this->table()}` WHERE project_id = %d AND public_id = %s",
			[$projectId, $publicId],
		);
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public function findById(int $id): ?array
	{
		return $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE id = %d", [$id]);
	}

	/**
	 * Bieżący stan frazy z jej pomiarów: najnowszy zakończony pomiar i poprzedni zakończony w tym samym kontekście.
	 * Działa także przy odbiorze w innej kolejności niż zlecenie (spóźniony starszy wynik nie zastępuje nowszego).
	 */
	public function refreshCurrent(int $trackedId, int $snapshotId): void
	{
		$tracked = $this->db->fetchRow("SELECT id, last_snapshot_id, last_checked_at FROM `{$this->table()}` WHERE id = %d FOR UPDATE", [$trackedId]);

		if ($tracked === null) {
			return;
		}

		$snapshots = $this->db->table('serp_snapshots');
		$columns = 'id, context_id, checked_at, requested_depth, project_rank, project_rank_absolute, project_url_id, project_featured';
		$candidate = $this->db->fetchRow("SELECT {$columns} FROM `{$snapshots}` WHERE id = %d AND status = 'completed'", [$snapshotId]);
		$current = $candidate;

		if ($tracked['last_snapshot_id'] !== null && (int) $tracked['last_snapshot_id'] !== $snapshotId) {
			$existing = $this->db->fetchRow("SELECT {$columns} FROM `{$snapshots}` WHERE id = %d AND status = 'completed'", [(int) $tracked['last_snapshot_id']]);

			if ($existing !== null && ($candidate === null || [(string) $existing['checked_at'], (int) $existing['id']] > [(string) $candidate['checked_at'], (int) $candidate['id']])) {
				$current = $existing;
			}
		}

		if ($current === null) {
			return;
		}

		$previous = $this->db->fetchRow(
			"SELECT id, project_rank FROM `{$snapshots}`
			WHERE tracked_keyword_id = %d AND context_id = %d AND status = 'completed' AND id <> %d
				AND (checked_at < %s OR (checked_at = %s AND id < %d))
			ORDER BY checked_at DESC, id DESC LIMIT 1",
			[$trackedId, (int) $current['context_id'], (int) $current['id'], (string) $current['checked_at'], (string) $current['checked_at'], (int) $current['id']],
		);
		$hadAny = $previous !== null || $this->db->fetchValue(
			"SELECT 1 FROM `{$snapshots}` WHERE tracked_keyword_id = %d AND status = 'completed' AND id <> %d AND checked_at <= %s LIMIT 1",
			[$trackedId, (int) $current['id'], (string) $current['checked_at']],
		) !== null;
		$currentRank = $current['project_rank'] === null ? null : (int) $current['project_rank'];
		$previousRank = $previous === null || $previous['project_rank'] === null ? null : (int) $previous['project_rank'];
		$change = RankChange::compare($previous !== null, $hadAny, $previousRank, $currentRank);

		$this->db->update($this->table(), [
			'last_snapshot_id' => (int) $current['id'],
			'last_context_id' => (int) $current['context_id'],
			'last_checked_at' => (string) $current['checked_at'],
			'last_found' => $currentRank === null ? 0 : 1,
			'last_rank' => $currentRank,
			'last_rank_absolute' => $current['project_rank_absolute'] === null ? null : (int) $current['project_rank_absolute'],
			'last_url_id' => $current['project_url_id'] === null ? null : (int) $current['project_url_id'],
			'last_depth' => (int) $current['requested_depth'],
			'last_featured' => (int) $current['project_featured'],
			'prev_snapshot_id' => $previous === null ? null : (int) $previous['id'],
			'prev_found' => $previous === null ? null : ($previousRank === null ? 0 : 1),
			'prev_rank' => $previousRank,
			'change_type' => $change->type,
			'change_value' => $change->value,
			'top10_change' => $change->top10,
			'updated_at' => $this->now(),
		], ['id' => $trackedId]);
	}

	private function table(): string
	{
		return $this->db->table('serp_tracked_keywords');
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
