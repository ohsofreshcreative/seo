<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Luki SEO (STEP 15, DataForSEO Labs Ranked Keywords) — frazy, na które rankują konkurenci, i ich porównanie z projektem:
 *
 * - `gap_domains` — zbiór fraz domeny na rynku (dostawca × lokalizacja × język × domena), WSPÓLNY dla projektów:
 *   zakres ostatniego importu, kompletność i świeżość; projekt widzi go wyłącznie przez swoich konkurentów lub domenę,
 * - `gap_domain_keywords` — bieżący stan: pozycja domeny (Labs) dla frazy rynkowej, URL (słownik `serp_urls`), daty,
 * - `gap_domain_pages` — strony domeny w zbiorze (URL ze słownika `serp_urls`, tytuł z wyniku dostawcy),
 * - `gap_domain_events` — historia wyłącznie zmian (nowa, utracona, powrót, zmiana URL, istotna zmiana pozycji),
 * - `gap_runs` / `gap_run_targets` — przebieg potwierdzony przez użytkownika i stronicowany import każdej domeny
 *   (także historia importów domeny: liczby, koszt, rozkład pozycji),
 * - `gap_settings` — progi, słowa tematyczne, marka projektu, zakres pobierania, harmonogram (domyślnie wyłączony),
 * - `gap_keywords` — luka frazy w projekcie (typ, widoczność projektu ze źródłem, konkurenci, priorytet, workflow),
 * - `gap_clusters` — grupy fraz i heurystyka luki treści (z workflow),
 * - `gap_competitor_pages` — agregaty stron konkurencji w projekcie.
 *
 * Zmiany istniejących tabel są wyłącznie addytywne: `market_keywords.core_key` (grupa synonimów dostawcy),
 * `market_keywords.other_language` (dostawca rozpoznał inny język frazy niż język rynku),
 * `serp_competitors.brand_terms` (warianty marki), wartość `gap` w `serp_tracked_keywords.source`. Idempotentna.
 */
final class M0009CreateKeywordGap implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	private const TRACKED_SOURCE = "enum('manual','gsc','discovery','gap')";

	public function version(): int
	{
		return 9;
	}

	public function name(): string
	{
		return 'create_keyword_gap';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		if (! MigrationHelpers::columnExists($db, 'market_keywords', 'core_key')) {
			$db->execute("ALTER TABLE `{$db->table('market_keywords')}` ADD COLUMN `core_key` BINARY(16) NULL DEFAULT NULL");
		}

		if (! MigrationHelpers::columnExists($db, 'market_keywords', 'other_language')) {
			$db->execute("ALTER TABLE `{$db->table('market_keywords')}` ADD COLUMN `other_language` TINYINT UNSIGNED NULL DEFAULT NULL");
		}

		if (! MigrationHelpers::columnExists($db, 'serp_competitors', 'brand_terms')) {
			$db->execute("ALTER TABLE `{$db->table('serp_competitors')}` ADD COLUMN `brand_terms` TEXT NULL");
		}

		// Dopisanie wartości na końcu enum nie zmienia zapisanych danych.
		if (strtolower((string) MigrationHelpers::columnType($db, 'serp_tracked_keywords', 'source')) !== self::TRACKED_SOURCE) {
			$db->execute("ALTER TABLE `{$db->table('serp_tracked_keywords')}` MODIFY COLUMN `source` ENUM('manual','gsc','discovery','gap') NOT NULL DEFAULT 'manual'");
		}

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_domains')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`provider` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`location_code` INT UNSIGNED NOT NULL,
			`language_code` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`domain` VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`domain_key` BINARY(16) NOT NULL,
			`status` ENUM('empty','importing','ready','partial') NOT NULL DEFAULT 'empty',
			`coverage_max_rank` TINYINT UNSIGNED NULL DEFAULT NULL,
			`coverage_min_volume` INT UNSIGNED NULL DEFAULT NULL,
			`coverage_max_rows` INT UNSIGNED NULL DEFAULT NULL,
			`complete` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`covered_min_volume` INT UNSIGNED NULL DEFAULT NULL,
			`total_count` INT UNSIGNED NULL DEFAULT NULL,
			`rows_present` INT UNSIGNED NOT NULL DEFAULT 0,
			`labs_updated_at` DATETIME NULL DEFAULT NULL,
			`import_run_id` INT UNSIGNED NULL DEFAULT NULL,
			`imported_at` DATETIME NULL DEFAULT NULL,
			`stale_after` DATETIME NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `market_domain` (`provider`, `location_code`, `language_code`, `domain_key`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_domain_keywords')}` (
			`domain_id` INT UNSIGNED NOT NULL,
			`market_keyword_id` INT UNSIGNED NOT NULL,
			`rank_group` TINYINT UNSIGNED NOT NULL,
			`rank_absolute` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`url_id` INT UNSIGNED NULL DEFAULT NULL,
			`etv` DECIMAL(12,2) NULL DEFAULT NULL,
			`serp_on` DATE NULL DEFAULT NULL,
			`first_seen` DATE NOT NULL,
			`last_seen` DATE NOT NULL,
			`seen_run_id` INT UNSIGNED NOT NULL,
			`prev_rank` TINYINT UNSIGNED NULL DEFAULT NULL,
			`changed_on` DATE NULL DEFAULT NULL,
			`present` TINYINT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY (`domain_id`, `market_keyword_id`),
			KEY `domain_url` (`domain_id`, `url_id`, `rank_group`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_domain_pages')}` (
			`domain_id` INT UNSIGNED NOT NULL,
			`url_id` INT UNSIGNED NOT NULL,
			`title` VARCHAR(512) NULL DEFAULT NULL,
			`last_seen` DATE NOT NULL,
			PRIMARY KEY (`domain_id`, `url_id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_domain_events')}` (
			`domain_id` INT UNSIGNED NOT NULL,
			`market_keyword_id` INT UNSIGNED NOT NULL,
			`run_id` INT UNSIGNED NOT NULL,
			`event` ENUM('new','lost','back','url','up','down') NOT NULL,
			`rank_old` TINYINT UNSIGNED NULL DEFAULT NULL,
			`rank_new` TINYINT UNSIGNED NULL DEFAULT NULL,
			`url_old` INT UNSIGNED NULL DEFAULT NULL,
			`url_new` INT UNSIGNED NULL DEFAULT NULL,
			`observed_on` DATE NOT NULL,
			PRIMARY KEY (`domain_id`, `market_keyword_id`, `run_id`, `event`),
			KEY `domain_run` (`domain_id`, `run_id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_runs')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`provider` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`location_code` INT UNSIGNED NOT NULL,
			`language_code` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`status` ENUM('queued','running','paused','completed','partial','failed','cancelled') NOT NULL DEFAULT 'queued',
			`trigger_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`max_rank` TINYINT UNSIGNED NOT NULL,
			`min_volume` INT UNSIGNED NOT NULL,
			`max_rows` INT UNSIGNED NOT NULL,
			`forced` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`targets_planned` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`targets_done` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`requests_planned` INT UNSIGNED NOT NULL DEFAULT 0,
			`requests_done` INT UNSIGNED NOT NULL DEFAULT 0,
			`estimated_cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`rows_received` INT UNSIGNED NOT NULL DEFAULT 0,
			`blocked_by` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`error_message` VARCHAR(255) NULL DEFAULT NULL,
			`active_project_id` INT UNSIGNED NULL DEFAULT NULL,
			`created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`started_at` DATETIME NULL DEFAULT NULL,
			`paused_at` DATETIME NULL DEFAULT NULL,
			`finished_at` DATETIME NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `active_project` (`active_project_id`),
			KEY `project_run` (`project_id`, `id`),
			KEY `status_run` (`status`, `id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_run_targets')}` (
			`run_id` INT UNSIGNED NOT NULL,
			`domain_id` INT UNSIGNED NOT NULL,
			`role` ENUM('competitor','project') NOT NULL,
			`competitor_id` INT UNSIGNED NULL DEFAULT NULL,
			`position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`status` ENUM('pending','cached','running','done','partial','failed','cancelled') NOT NULL DEFAULT 'pending',
			`next_offset` INT UNSIGNED NOT NULL DEFAULT 0,
			`pages_done` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`total_count` INT UNSIGNED NULL DEFAULT NULL,
			`rows_received` INT UNSIGNED NOT NULL DEFAULT 0,
			`rows_unique` INT UNSIGNED NOT NULL DEFAULT 0,
			`rows_new` INT UNSIGNED NOT NULL DEFAULT 0,
			`rows_lost` INT UNSIGNED NOT NULL DEFAULT 0,
			`rows_changed` INT UNSIGNED NOT NULL DEFAULT 0,
			`last_volume` INT UNSIGNED NULL DEFAULT NULL,
			`inflight_task_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`estimated_cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`next_attempt_at` DATETIME NULL DEFAULT NULL,
			`stats` LONGTEXT NULL,
			`started_at` DATETIME NULL DEFAULT NULL,
			`finished_at` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`run_id`, `domain_id`),
			KEY `domain_finished` (`domain_id`, `finished_at`)
		) {$options}");

		// `data_key` (BINARY) — także dlatego, że $wpdb traktuje tabelę mieszającą kolumny ascii i utf8mb4 bez kolumny
		// binarnej jako ASCII i odrzuca zapytania z polskimi znakami (słowa tematyczne, marka).
		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_settings')}` (
			`project_id` INT UNSIGNED NOT NULL,
			`competitor_max_rank` TINYINT UNSIGNED NOT NULL DEFAULT 20,
			`min_volume` INT UNSIGNED NOT NULL DEFAULT 10,
			`max_difficulty` TINYINT UNSIGNED NULL DEFAULT NULL,
			`fetch_max_rank` TINYINT UNSIGNED NOT NULL DEFAULT 30,
			`fetch_min_volume` INT UNSIGNED NOT NULL DEFAULT 10,
			`max_rows` INT UNSIGNED NOT NULL DEFAULT 10000,
			`include_terms` TEXT NULL,
			`brand_terms` TEXT NULL,
			`refresh_days` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
			`schedule_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`schedule_enabled_at` DATETIME NULL DEFAULT NULL,
			`schedule_enabled_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`next_refresh_at` DATETIME NULL DEFAULT NULL,
			`last_skip_reason` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`last_skip_at` DATETIME NULL DEFAULT NULL,
			`data_key` BINARY(16) NULL DEFAULT NULL,
			`recalculated_at` DATETIME NULL DEFAULT NULL,
			`updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`project_id`)
		) {$options}");

		// Wszystkie kolumny tekstowe w utf8mb4 (`public_id` w utf8mb4_bin, bez ascii) — lista jest filtrowana tekstem frazy,
		// a tabela mieszająca kolumny ascii i utf8mb4 bez kolumny binarnej jest dla $wpdb tabelą ASCII (patrz wyżej).
		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_keywords')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`market_keyword_id` INT UNSIGNED NOT NULL,
			`status` ENUM('new','review','accepted','dismissed') NOT NULL DEFAULT 'new',
			`note` TEXT NULL,
			`status_changed_at` DATETIME NULL DEFAULT NULL,
			`status_changed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
			`listed` TINYINT UNSIGNED NOT NULL DEFAULT 1,
			`filter_reason` VARCHAR(24) NULL DEFAULT NULL,
			`gap_type` ENUM('missing','weak','competitive','stronger','unknown') NOT NULL DEFAULT 'unknown',
			`visibility` ENUM('unknown','none','low','visible') NOT NULL DEFAULT 'unknown',
			`visibility_source` ENUM('serp','gsc','labs') NULL DEFAULT NULL,
			`sporadic` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`project_position` DECIMAL(6,2) NULL DEFAULT NULL,
			`project_labs_rank` TINYINT UNSIGNED NULL DEFAULT NULL,
			`serp_rank` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`serp_checked_at` DATETIME NULL DEFAULT NULL,
			`gsc_impressions` INT UNSIGNED NULL DEFAULT NULL,
			`gsc_clicks` INT UNSIGNED NULL DEFAULT NULL,
			`gsc_position` DECIMAL(6,2) NULL DEFAULT NULL,
			`competitors_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`competitors_top10` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`best_competitor_id` INT UNSIGNED NULL DEFAULT NULL,
			`best_competitor_rank` TINYINT UNSIGNED NULL DEFAULT NULL,
			`best_url_id` INT UNSIGNED NULL DEFAULT NULL,
			`search_volume` INT UNSIGNED NULL DEFAULT NULL,
			`keyword_difficulty` TINYINT UNSIGNED NULL DEFAULT NULL,
			`cpc` DECIMAL(12,4) NULL DEFAULT NULL,
			`intent` VARCHAR(16) NULL DEFAULT NULL,
			`content_gap` ENUM('improve','new_page','unclear','covered') NULL DEFAULT NULL,
			`cluster_id` INT UNSIGNED NULL DEFAULT NULL,
			`target_url_id` INT UNSIGNED NULL DEFAULT NULL,
			`target_source` ENUM('serp','gsc','labs','slug') NULL DEFAULT NULL,
			`priority` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`score` LONGTEXT NULL,
			`evidence_on` DATE NULL DEFAULT NULL,
			`first_seen_at` DATETIME NOT NULL,
			`scored_at` DATETIME NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `project_market_keyword` (`project_id`, `market_keyword_id`),
			KEY `project_list` (`project_id`, `listed`, `active`, `gap_type`, `status`, `priority`),
			KEY `project_volume` (`project_id`, `listed`, `search_volume`),
			KEY `project_cluster` (`project_id`, `cluster_id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_clusters')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`leader_market_keyword_id` INT UNSIGNED NOT NULL,
			`label` VARCHAR(255) NOT NULL,
			`label_key` BINARY(16) NOT NULL,
			`active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
			`keywords_count` INT UNSIGNED NOT NULL DEFAULT 0,
			`gap_keywords_count` INT UNSIGNED NOT NULL DEFAULT 0,
			`total_volume` INT UNSIGNED NOT NULL DEFAULT 0,
			`gap_volume` INT UNSIGNED NOT NULL DEFAULT 0,
			`competitors_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`best_competitor_id` INT UNSIGNED NULL DEFAULT NULL,
			`best_competitor_rank` TINYINT UNSIGNED NULL DEFAULT NULL,
			`competitor_pages` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`dedicated_pages` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`visibility` ENUM('unknown','none','low','visible') NOT NULL DEFAULT 'unknown',
			`content_gap` ENUM('improve','new_page','unclear','covered') NOT NULL DEFAULT 'unclear',
			`content_reason` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`confidence` ENUM('low','medium','high') NOT NULL DEFAULT 'low',
			`target_url_id` INT UNSIGNED NULL DEFAULT NULL,
			`target_source` ENUM('serp','gsc','labs','slug') NULL DEFAULT NULL,
			`priority` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`status` ENUM('new','review','accepted','dismissed') NOT NULL DEFAULT 'new',
			`note` TEXT NULL,
			`status_changed_at` DATETIME NULL DEFAULT NULL,
			`status_changed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`first_seen_at` DATETIME NOT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			KEY `project_list` (`project_id`, `active`, `content_gap`, `priority`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gap_competitor_pages')}` (
			`project_id` INT UNSIGNED NOT NULL,
			`competitor_id` INT UNSIGNED NOT NULL,
			`url_id` INT UNSIGNED NOT NULL,
			`url_key` BINARY(16) NOT NULL,
			`title` VARCHAR(512) NULL DEFAULT NULL,
			`page_type` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`keywords` INT UNSIGNED NOT NULL DEFAULT 0,
			`top3` INT UNSIGNED NOT NULL DEFAULT 0,
			`top10` INT UNSIGNED NOT NULL DEFAULT 0,
			`top20` INT UNSIGNED NOT NULL DEFAULT 0,
			`total_volume` INT UNSIGNED NOT NULL DEFAULT 0,
			`etv` DECIMAL(14,2) NOT NULL DEFAULT 0,
			`gap_keywords` INT UNSIGNED NOT NULL DEFAULT 0,
			`gap_volume` INT UNSIGNED NOT NULL DEFAULT 0,
			`overlap_keywords` INT UNSIGNED NOT NULL DEFAULT 0,
			`main_intent` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`best_rank` TINYINT UNSIGNED NULL DEFAULT NULL,
			`best_market_keyword_id` INT UNSIGNED NULL DEFAULT NULL,
			`cluster_id` INT UNSIGNED NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`project_id`, `competitor_id`, `url_id`),
			KEY `project_gap` (`project_id`, `gap_keywords`),
			KEY `project_url` (`project_id`, `url_key`)
		) {$options}");
	}
}
