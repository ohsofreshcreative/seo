<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Support\Clock;

/**
 * `strategy_settings` — stan Strategii projektu. Każda mutacja ręczna Strategii podbija `revision` (część klucza danych),
 * więc przeliczenie nie zależy wyłącznie od czasu importu.
 */
final class StrategySettingsRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function get(int $projectId): StrategySettings
	{
		$row = $this->db->fetchRow("SELECT *, LOWER(HEX(data_key)) AS data_key_hex FROM `{$this->table()}` WHERE project_id = %d", [$projectId]);

		return $row === null ? new StrategySettings($projectId) : StrategySettings::fromRow($row);
	}

	/** Mutacja ręczna (wpisy ręczne, a w kolejnych fazach praca nad tematami) — unieważnia klucz danych. */
	public function bumpRevision(int $projectId, ?int $userId): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, revision, updated_by, updated_at) VALUES (%d, 1, NULLIF(%d, 0), %s)
			ON DUPLICATE KEY UPDATE revision = revision + 1, updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
			[$projectId, max(0, (int) $userId), $now],
		);
	}

	/**
	 * @param array<string, mixed> $stats
	 */
	public function recordRefresh(int $projectId, string $dataKeyHex, int $durationMs, array $stats, Market $market): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, data_key, refreshed_at, refresh_ms, stats, location_code, language_code, updated_at)
			VALUES (%d, UNHEX(%s), %s, %d, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE data_key = VALUES(data_key), refreshed_at = VALUES(refreshed_at), refresh_ms = VALUES(refresh_ms),
				stats = VALUES(stats), location_code = VALUES(location_code), language_code = VALUES(language_code), updated_at = VALUES(updated_at)",
			[
				$projectId,
				$dataKeyHex,
				$now,
				max(0, $durationMs),
				(string) json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
				$market->locationCode,
				$market->languageCode,
				$now,
			],
		);
	}

	/** Wymuszenie przeliczenia przy kolejnym uruchomieniu (bez zmiany rewizji). */
	public function invalidate(int $projectId): void
	{
		$this->db->execute("UPDATE `{$this->table()}` SET data_key = NULL WHERE project_id = %d", [$projectId]);
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	private function table(): string
	{
		return $this->db->table('strategy_settings');
	}
}
