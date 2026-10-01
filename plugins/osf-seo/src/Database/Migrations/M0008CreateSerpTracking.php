<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Pozycje SERP i konkurenci (STEP 14, DataForSEO Google Organic SERP) — historyczny zbiór pełnych wyników organicznych,
 * niezależny od faktów GSC:
 *
 * - `serp_contexts` — kontekst pomiaru (wyszukiwarka, typ SERP, lokalizacja, język, urządzenie, system, głębokość),
 * - `serp_settings` — śledzenie projektu (domyślnie wyłączone), częstotliwość, urządzenie, głębokość, harmonogram,
 * - `serp_competitors` — konkurenci dodani ręcznie (ULID); pozycje wyliczane z zapisanych SERP-ów,
 * - `serp_tracked_keywords` — monitorowane frazy (ULID, wspólna fraza rynkowa) i ich bieżący stan (ostatnia i poprzednia
 *   porównywalna pozycja, zmiana) — szybka lista bez skanowania historii,
 * - `serp_runs` — przebiegi pomiaru (harmonogram / ręczny), `slot_key` chroni przed podwójnym przebiegiem tego samego terminu,
 * - `serp_snapshots` — pomiar = jedno zadanie dostawcy (ULID): kontekst, koszt, czas pobrania SERP, wynik projektu,
 * - `serp_results` — każdy zapisany wynik pomiaru (organic, featured snippet) jako liczby: pozycje, domena, URL, treść,
 * - `serp_domains`, `serp_urls`, `serp_snippets` — słowniki (host, adres, prezentacja wyniku), bez powtarzania tekstu.
 *
 * Historia jest zachowywana bez automatycznego usuwania; klucze rosnące (`snapshot_id`) pozwalają w przyszłości na
 * archiwizację lub partycjonowanie zakresami. Tylko nowe tabele — istniejące dane pozostają nietknięte. Idempotentna.
 */
final class M0008CreateSerpTracking implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 8;
	}

	public function name(): string
	{
		return 'create_serp_tracking';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_contexts')}` (
			`id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`context_key` BINARY(16) NOT NULL,
			`engine` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`serp_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`location_code` INT UNSIGNED NOT NULL,
			`language_code` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`device` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`os` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`depth` SMALLINT UNSIGNED NOT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `context_key` (`context_key`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_settings')}` (
			`project_id` INT UNSIGNED NOT NULL,
			`enabled` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`frequency` ENUM('daily','every_3_days','weekly') NOT NULL DEFAULT 'weekly',
			`device` ENUM('desktop','mobile') NOT NULL DEFAULT 'desktop',
			`depth` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
			`enabled_at` DATETIME NULL DEFAULT NULL,
			`enabled_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`next_run_at` DATETIME NULL DEFAULT NULL,
			`retry_after` DATETIME NULL DEFAULT NULL,
			`last_run_at` DATETIME NULL DEFAULT NULL,
			`last_skip_reason` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`last_skip_at` DATETIME NULL DEFAULT NULL,
			`updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`project_id`),
			KEY `due` (`enabled`, `next_run_at`)
		) {$options}");

		// `domain_key` (BINARY) — także dlatego, że $wpdb traktuje tabelę mieszającą kolumny ascii i utf8mb4 bez kolumny
		// binarnej jako ASCII i odrzuca zapytania z polskimi znakami (nazwa konkurenta).
		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_competitors')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`name` VARCHAR(190) NOT NULL,
			`domain` VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`domain_key` BINARY(16) NOT NULL,
			`status` ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
			`created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `project_domain` (`project_id`, `domain_key`),
			KEY `project_status` (`project_id`, `status`)
		) {$options}");

		// `public_id` w utf8mb4_bin (nie ascii_bin) — lista fraz jest filtrowana tekstem frazy (patrz wyżej, $wpdb).
		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_tracked_keywords')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`market_keyword_id` INT UNSIGNED NOT NULL,
			`source` ENUM('manual','gsc','discovery') NOT NULL DEFAULT 'manual',
			`status` ENUM('active','removed') NOT NULL DEFAULT 'active',
			`added_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`added_at` DATETIME NOT NULL,
			`removed_at` DATETIME NULL DEFAULT NULL,
			`last_requested_at` DATETIME NULL DEFAULT NULL,
			`last_snapshot_id` INT UNSIGNED NULL DEFAULT NULL,
			`last_context_id` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`last_checked_at` DATETIME NULL DEFAULT NULL,
			`last_found` TINYINT UNSIGNED NULL DEFAULT NULL,
			`last_rank` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`last_rank_absolute` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`last_url_id` INT UNSIGNED NULL DEFAULT NULL,
			`last_depth` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`last_featured` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`prev_snapshot_id` INT UNSIGNED NULL DEFAULT NULL,
			`prev_found` TINYINT UNSIGNED NULL DEFAULT NULL,
			`prev_rank` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`change_type` ENUM('new','up','down','same','entered','left','out','incomparable') NULL DEFAULT NULL,
			`change_value` SMALLINT NULL DEFAULT NULL,
			`top10_change` ENUM('entered','left') NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `project_market_keyword` (`project_id`, `market_keyword_id`),
			KEY `project_rank` (`project_id`, `status`, `last_rank`),
			KEY `project_requested` (`project_id`, `status`, `last_requested_at`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_runs')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`context_id` SMALLINT UNSIGNED NOT NULL,
			`trigger_type` ENUM('schedule','manual') NOT NULL,
			`slot_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`status` ENUM('queued','submitting','submitted','completed','partial','failed','skipped','cancelled') NOT NULL DEFAULT 'queued',
			`skip_reason` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`keywords_planned` INT UNSIGNED NOT NULL DEFAULT 0,
			`keywords_skipped` INT UNSIGNED NOT NULL DEFAULT 0,
			`tasks_submitted` INT UNSIGNED NOT NULL DEFAULT 0,
			`tasks_completed` INT UNSIGNED NOT NULL DEFAULT 0,
			`tasks_failed` INT UNSIGNED NOT NULL DEFAULT 0,
			`estimated_cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`error_message` VARCHAR(255) NULL DEFAULT NULL,
			`created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`submitted_at` DATETIME NULL DEFAULT NULL,
			`finished_at` DATETIME NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `project_slot` (`project_id`, `slot_key`),
			KEY `project_run` (`project_id`, `id`),
			KEY `status_run` (`status`, `id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_snapshots')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`tracked_keyword_id` INT UNSIGNED NOT NULL,
			`market_keyword_id` INT UNSIGNED NOT NULL,
			`context_id` SMALLINT UNSIGNED NOT NULL,
			`run_id` INT UNSIGNED NOT NULL,
			`market_task_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`provider` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`provider_task_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`status` ENUM('queued','uncertain','submitted','completed','failed','expired','cancelled') NOT NULL DEFAULT 'queued',
			`trigger_type` ENUM('schedule','manual') NOT NULL,
			`requested_depth` SMALLINT UNSIGNED NOT NULL,
			`estimated_cost` DECIMAL(10,6) NOT NULL DEFAULT 0,
			`cost` DECIMAL(10,6) NULL DEFAULT NULL,
			`attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`submitted_at` DATETIME NULL DEFAULT NULL,
			`next_check_at` DATETIME NULL DEFAULT NULL,
			`checked_at` DATETIME NULL DEFAULT NULL,
			`collected_at` DATETIME NULL DEFAULT NULL,
			`se_domain` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`se_results_count` BIGINT UNSIGNED NULL DEFAULT NULL,
			`pages_count` TINYINT UNSIGNED NULL DEFAULT NULL,
			`items_count` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`organic_count` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`item_types` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`spell_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`spell_keyword` VARCHAR(255) NULL DEFAULT NULL,
			`project_rank` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`project_rank_absolute` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`project_url_id` INT UNSIGNED NULL DEFAULT NULL,
			`project_results` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`project_featured` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			KEY `tracked_history` (`tracked_keyword_id`, `context_id`, `checked_at`),
			KEY `project_snapshot` (`project_id`, `id`),
			KEY `collect` (`status`, `next_check_at`),
			KEY `provider_task` (`provider_task_id`),
			KEY `run` (`run_id`, `status`),
			KEY `market_task` (`market_task_id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_results')}` (
			`snapshot_id` INT UNSIGNED NOT NULL,
			`item_index` SMALLINT UNSIGNED NOT NULL,
			`result_type` TINYINT UNSIGNED NOT NULL,
			`rank_group` SMALLINT UNSIGNED NOT NULL,
			`rank_absolute` SMALLINT UNSIGNED NOT NULL,
			`page` TINYINT UNSIGNED NULL DEFAULT NULL,
			`domain_id` INT UNSIGNED NOT NULL,
			`url_id` INT UNSIGNED NOT NULL,
			`snippet_id` INT UNSIGNED NULL DEFAULT NULL,
			`flags` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (`snapshot_id`, `item_index`),
			KEY `domain_snapshot` (`domain_id`, `snapshot_id`, `rank_group`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_domains')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`host_hash` BINARY(16) NOT NULL,
			`host` VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`host_rev` VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `host_hash` (`host_hash`),
			KEY `host_rev` (`host_rev`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_urls')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`url_hash` BINARY(16) NOT NULL,
			`domain_id` INT UNSIGNED NOT NULL,
			`url` VARCHAR(2048) NOT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `url_hash` (`url_hash`),
			KEY `domain` (`domain_id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('serp_snippets')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`snippet_hash` BINARY(16) NOT NULL,
			`title` VARCHAR(512) NULL DEFAULT NULL,
			`description` TEXT NULL,
			`breadcrumb` VARCHAR(512) NULL DEFAULT NULL,
			`website_name` VARCHAR(255) NULL DEFAULT NULL,
			`extra` LONGTEXT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `snippet_hash` (`snippet_hash`)
		) {$options}");
	}
}
