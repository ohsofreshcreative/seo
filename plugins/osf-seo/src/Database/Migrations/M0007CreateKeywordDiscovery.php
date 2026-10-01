<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Wykrywanie nowych fraz (STEP 13, DataForSEO Labs) — badania rynku projektu, niezależne od faktów GSC:
 *
 * - `discovery_runs` — przebieg wyszukiwania (ULID): rynek, metoda, limity i filtry, stan, zadania, koszt, błędy,
 * - `discovery_run_seeds` — seedy przebiegu ze stanem (wznawianie w tle, cache seedów, koszt per seed),
 * - `discovery_candidates` — kandydaci projektu (ULID): odwołanie do wspólnej frazy rynkowej `market_keywords`
 *   (metryk nie kopiujemy), stan pracy (status, notatka, kto i kiedy), migawka widoczności GSC i priorytet,
 * - `discovery_candidate_sources` — z których seedów i jaką metodą fraza została znaleziona (bez duplikatów kandydatów),
 * - `discovery_settings` — wykluczone słowa projektu i stan przeliczenia widoczności,
 * - `market_keywords.search_intent` / `intent_fetched_at` — intencja wyszukiwania od dostawcy (cecha frazy na rynku).
 *
 * Tabele nie należą do danych GSC (`GscDataStore::DATA_TABLES`): reset property ich nie usuwa.
 * Tylko nowe tabele i nowe kolumny dopuszczające NULL (ADD COLUMN na końcu tabeli). Idempotentna.
 */
final class M0007CreateKeywordDiscovery implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 7;
	}

	public function name(): string
	{
		return 'create_keyword_discovery';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		if (! MigrationHelpers::columnExists($db, 'market_keywords', 'search_intent')) {
			$db->execute("ALTER TABLE `{$db->table('market_keywords')}` ADD COLUMN `search_intent` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL");
		}

		if (! MigrationHelpers::columnExists($db, 'market_keywords', 'intent_fetched_at')) {
			$db->execute("ALTER TABLE `{$db->table('market_keywords')}` ADD COLUMN `intent_fetched_at` DATETIME NULL DEFAULT NULL");
		}

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('discovery_runs')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`provider` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`location_code` INT UNSIGNED NOT NULL,
			`language_code` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`method` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`depth` TINYINT UNSIGNED NULL DEFAULT NULL,
			`status` ENUM('queued','running','completed','partial','failed','cancelled') NOT NULL DEFAULT 'queued',
			`trigger_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`seeds_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`max_candidates` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`seed_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`min_volume` INT UNSIGNED NOT NULL DEFAULT 0,
			`max_difficulty` TINYINT UNSIGNED NULL DEFAULT NULL,
			`forced` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`options` LONGTEXT NULL,
			`tasks_planned` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`tasks_done` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`estimated_cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`items_received` INT UNSIGNED NOT NULL DEFAULT 0,
			`candidates_new` INT UNSIGNED NOT NULL DEFAULT 0,
			`candidates_seen` INT UNSIGNED NOT NULL DEFAULT 0,
			`rejected` LONGTEXT NULL,
			`blocked_by` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`error_message` VARCHAR(255) NULL DEFAULT NULL,
			`created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`started_at` DATETIME NULL DEFAULT NULL,
			`finished_at` DATETIME NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			KEY `project_run` (`project_id`, `id`),
			KEY `status_run` (`status`, `id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('discovery_run_seeds')}` (
			`run_id` INT UNSIGNED NOT NULL,
			`seed_key` BINARY(16) NOT NULL,
			`seed` VARCHAR(255) NOT NULL,
			`source` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`status` ENUM('pending','running','done','cached','failed','cancelled') NOT NULL DEFAULT 'pending',
			`pages_done` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`next_offset` INT UNSIGNED NOT NULL DEFAULT 0,
			`items` INT UNSIGNED NOT NULL DEFAULT 0,
			`candidates_new` INT UNSIGNED NOT NULL DEFAULT 0,
			`cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`task_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`next_attempt_at` DATETIME NULL DEFAULT NULL,
			`started_at` DATETIME NULL DEFAULT NULL,
			`finished_at` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`run_id`, `seed_key`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('discovery_candidates')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`market_keyword_id` INT UNSIGNED NOT NULL,
			`status` ENUM('new','review','accepted','dismissed') NOT NULL DEFAULT 'new',
			`note` TEXT NULL,
			`status_changed_at` DATETIME NULL DEFAULT NULL,
			`status_changed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`seeds_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`best_relation` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`visibility` ENUM('unknown','none','low','visible') NOT NULL DEFAULT 'unknown',
			`gsc_impressions` INT UNSIGNED NULL DEFAULT NULL,
			`gsc_clicks` INT UNSIGNED NULL DEFAULT NULL,
			`gsc_position` DECIMAL(6,2) NULL DEFAULT NULL,
			`target_url` VARCHAR(2048) NULL DEFAULT NULL,
			`priority` TINYINT UNSIGNED NULL DEFAULT NULL,
			`score` LONGTEXT NULL,
			`excluded` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`provider_meta` LONGTEXT NULL,
			`first_run_id` INT UNSIGNED NOT NULL,
			`last_run_id` INT UNSIGNED NOT NULL,
			`discovered_at` DATETIME NOT NULL,
			`last_seen_at` DATETIME NOT NULL,
			`scored_at` DATETIME NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `project_market_keyword` (`project_id`, `market_keyword_id`),
			KEY `project_priority` (`project_id`, `priority`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('discovery_candidate_sources')}` (
			`candidate_id` INT UNSIGNED NOT NULL,
			`seed_key` BINARY(16) NOT NULL,
			`method` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`seed` VARCHAR(255) NOT NULL,
			`run_id` INT UNSIGNED NOT NULL,
			`relation` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`depth` TINYINT UNSIGNED NULL DEFAULT NULL,
			`result_position` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`first_seen_at` DATETIME NOT NULL,
			`last_seen_at` DATETIME NOT NULL,
			PRIMARY KEY (`candidate_id`, `seed_key`, `method`),
			KEY `run` (`run_id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('discovery_settings')}` (
			`project_id` INT UNSIGNED NOT NULL,
			`excluded_terms` TEXT NULL,
			`refresh_key` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`refreshed_at` DATETIME NULL DEFAULT NULL,
			`updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`project_id`)
		) {$options}");
	}
}
