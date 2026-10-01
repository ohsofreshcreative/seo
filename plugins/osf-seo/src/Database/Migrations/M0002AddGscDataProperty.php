<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * `projects.gsc_data_property` — property GSC, z której pochodzą dane zapisane w tabelach faktów.
 *
 * `gsc_property` (wybrana property) jest czyszczona przy odłączeniu lub zmianie połączenia Google,
 * a dane zostają. Osobna kolumna pozwala wykryć, że nowa property różni się od źródła danych
 * (wtedy wymagany jest jawny reset) i nie mieszać danych dwóch properties.
 * NULL = brak danych albo pochodzenie nieznane (np. przerwany reset) — każdy wybór wymaga wtedy resetu.
 *
 * Migracja tylko dodaje kolumnę (bez zmian istniejących danych); idempotentna.
 */
final class M0002AddGscDataProperty implements Migration
{
	public function version(): int
	{
		return 2;
	}

	public function name(): string
	{
		return 'add_gsc_data_property';
	}

	public function up(Connection $db): void
	{
		if (MigrationHelpers::columnExists($db, 'projects', 'gsc_data_property')) {
			return;
		}

		$db->execute("ALTER TABLE `{$db->table('projects')}` ADD COLUMN `gsc_data_property` VARCHAR(255) NULL DEFAULT NULL AFTER `gsc_permission`");
	}
}
