<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Przeliczenie Strategii w tle (STEP 16, faza E — docs/ARCHITECTURE.md, sekcja 15.15): stan zadania przeliczenia projektu w wierszu
 * `strategy_settings` (jeden wiersz = jedno zadanie na projekt, bez osobnej tabeli kolejki) — status, źródło zlecenia, termin, przejęcie
 * i dzierżawa, liczba prób, kod ostatniego błędu i klucz danych, na którym próby się wyczerpały, oraz stan wykrywania zmian danych
 * (ostatnio widziany klucz, od kiedy stabilny, od kiedy dane są nieaktualne — debounce importów).
 *
 * Wyłącznie addytywnie (nowe kolumny z NULL / 0 / `idle`) i indeks terminu zadań. Idempotentna.
 */
final class M0014StrategyRefreshQueue implements Migration
{
	/** @var array<string, string> kolumna → definicja (kolejność = kolejność dopisania) */
	private const COLUMNS = [
		'refresh_status' => "VARCHAR(10) NOT NULL DEFAULT 'idle'",
		'refresh_source' => 'VARCHAR(10) NULL DEFAULT NULL',
		'refresh_due_at' => 'DATETIME NULL DEFAULT NULL',
		'refresh_started_at' => 'DATETIME NULL DEFAULT NULL',
		'refresh_lease_until' => 'DATETIME NULL DEFAULT NULL',
		'refresh_finished_at' => 'DATETIME NULL DEFAULT NULL',
		'refresh_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
		'refresh_error' => 'VARCHAR(64) NULL DEFAULT NULL',
		'refresh_error_at' => 'DATETIME NULL DEFAULT NULL',
		'refresh_failed_key' => 'BINARY(16) NULL DEFAULT NULL',
		'refresh_seen_key' => 'BINARY(16) NULL DEFAULT NULL',
		'refresh_seen_at' => 'DATETIME NULL DEFAULT NULL',
		'refresh_dirty_since' => 'DATETIME NULL DEFAULT NULL',
	];

	public function version(): int
	{
		return 14;
	}

	public function name(): string
	{
		return 'strategy_refresh_queue';
	}

	public function up(Connection $db): void
	{
		$table = $db->table('strategy_settings');

		foreach (self::COLUMNS as $column => $definition) {
			if (! MigrationHelpers::columnExists($db, 'strategy_settings', $column)) {
				$db->execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
			}
		}

		if (! MigrationHelpers::indexExists($db, 'strategy_settings', 'refresh_queue')) {
			$db->execute("ALTER TABLE `{$table}` ADD INDEX `refresh_queue` (`refresh_status`, `refresh_due_at`)");
		}
	}
}
