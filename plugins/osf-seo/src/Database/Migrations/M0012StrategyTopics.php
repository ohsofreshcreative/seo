<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Rdzeń Strategii (STEP 16, faza C — docs/ARCHITECTURE.md, sekcja 15.13):
 *
 * - `strategy_topics` — temat (jednostka backlogu): stabilny `public_id`, lider i liczba fraz, strona docelowa (stan, adres, ręczne
 *   wskazanie albo ręczne potwierdzenie braku strony), działanie z kodem powodu, pewność punktowa, Priorytet Strategii, status pracy
 *   (zmieniany wyłącznie przez użytkownika) z notatką, podstawą decyzji i punktem odniesienia do późniejszej oceny efektu,
 *   analiza (rozbicie pewności i priorytetu, sygnały konfliktu URL, sugestie grup) i odcisk dowodów,
 * - `strategy_topic_events` — wyłącznie istotne zmiany tematu (bez pełnych migawek),
 * - `strategy_keywords` — przynależność do tematu (`topic_id`, zostaje także dla nieaktywnych kandydatów — do stabilnych ID),
 *   ręczne przypięcie do tematu i stan strony docelowej frazy.
 *
 * Zmiany istniejących tabel wyłącznie addytywne (nowe kolumny z NULL i indeks). Idempotentna.
 */
final class M0012StrategyTopics implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	/** @var array<string, string> nowe kolumny `strategy_keywords` (kolejność = kolejność dopisania) */
	private const KEYWORD_COLUMNS = [
		'topic_id' => 'INT UNSIGNED NULL DEFAULT NULL',
		'pinned_topic_id' => 'INT UNSIGNED NULL DEFAULT NULL',
		'pinned_by' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
		'pinned_at' => 'DATETIME NULL DEFAULT NULL',
		'target_state' => 'VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL',
		'target_url_id' => 'INT UNSIGNED NULL DEFAULT NULL',
	];

	public function version(): int
	{
		return 12;
	}

	public function name(): string
	{
		return 'strategy_topics';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		// `public_id` w utf8mb4_bin, `evidence_hash` binarny — etykieta tematu (tekst frazy lidera) zawiera polskie znaki (D53, uwaga o $wpdb).
		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('strategy_topics')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`active` TINYINT UNSIGNED NOT NULL DEFAULT 1,
			`inactive_reason` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`merged_into_id` INT UNSIGNED NULL DEFAULT NULL,
			`leader_market_keyword_id` INT UNSIGNED NULL DEFAULT NULL,
			`label` VARCHAR(255) NULL DEFAULT NULL,
			`keywords_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`demand` INT UNSIGNED NULL DEFAULT NULL,
			`action` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`action_reason` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`confidence` TINYINT UNSIGNED NULL DEFAULT NULL,
			`confidence_level` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`priority` TINYINT UNSIGNED NULL DEFAULT NULL,
			`target_state` VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`target_url_id` INT UNSIGNED NULL DEFAULT NULL,
			`manual_target_url_id` INT UNSIGNED NULL DEFAULT NULL,
			`manual_no_page` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`manual_target_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`manual_target_at` DATETIME NULL DEFAULT NULL,
			`serp_band` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`status` ENUM('new','review','planned','in_progress','completed','dismissed') NOT NULL DEFAULT 'new',
			`note` TEXT NULL,
			`status_changed_at` DATETIME NULL DEFAULT NULL,
			`status_changed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`completed_on` DATE NULL DEFAULT NULL,
			`status_basis` LONGTEXT NULL,
			`baseline` LONGTEXT NULL,
			`decision_changed` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`analysis` LONGTEXT NULL,
			`evidence_hash` BINARY(16) NULL DEFAULT NULL,
			`first_seen_at` DATETIME NOT NULL,
			`refreshed_at` DATETIME NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			KEY `project_active` (`project_id`, `active`, `priority`),
			KEY `project_status` (`project_id`, `status`)
		) {$options}");

		// Wszystkie kolumny tekstowe w utf8mb4 (dane zdarzenia mogą zawierać tekst frazy z polskimi znakami).
		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('strategy_topic_events')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`topic_id` INT UNSIGNED NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`type` VARCHAR(24) NOT NULL,
			`from_value` VARCHAR(64) NULL DEFAULT NULL,
			`to_value` VARCHAR(64) NULL DEFAULT NULL,
			`data` TEXT NULL,
			`created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			KEY `topic_event` (`topic_id`, `id`),
			KEY `project_event` (`project_id`, `id`)
		) {$options}");

		foreach (self::KEYWORD_COLUMNS as $column => $definition) {
			if (! MigrationHelpers::columnExists($db, 'strategy_keywords', $column)) {
				$db->execute("ALTER TABLE `{$db->table('strategy_keywords')}` ADD COLUMN `{$column}` {$definition}");
			}
		}

		if (! MigrationHelpers::indexExists($db, 'strategy_keywords', 'project_topic')) {
			$db->execute("ALTER TABLE `{$db->table('strategy_keywords')}` ADD KEY `project_topic` (`project_id`, `topic_id`)");
		}
	}
}
