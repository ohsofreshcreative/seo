<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Page Intelligence (STEP 17, faza B — docs/ARCHITECTURE.md, sekcja 23):
 *
 * - `page_targets` — strona w obrębie projektu (projektu albo konkurenta): adres znormalizowany i jego klucz, host, rodzaj, źródło,
 *   powiązany temat Strategii, stan ostatniej próby i ostatni poprawny snapshot. Bez współdzielenia między projektami.
 * - `page_snapshots` — wyekstrahowana treść strony z jednego pobrania (meta, nagłówki, treść główna, linki, sygnały techniczne, jakość
 *   ekstrakcji, ograniczenia odczytu) z odciskami treści i odpowiedzi — bez surowego HTML. Identyczna treść nie tworzy kopii (`last_seen_at`).
 * - `page_fetches` — próby pobrania (źródło zlecenia, czas, wynik, kod błędu, czy było żądanie HTTP, `Retry-After`, bezpieczna diagnostyka).
 * - `page_serp_links` — strona ↔ organiczny wynik zapisanego pomiaru SERP (fraza, pozycja, data pomiaru — osobno od daty pobrania strony).
 * - `ai_runs.evidence_fingerprint` — odcisk dowodów kontekstu AI bez stanu pracy tematu (obok odcisku pełnego wejścia).
 *
 * Wyłącznie addytywnie. Idempotentna.
 */
final class M0016PageIntelligence implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 16;
	}

	public function name(): string
	{
		return 'page_intelligence';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('page_targets')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`url_key` BINARY(32) NOT NULL,
			`url` VARCHAR(2048) NOT NULL,
			`host` VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`kind` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`source` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`topic_id` INT UNSIGNED NULL DEFAULT NULL,
			`status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'new',
			`last_attempt_at` DATETIME NULL DEFAULT NULL,
			`last_success_at` DATETIME NULL DEFAULT NULL,
			`last_error` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`last_http_status` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`last_snapshot_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`final_url` VARCHAR(2048) NULL DEFAULT NULL,
			`created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `project_url` (`project_id`, `url_key`),
			KEY `project_kind` (`project_id`, `kind`, `updated_at`),
			KEY `project_topic` (`project_id`, `topic_id`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('page_snapshots')}` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`target_id` INT UNSIGNED NOT NULL,
			`extractor_version` SMALLINT UNSIGNED NOT NULL,
			`fetched_at` DATETIME NOT NULL,
			`last_seen_at` DATETIME NOT NULL,
			`http_status` SMALLINT UNSIGNED NOT NULL,
			`content_type` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`charset` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`final_url` VARCHAR(2048) NOT NULL,
			`bytes` INT UNSIGNED NOT NULL,
			`fetch_ms` INT UNSIGNED NOT NULL,
			`body_hash` BINARY(32) NOT NULL,
			`content_hash` BINARY(32) NOT NULL,
			`etag` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`last_modified` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`title` VARCHAR(512) NULL DEFAULT NULL,
			`word_count` INT UNSIGNED NOT NULL DEFAULT 0,
			`h1_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`headings_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`links_internal` INT UNSIGNED NOT NULL DEFAULT 0,
			`links_external` INT UNSIGNED NOT NULL DEFAULT 0,
			`content_quality` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`indexability` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`canonical_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`data` LONGTEXT NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			KEY `target_fetched` (`project_id`, `target_id`, `fetched_at`),
			KEY `project_seen` (`project_id`, `last_seen_at`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('page_fetches')}` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`project_id` INT UNSIGNED NOT NULL,
			`target_id` INT UNSIGNED NOT NULL,
			`snapshot_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`host` VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`trigger_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`requested_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`started_at` DATETIME NOT NULL,
			`finished_at` DATETIME NULL DEFAULT NULL,
			`duration_ms` INT UNSIGNED NULL DEFAULT NULL,
			`network` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`outcome` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`http_status` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`bytes` INT UNSIGNED NULL DEFAULT NULL,
			`retry_after_at` DATETIME NULL DEFAULT NULL,
			`diagnostics` TEXT NULL,
			`request_key` BINARY(16) NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `host_started` (`host`, `started_at`),
			KEY `project_target` (`project_id`, `target_id`, `started_at`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('page_serp_links')}` (
			`target_id` INT UNSIGNED NOT NULL,
			`serp_snapshot_id` INT UNSIGNED NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`market_keyword_id` INT UNSIGNED NOT NULL,
			`rank_group` SMALLINT UNSIGNED NOT NULL,
			`serp_checked_at` DATETIME NOT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY (`target_id`, `serp_snapshot_id`),
			KEY `project_keyword` (`project_id`, `market_keyword_id`)
		) {$options}");

		if (! MigrationHelpers::columnExists($db, 'ai_runs', 'evidence_fingerprint')) {
			$db->execute("ALTER TABLE `{$db->table('ai_runs')}` ADD COLUMN `evidence_fingerprint` BINARY(32) NULL DEFAULT NULL");
		}
	}
}
