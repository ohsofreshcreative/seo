<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * SERP Intelligence Strategii (STEP 16, faza B — docs/ARCHITECTURE.md, sekcja 15.12):
 *
 * - `serp_tracked_keywords.status` — wartość `analysis`: jednorazowa analiza Strategii (poza harmonogramem, listą Pozycji i miękkim
 *   limitem); dodanie frazy do monitorowania zmienia ją na `active` bez utraty historii pomiarów,
 * - `serp_tracked_keywords.source` — wartość `strategy` (fraza dodana przez analizę Strategii),
 * - `serp_runs.trigger_type` i `serp_snapshots.trigger_type` — wartość `analysis` (przebieg analizy Strategii przez `SerpSubmitter`),
 * - `serp_snapshot_profiles` — pochodny, niezmienny (w obrębie wersji reguł) profil zakończonego pomiaru: kompozycja TOP10/TOP20,
 *   kształty wyników z pewnością, sygnał intencji z SERP. Bez kopii wyników — pełne TOP N zostaje w `serp_results`.
 *
 * Zmiany istniejących tabel wyłącznie addytywne (wartości dopisane na końcu enum nie zmieniają zapisanych danych). Idempotentna.
 */
final class M0011SerpIntelligence implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	/** @var array<string, array{0: string, 1: string, 2: string}> tabela.kolumna → [oczekiwany typ, definicja kolumny, kolumna] */
	private const ENUMS = [
		'serp_tracked_keywords.status' => ["enum('active','removed','analysis')", "ENUM('active','removed','analysis') NOT NULL DEFAULT 'active'", 'status'],
		'serp_tracked_keywords.source' => ["enum('manual','gsc','discovery','gap','strategy')", "ENUM('manual','gsc','discovery','gap','strategy') NOT NULL DEFAULT 'manual'", 'source'],
		'serp_runs.trigger_type' => ["enum('schedule','manual','analysis')", "ENUM('schedule','manual','analysis') NOT NULL", 'trigger_type'],
		'serp_snapshots.trigger_type' => ["enum('schedule','manual','analysis')", "ENUM('schedule','manual','analysis') NOT NULL", 'trigger_type'],
	];

	public function version(): int
	{
		return 11;
	}

	public function name(): string
	{
		return 'serp_intelligence';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		foreach (self::ENUMS as $target => [$type, $definition, $column]) {
			$table = explode('.', $target)[0];

			if (strtolower((string) MigrationHelpers::columnType($db, $table, $column)) !== $type) {
				$db->execute("ALTER TABLE `{$db->table($table)}` MODIFY COLUMN `{$column}` {$definition}");
			}
		}

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_snapshot_profiles')}` (
			`snapshot_id` INT UNSIGNED NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`version` TINYINT UNSIGNED NOT NULL,
			`organic_top10` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`organic_top20` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`domains_top10` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`domains_top20` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`top_domain_top10` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`home_top10` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`shape` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`shape_share` DECIMAL(4,3) NULL DEFAULT NULL,
			`shape_confidence` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`intent` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`intent_confidence` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`composition` TEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY (`snapshot_id`),
			KEY `project_snapshot` (`project_id`, `snapshot_id`)
		) {$options}");
	}
}
