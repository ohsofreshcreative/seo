<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Ustawienia wyszukiwania projektu (`discovery_settings`): wykluczone słowa i stan przeliczenia widoczności/priorytetu.
 * Nie należą do danych GSC — reset property ich nie usuwa.
 */
final class DiscoverySettingsRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function exclusions(int $projectId): ExclusionList
	{
		return ExclusionList::parse($this->db->fetchValue("SELECT excluded_terms FROM `{$this->table()}` WHERE project_id = %d", [$projectId]));
	}

	public function saveExclusions(int $projectId, ExclusionList $list, int $userId): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, excluded_terms, updated_by, updated_at) VALUES (%d, NULLIF(%s, ''), NULLIF(%d, 0), %s)
			ON DUPLICATE KEY UPDATE excluded_terms = VALUES(excluded_terms), updated_by = VALUES(updated_by), refresh_key = NULL, updated_at = VALUES(updated_at)",
			[$projectId, $list->toText(), max(0, $userId), $now],
		);
	}

	public function refreshKey(int $projectId): ?string
	{
		return $this->db->fetchValue("SELECT refresh_key FROM `{$this->table()}` WHERE project_id = %d", [$projectId]);
	}

	/**
	 * @return array{refresh_key: ?string, refreshed_at: ?string}
	 */
	public function refreshState(int $projectId): array
	{
		$row = $this->db->fetchRow("SELECT refresh_key, refreshed_at FROM `{$this->table()}` WHERE project_id = %d", [$projectId]) ?? [];

		return ['refresh_key' => $row['refresh_key'] ?? null, 'refreshed_at' => $row['refreshed_at'] ?? null];
	}

	public function saveRefresh(int $projectId, string $key): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, refresh_key, refreshed_at, updated_at) VALUES (%d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE refresh_key = VALUES(refresh_key), refreshed_at = VALUES(refreshed_at)",
			[$projectId, $key, $now, $now],
		);
	}

	/** Wymuszenie przeliczenia przy kolejnym kroku w tle (np. reset property). */
	public function invalidate(int $projectId): void
	{
		$this->db->execute("UPDATE `{$this->table()}` SET refresh_key = NULL WHERE project_id = %d", [$projectId]);
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	private function table(): string
	{
		return $this->db->table('discovery_settings');
	}
}
