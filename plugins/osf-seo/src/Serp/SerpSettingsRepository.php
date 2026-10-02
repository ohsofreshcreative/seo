<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use DateTimeImmutable;
use DateTimeZone;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Ustawienia śledzenia projektów (`serp_settings`) i harmonogram automatycznych pomiarów.
 */
final class SerpSettingsRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function get(int $projectId): SerpSettings
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE project_id = %d", [$projectId]);

		return $row === null ? new SerpSettings($projectId) : SerpSettings::fromRow($row);
	}

	/**
	 * Zapis ustawień. Włączenie ustawia pierwszy pomiar na „teraz” (wykona go tło); zmiana częstotliwości przy włączonym
	 * śledzeniu przesuwa termin względem ostatniego pomiaru.
	 */
	public function save(int $projectId, bool $enabled, SerpFrequency $frequency, SerpDevice $device, int $depth, ?int $userId): SerpSettings
	{
		$current = $this->get($projectId);
		$now = $this->now();
		$nextRun = match (true) {
			! $enabled => null,
			! $current->enabled => $now,
			$current->lastRunAt !== null => self::date($current->lastRunAt)->modify('+' . $frequency->days() . ' days')->format('Y-m-d H:i:s'),
			default => $current->nextRunAt ?? $now,
		};
		$keep = $enabled && $current->enabled;
		$row = [
			'project_id' => $projectId,
			'enabled' => $enabled ? 1 : 0,
			'frequency' => $frequency->value,
			'device' => $device->value,
			'depth' => $depth,
			'enabled_at' => $enabled ? ($keep ? $current->enabledAt : $now) : null,
			'enabled_by' => $enabled ? ($keep ? $current->enabledBy : $userId) : null,
			'next_run_at' => $nextRun,
			'retry_after' => null,
			'updated_by' => $userId,
			'updated_at' => $now,
		];

		if ($current->stored) {
			unset($row['project_id']);
			$this->db->update($this->table(), $row, ['project_id' => $projectId]);
		} else {
			$this->db->insert($this->table(), $row);
		}

		return $this->get($projectId);
	}

	/**
	 * Projekty z terminem pomiaru (włączone, termin minął, bez blokady po pominięciu).
	 *
	 * @return list<int>
	 */
	public function due(int $limit): array
	{
		$now = $this->now();

		return array_map(static fn (array $row): int => (int) $row['project_id'], $this->db->fetchAll(
			"SELECT project_id FROM `{$this->table()}` WHERE enabled = 1 AND next_run_at <= %s AND (retry_after IS NULL OR retry_after <= %s)
			ORDER BY next_run_at, project_id LIMIT %d",
			[$now, $now, max(1, $limit)],
		));
	}

	/** Pomiar z harmonogramu utworzony: kolejny termin = ten termin + częstotliwość (nie wcześniej niż za interwał od teraz). */
	public function scheduled(int $projectId, string $slot, SerpFrequency $frequency): void
	{
		$now = $this->clock->now();
		$next = self::date($slot)->modify('+' . $frequency->days() . ' days');

		if ($next <= $now) {
			$next = $now->modify('+' . $frequency->days() . ' days');
		}

		$this->db->execute(
			"UPDATE `{$this->table()}` SET next_run_at = %s, last_run_at = %s, retry_after = NULL, last_skip_reason = NULL, updated_at = %s WHERE project_id = %d",
			[$next->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $projectId],
		);
	}

	/** Pomiar pominięty (np. budżet): termin zostaje, kolejna próba po `retry_after`. */
	public function skipped(int $projectId, string $reason, string $retryAfter): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET retry_after = %s, last_skip_reason = %s, last_skip_at = %s, updated_at = %s WHERE project_id = %d",
			[$retryAfter, $reason, $this->now(), $this->now(), $projectId],
		);
	}

	/** Pomiar bez fraz do sprawdzenia — termin przesuwa się jak po zwykłym pomiarze. */
	public function nothingToDo(int $projectId, string $slot, SerpFrequency $frequency): void
	{
		$this->scheduled($projectId, $slot, $frequency);
		$this->db->execute("UPDATE `{$this->table()}` SET last_skip_reason = 'nothing_to_do', last_skip_at = %s WHERE project_id = %d", [$this->now(), $projectId]);
	}

	private static function date(string $value): DateTimeImmutable
	{
		return new DateTimeImmutable($value, new DateTimeZone('UTC'));
	}

	private function table(): string
	{
		return $this->db->table('serp_settings');
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
