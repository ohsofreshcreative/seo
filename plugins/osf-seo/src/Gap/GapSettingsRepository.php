<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Ustawienia Luk SEO projektu (`gap_settings`). Brak wiersza = wartości domyślne (harmonogram wyłączony).
 * Tekst użytkownika (słowa tematyczne, marka) zapisujemy wyłącznie przez insert()/update().
 */
final class GapSettingsRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	public function get(int $projectId): GapSettings
	{
		$row = $this->db->fetchRow("SELECT *, LOWER(HEX(data_key)) AS data_key_hex FROM `{$this->table()}` WHERE project_id = %d", [$projectId]);

		return $row === null ? new GapSettings($projectId) : GapSettings::fromRow($row);
	}

	/**
	 * @param array<string, int|string|null> $values kolumny ustawień
	 */
	public function save(int $projectId, array $values, ?int $userId): GapSettings
	{
		$values = array_intersect_key($values, array_flip([
			'competitor_max_rank', 'min_volume', 'max_difficulty', 'fetch_max_rank', 'fetch_min_volume', 'max_rows',
			'include_terms', 'brand_terms', 'refresh_days', 'schedule_enabled', 'schedule_enabled_at', 'schedule_enabled_by',
			'next_refresh_at', 'last_skip_reason', 'last_skip_at',
		]));
		$values['updated_by'] = $userId !== null && $userId > 0 ? $userId : null;
		$values['updated_at'] = $this->now();
		$this->ensure($projectId);
		$this->db->update($this->table(), $values, ['project_id' => $projectId]);

		return $this->get($projectId);
	}

	public function recordRecalculation(int $projectId, string $dataKeyHex): void
	{
		$this->ensure($projectId);
		$this->db->execute(
			"UPDATE `{$this->table()}` SET data_key = UNHEX(%s), recalculated_at = %s WHERE project_id = %d",
			[$dataKeyHex, $this->now(), $projectId],
		);
	}

	/** Wymuszenie przeliczenia w najbliższym kroku tła (klucz danych przestaje pasować). */
	public function invalidate(int $projectId): void
	{
		$this->ensure($projectId);
		$this->db->execute("UPDATE `{$this->table()}` SET data_key = NULL WHERE project_id = %d", [$projectId]);
	}

	public function recordSkip(int $projectId, string $reason, ?string $nextRefreshAt): void
	{
		$this->ensure($projectId);
		$this->db->update($this->table(), ['last_skip_reason' => $reason, 'last_skip_at' => $this->now(), 'next_refresh_at' => $nextRefreshAt], ['project_id' => $projectId]);
	}

	public function scheduleNext(int $projectId, ?string $nextRefreshAt): void
	{
		$this->ensure($projectId);
		$this->db->update($this->table(), ['next_refresh_at' => $nextRefreshAt, 'last_skip_reason' => null, 'last_skip_at' => null], ['project_id' => $projectId]);
	}

	/**
	 * Projekty z włączonym harmonogramem, których termin odświeżenia minął.
	 *
	 * @return list<int>
	 */
	public function dueProjects(int $limit = 5): array
	{
		return array_map('intval', array_column($this->db->fetchAll(
			"SELECT project_id FROM `{$this->table()}` WHERE schedule_enabled = 1 AND (next_refresh_at IS NULL OR next_refresh_at <= %s) ORDER BY next_refresh_at LIMIT %d",
			[$this->now(), max(1, $limit)],
		), 'project_id'));
	}

	/**
	 * @return list<int>
	 */
	public function projectIds(): array
	{
		return array_map('intval', array_column($this->db->fetchAll("SELECT project_id FROM `{$this->table()}`"), 'project_id'));
	}

	private function ensure(int $projectId): void
	{
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, updated_at) VALUES (%d, %s) ON DUPLICATE KEY UPDATE project_id = project_id",
			[$projectId, $this->now()],
		);
	}

	private function table(): string
	{
		return $this->db->table('gap_settings');
	}
}
