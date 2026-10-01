<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Dane rynkowe fraz (STEP 12, DataForSEO) — dodatkowe źródło obok GSC, niezależne od faktów GSC:
 *
 * - `market_keywords` — metryki frazy na rynku: dostawca × lokalizacja × język × klucz znormalizowanej frazy.
 *   Nie zależą od projektu ani od słownika GSC, więc reset property (usunięcie `keywords`) ich nie kasuje,
 *   a projekty na tym samym rynku współdzielą dane (jeden płatny odczyt),
 * - `market_keyword_monthly` — historia miesięcznego wolumenu (miesiąc → wolumen; odświeżenie nadpisuje
 *   nakładające się miesiące zamiast dublować historię),
 * - `market_tasks` — zadania płatnego API (zlecenia Standard czekające na wynik, wywołania Live), koszt i błędy;
 *   z nich liczone są lokalne limity kosztów,
 * - `market_sync_state` — stan per projekt: jawne włączenie (pierwsza ręczna synchronizacja), ostatni sukces i błąd,
 * - `keywords.market_key` — klucz rynkowy frazy GSC (MD5 postaci znormalizowanej). Osobna kolumna:
 *   tożsamość frazy GSC (`keyword_hash`, dokładne bajty) pozostaje bez zmian. NULL = jeszcze nie wyliczony
 *   (wylicza go w tle `MarketKeyBackfill`, nie importer GSC).
 *
 * Tylko nowe tabele i nowa kolumna dopuszczająca NULL (ADD COLUMN na końcu tabeli — natychmiastowy w MariaDB 10.3+
 * i MySQL 8; indeks osobnym poleceniem, bez przebudowy tabeli). Istniejące dane pozostają nietknięte. Idempotentna.
 */
final class M0006CreateMarketData implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 6;
	}

	public function name(): string
	{
		return 'create_market_data';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		if (! MigrationHelpers::columnExists($db, 'keywords', 'market_key')) {
			$db->execute("ALTER TABLE `{$db->table('keywords')}` ADD COLUMN `market_key` BINARY(16) NULL DEFAULT NULL");
		}

		if (! MigrationHelpers::indexExists($db, 'keywords', 'project_market_key')) {
			$db->execute("ALTER TABLE `{$db->table('keywords')}` ADD KEY `project_market_key` (`project_id`, `market_key`)");
		}

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('market_keywords')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`provider` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`location_code` INT UNSIGNED NOT NULL,
			`language_code` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`keyword_key` BINARY(16) NOT NULL,
			`keyword` VARCHAR(255) NOT NULL,
			`search_volume` INT UNSIGNED NULL DEFAULT NULL,
			`cpc` DECIMAL(12,4) NULL DEFAULT NULL,
			`competition_level` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`competition_index` TINYINT UNSIGNED NULL DEFAULT NULL,
			`low_top_of_page_bid` DECIMAL(12,4) NULL DEFAULT NULL,
			`high_top_of_page_bid` DECIMAL(12,4) NULL DEFAULT NULL,
			`keyword_difficulty` TINYINT UNSIGNED NULL DEFAULT NULL,
			`volume_fetched_at` DATETIME NULL DEFAULT NULL,
			`volume_stale_after` DATETIME NULL DEFAULT NULL,
			`volume_pending_until` DATETIME NULL DEFAULT NULL,
			`volume_task_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`difficulty_fetched_at` DATETIME NULL DEFAULT NULL,
			`difficulty_stale_after` DATETIME NULL DEFAULT NULL,
			`difficulty_task_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `market_keyword` (`provider`, `location_code`, `language_code`, `keyword_key`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('market_keyword_monthly')}` (
			`market_keyword_id` INT UNSIGNED NOT NULL,
			`month` DATE NOT NULL,
			`search_volume` INT UNSIGNED NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`market_keyword_id`, `month`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('market_tasks')}` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`provider` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`endpoint` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`mode` ENUM('standard','live') NOT NULL,
			`trigger_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`project_id` INT UNSIGNED NULL DEFAULT NULL,
			`location_code` INT UNSIGNED NOT NULL,
			`language_code` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`provider_task_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`status` ENUM('pending','completed','failed','expired') NOT NULL,
			`keywords_count` INT UNSIGNED NOT NULL DEFAULT 0,
			`results_count` INT UNSIGNED NOT NULL DEFAULT 0,
			`keywords` LONGTEXT NULL,
			`estimated_cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`cost` DECIMAL(12,6) NULL DEFAULT NULL,
			`attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`error_message` VARCHAR(255) NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`next_check_at` DATETIME NULL DEFAULT NULL,
			`completed_at` DATETIME NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			KEY `status_check` (`status`, `next_check_at`),
			KEY `created` (`created_at`),
			KEY `project_task` (`project_id`, `id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('market_sync_state')}` (
			`project_id` INT UNSIGNED NOT NULL,
			`provider` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`enabled_at` DATETIME NULL DEFAULT NULL,
			`enabled_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`last_run_at` DATETIME NULL DEFAULT NULL,
			`last_success_at` DATETIME NULL DEFAULT NULL,
			`last_error` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`last_error_at` DATETIME NULL DEFAULT NULL,
			`next_auto_at` DATETIME NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`project_id`, `provider`)
		) {$options}");
	}
}
