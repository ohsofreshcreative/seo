<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Szanse SEO (STEP 11) — model hybrydowy: dowody są wyliczane z danych GSC, stan pracy jest trwały.
 *
 * - `opportunities` — trwałe „zadanie” (projekt × stabilny odcisk: property, typ, podstrona/fraza/para URL-i):
 *   stan wykrycia (active/inactive/archived), status pracy, notatka, data wdrożenia, baseline,
 *   pierwsze/ostatnie wykrycie i ostatni snapshot dowodów (historia po zniknięciu sygnału),
 * - `opportunity_detections` — dane pochodne: wynik ostatniej analizy danego okresu (7/28/90 dni),
 *   zastępowane przy każdej analizie; nie zawierają stanu pracy,
 * - `opportunity_analyses` — stan analizy per projekt i okres (klucz danych do automatycznego przeliczania).
 *
 * Tylko nowe tabele — istniejące tabele i dane GSC pozostają nietknięte. Idempotentna.
 */
final class M0005CreateOpportunities implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 5;
	}

	public function name(): string
	{
		return 'create_opportunities';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('opportunities')}` (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`fingerprint` BINARY(16) NOT NULL,
			`type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`property` VARCHAR(255) NOT NULL,
			`page_url` VARCHAR(2048) NULL DEFAULT NULL,
			`page_hash` BINARY(16) NULL DEFAULT NULL,
			`keyword` VARCHAR(500) NULL DEFAULT NULL,
			`state` ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
			`status` ENUM('new','review','planned','in_progress','completed','dismissed') NOT NULL DEFAULT 'new',
			`note` TEXT NULL,
			`completed_on` DATE NULL DEFAULT NULL,
			`baseline` LONGTEXT NULL,
			`last_priority` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`last_confidence` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`last_period_days` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`last_latest_date` DATE NULL DEFAULT NULL,
			`last_evidence` LONGTEXT NULL,
			`first_detected_at` DATETIME NOT NULL,
			`last_detected_at` DATETIME NOT NULL,
			`inactive_since` DATETIME NULL DEFAULT NULL,
			`status_changed_at` DATETIME NULL DEFAULT NULL,
			`status_changed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `project_fingerprint` (`project_id`, `fingerprint`),
			KEY `project_state_status` (`project_id`, `state`, `status`),
			KEY `project_page` (`project_id`, `page_hash`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('opportunity_detections')}` (
			`opportunity_id` INT UNSIGNED NOT NULL,
			`period_days` TINYINT UNSIGNED NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`priority` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`confidence` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`impressions` INT UNSIGNED NOT NULL DEFAULT 0,
			`clicks` INT UNSIGNED NOT NULL DEFAULT 0,
			`latest_date` DATE NOT NULL,
			`search_text` TEXT NULL,
			`evidence` LONGTEXT NOT NULL,
			`analyzed_at` DATETIME NOT NULL,
			PRIMARY KEY (`opportunity_id`, `period_days`),
			KEY `project_period_priority` (`project_id`, `period_days`, `priority`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('opportunity_analyses')}` (
			`project_id` INT UNSIGNED NOT NULL,
			`period_days` TINYINT UNSIGNED NOT NULL,
			`status` ENUM('success','skipped','failed') NOT NULL,
			`trigger_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`property` VARCHAR(255) NULL DEFAULT NULL,
			`data_key` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`latest_date` DATE NULL DEFAULT NULL,
			`opportunities` INT UNSIGNED NOT NULL DEFAULT 0,
			`duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
			`message` VARCHAR(255) NULL DEFAULT NULL,
			`analyzed_at` DATETIME NOT NULL,
			PRIMARY KEY (`project_id`, `period_days`)
		) {$options}");
	}
}
