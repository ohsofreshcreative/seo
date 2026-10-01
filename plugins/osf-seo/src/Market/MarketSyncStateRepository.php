<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Stan danych rynkowych projektu (`market_sync_state`): jawne włączenie (pierwsza ręczna synchronizacja — dopiero wtedy
 * działa automatyczne odświeżanie), ostatni przebieg, sukces, błąd i termin kolejnego automatycznego sprawdzenia.
 * Nie należy do danych GSC — reset property go nie usuwa.
 */
final class MarketSyncStateRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{enabled_at: ?string, enabled_by: ?int, last_run_at: ?string, last_success_at: ?string, last_error: ?string, last_error_at: ?string, next_auto_at: ?string}
	 */
	public function get(int $projectId, string $provider): array
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE project_id = %d AND provider = %s", [$projectId, $provider]) ?? [];

		return [
			'enabled_at' => $row['enabled_at'] ?? null,
			'enabled_by' => isset($row['enabled_by']) ? (int) $row['enabled_by'] : null,
			'last_run_at' => $row['last_run_at'] ?? null,
			'last_success_at' => $row['last_success_at'] ?? null,
			'last_error' => $row['last_error'] ?? null,
			'last_error_at' => $row['last_error_at'] ?? null,
			'next_auto_at' => $row['next_auto_at'] ?? null,
		];
	}

	/** Pierwsza jawna synchronizacja włącza automatyczne odświeżanie projektu (raz; kolejne nie zmieniają daty). */
	public function enable(int $projectId, string $provider, int $userId): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, provider, enabled_at, enabled_by, updated_at) VALUES (%d, %s, %s, NULLIF(%d, 0), %s)
			ON DUPLICATE KEY UPDATE enabled_at = COALESCE(enabled_at, VALUES(enabled_at)), enabled_by = COALESCE(enabled_by, VALUES(enabled_by)), updated_at = VALUES(updated_at)",
			[$projectId, $provider, $now, max(0, $userId), $now],
		);
	}

	/** Wynik przebiegu: sukces (czyści błąd) albo kategoria błędu; termin kolejnego automatycznego sprawdzenia. */
	public function recordRun(int $projectId, string $provider, ?string $error, string $nextAutoAt): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, provider, last_run_at, last_success_at, last_error, last_error_at, next_auto_at, updated_at)
			VALUES (%d, %s, %s, NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), %s, %s)
			ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at),
				last_success_at = COALESCE(VALUES(last_success_at), last_success_at),
				last_error = VALUES(last_error), last_error_at = COALESCE(VALUES(last_error_at), last_error_at),
				next_auto_at = VALUES(next_auto_at), updated_at = VALUES(updated_at)",
			[$projectId, $provider, $now, $error === null ? $now : '', $error ?? '', $error === null ? '' : $now, $nextAutoAt, $now],
		);
	}

	/** Sukces bez nowego przebiegu (np. odebrany wynik zadania wolumenu). */
	public function recordSuccess(int $projectId, string $provider): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, provider, last_success_at, updated_at) VALUES (%d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE last_success_at = VALUES(last_success_at), last_error = NULL, updated_at = VALUES(updated_at)",
			[$projectId, $provider, $now, $now],
		);
	}

	public function recordError(int $projectId, string $provider, string $error): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, provider, last_error, last_error_at, updated_at) VALUES (%d, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE last_error = VALUES(last_error), last_error_at = VALUES(last_error_at), updated_at = VALUES(updated_at)",
			[$projectId, $provider, $error, $now, $now],
		);
	}

	/**
	 * Projekty z włączonym automatycznym odświeżaniem, których termin minął (najdawniej sprawdzane najpierw).
	 *
	 * @return list<int>
	 */
	public function dueForAuto(string $provider, int $limit): array
	{
		return array_map('intval', array_column($this->db->fetchAll(
			"SELECT s.project_id FROM `{$this->table()}` s
			JOIN `{$this->db->table('projects')}` p ON p.id = s.project_id AND p.status = 'active'
			WHERE s.provider = %s AND s.enabled_at IS NOT NULL AND (s.next_auto_at IS NULL OR s.next_auto_at <= %s)
			ORDER BY s.next_auto_at IS NOT NULL, s.next_auto_at, s.project_id LIMIT %d",
			[$provider, $this->now(), max(1, $limit)],
		), 'project_id'));
	}

	/** Ostatni sukces i ostatni błąd w całej instalacji (status bez projektu). */
	public function latest(string $provider): array
	{
		$row = $this->db->fetchRow(
			"SELECT MAX(last_success_at) AS last_success_at, MAX(last_error_at) AS last_error_at, SUM(enabled_at IS NOT NULL) AS enabled_projects
			FROM `{$this->table()}` WHERE provider = %s",
			[$provider],
		) ?? [];
		$error = $row['last_error_at'] ?? null;

		return [
			'last_success_at' => $row['last_success_at'] ?? null,
			'last_error_at' => $error,
			'last_error' => $error === null ? null : $this->db->fetchValue(
				"SELECT last_error FROM `{$this->table()}` WHERE provider = %s AND last_error_at = %s ORDER BY project_id LIMIT 1",
				[$provider, $error],
			),
			'enabled_projects' => (int) ($row['enabled_projects'] ?? 0),
		];
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	private function table(): string
	{
		return $this->db->table('market_sync_state');
	}
}
