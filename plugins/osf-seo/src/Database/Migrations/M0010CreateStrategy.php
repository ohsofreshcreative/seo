<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Strategia (STEP 16, faza A — fundament, docs/ARCHITECTURE.md, sekcja 15.4):
 *
 * - `strategy_settings` — stan przeliczenia projektu: klucz danych (także mutacji ręcznych), rewizja wpisów ręcznych Strategii,
 *   liczby źródeł i powodów pominięcia, rynek ostatniego przeliczenia,
 * - `strategy_keywords` — kandydat Strategii = fraza rynkowa projektu (`UNIQUE (project_id, market_keyword_id)`): maska źródeł,
 *   wpis ręczny, fakty GSC i SERP na chwilę przeliczenia, odwołania do rekordów modułów i dowody (JSON) — metryki rynkowe, historia GSC
 *   i pełne SERP-y nie są kopiowane.
 *
 * Tylko nowe tabele (bez zmian istniejących danych). Idempotentna. Tematy, zdarzenia i profile pomiarów SERP dodadzą kolejne migracje.
 */
final class M0010CreateStrategy implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 10;
	}

	public function name(): string
	{
		return 'create_strategy';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('strategy_settings')}` (
			`project_id` INT UNSIGNED NOT NULL,
			`revision` INT UNSIGNED NOT NULL DEFAULT 0,
			`data_key` BINARY(16) NULL DEFAULT NULL,
			`refreshed_at` DATETIME NULL DEFAULT NULL,
			`refresh_ms` INT UNSIGNED NULL DEFAULT NULL,
			`stats` LONGTEXT NULL,
			`location_code` INT UNSIGNED NULL DEFAULT NULL,
			`language_code` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`project_id`)
		) {$options}");

		// `public_id` w utf8mb4_bin (nie ascii_bin): $wpdb traktuje tabelę mieszającą kolumny ascii i utf8mb4 bez kolumny binarnej
		// jako ASCII i odrzuca zapytania z polskimi znakami (wyszukiwanie kandydatów po tekście frazy).
		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('strategy_keywords')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`market_keyword_id` INT UNSIGNED NOT NULL,
			`active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
			`inactive_reason` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`sources` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`tier` TINYINT UNSIGNED NULL DEFAULT NULL,
			`manual` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`manual_added_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`manual_added_at` DATETIME NULL DEFAULT NULL,
			`gsc_impressions` INT UNSIGNED NULL DEFAULT NULL,
			`gsc_clicks` INT UNSIGNED NULL DEFAULT NULL,
			`gsc_position` DECIMAL(6,2) NULL DEFAULT NULL,
			`gsc_pages` TINYINT UNSIGNED NULL DEFAULT NULL,
			`gsc_top_url_id` INT UNSIGNED NULL DEFAULT NULL,
			`gsc_top_share` DECIMAL(5,4) NULL DEFAULT NULL,
			`tracked_keyword_id` INT UNSIGNED NULL DEFAULT NULL,
			`serp_checked_at` DATETIME NULL DEFAULT NULL,
			`serp_found` TINYINT UNSIGNED NULL DEFAULT NULL,
			`serp_rank` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`serp_url_id` INT UNSIGNED NULL DEFAULT NULL,
			`gap_keyword_id` INT UNSIGNED NULL DEFAULT NULL,
			`gap_cluster_id` INT UNSIGNED NULL DEFAULT NULL,
			`discovery_candidate_id` INT UNSIGNED NULL DEFAULT NULL,
			`opportunities` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`evidence` LONGTEXT NULL,
			`facts_hash` BINARY(16) NULL DEFAULT NULL,
			`first_seen_at` DATETIME NOT NULL,
			`refreshed_at` DATETIME NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `project_market_keyword` (`project_id`, `market_keyword_id`),
			KEY `project_active` (`project_id`, `active`, `tier`)
		) {$options}");
	}
}
