<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Schemat MVP 1. Migracja niezmienna po wdrożeniu — kolejne zmiany w nowych migracjach.
 *
 * Decyzje (szczegóły: docs/ARCHITECTURE.md, sekcja 6):
 * - ID encji INT UNSIGNED (nie BIGINT): tabele faktów powtarzają klucze w PK i indeksach wtórnych,
 *   4 zamiast 8 bajtów na kolumnę, zakres 4,29 mld wystarcza; user_id = BIGINT jak wp_users.ID,
 * - tabele faktów: klastrowy PK zaczynający się od (project_id, date) — idempotentny import
 *   (zamiana zakresu dat) i skan okresu w jednym bloku; bez kluczy obcych (kaskady na milionach wierszy),
 * - frazy i URL-e przez ID + hash BINARY(16) w UNIQUE (długie teksty nie mieszczą się w indeksie),
 * - `trigger_type` zamiast `trigger` (słowo zastrzeżone w MySQL),
 * - daty i czasy w UTC, poza kolumnami `date` faktów GSC (daty GSC w czasie pacyficznym).
 */
final class M0001CreateCoreTables implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 1;
	}

	public function name(): string
	{
		return 'create_core_tables';
	}

	public function up(Connection $db): void
	{
		foreach ($this->statements($db) as $sql) {
			$db->execute($sql);
		}
	}

	/**
	 * @return list<string>
	 */
	private function statements(Connection $db): array
	{
		$options = self::TABLE_OPTIONS;

		return [
			"CREATE TABLE IF NOT EXISTS `{$db->table('projects')}` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				`name` VARCHAR(190) NOT NULL,
				`domain` VARCHAR(190) NOT NULL,
				`country` CHAR(2) NOT NULL DEFAULT 'pl',
				`language` VARCHAR(10) NOT NULL DEFAULT 'pl',
				`status` ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
				`connection_id` INT UNSIGNED NULL DEFAULT NULL,
				`gsc_property` VARCHAR(255) NULL DEFAULT NULL,
				`gsc_permission` VARCHAR(32) NULL DEFAULT NULL,
				`settings` LONGTEXT NULL,
				`last_synced_at` DATETIME NULL DEFAULT NULL,
				`created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
				`created_at` DATETIME NOT NULL,
				`updated_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `public_id` (`public_id`),
				KEY `status` (`status`),
				KEY `domain` (`domain`),
				KEY `connection_id` (`connection_id`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('project_users')}` (
				`project_id` INT UNSIGNED NOT NULL,
				`user_id` BIGINT UNSIGNED NOT NULL,
				`role` ENUM('manager','viewer') NOT NULL DEFAULT 'viewer',
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`project_id`, `user_id`),
				KEY `user_project` (`user_id`, `project_id`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('connections')}` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`owner_user_id` BIGINT UNSIGNED NOT NULL,
				`provider` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'google',
				`google_sub` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				`email` VARCHAR(190) NOT NULL DEFAULT '',
				`refresh_token_enc` TEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				`scopes` TEXT NULL,
				`status` ENUM('active','needs_reauth','revoked') NOT NULL DEFAULT 'active',
				`last_error` VARCHAR(255) NULL DEFAULT NULL,
				`last_refreshed_at` DATETIME NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL,
				`updated_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `provider_account_owner` (`provider`, `google_sub`, `owner_user_id`),
				KEY `owner_user_id` (`owner_user_id`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('keywords')}` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`project_id` INT UNSIGNED NOT NULL,
				`keyword` VARCHAR(500) NOT NULL,
				`keyword_hash` BINARY(16) NOT NULL,
				`first_seen` DATE NULL DEFAULT NULL,
				`last_seen` DATE NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `project_keyword_hash` (`project_id`, `keyword_hash`),
				KEY `project_last_seen` (`project_id`, `last_seen`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('pages')}` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`project_id` INT UNSIGNED NOT NULL,
				`url` VARCHAR(2048) NOT NULL,
				`url_hash` BINARY(16) NOT NULL,
				`path` VARCHAR(2048) NOT NULL DEFAULT '',
				`first_seen` DATE NULL DEFAULT NULL,
				`last_seen` DATE NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `project_url_hash` (`project_id`, `url_hash`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('gsc_site_daily')}` (
				`project_id` INT UNSIGNED NOT NULL,
				`date` DATE NOT NULL,
				`device` TINYINT UNSIGNED NOT NULL,
				`clicks` INT UNSIGNED NOT NULL DEFAULT 0,
				`impressions` INT UNSIGNED NOT NULL DEFAULT 0,
				`position_sum` DOUBLE NOT NULL DEFAULT 0,
				PRIMARY KEY (`project_id`, `date`, `device`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('gsc_query_daily')}` (
				`project_id` INT UNSIGNED NOT NULL,
				`date` DATE NOT NULL,
				`keyword_id` INT UNSIGNED NOT NULL,
				`clicks` INT UNSIGNED NOT NULL DEFAULT 0,
				`impressions` INT UNSIGNED NOT NULL DEFAULT 0,
				`position_sum` DOUBLE NOT NULL DEFAULT 0,
				PRIMARY KEY (`project_id`, `date`, `keyword_id`),
				KEY `project_keyword_date` (`project_id`, `keyword_id`, `date`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('gsc_query_page_daily')}` (
				`project_id` INT UNSIGNED NOT NULL,
				`date` DATE NOT NULL,
				`keyword_id` INT UNSIGNED NOT NULL,
				`page_id` INT UNSIGNED NOT NULL,
				`clicks` INT UNSIGNED NOT NULL DEFAULT 0,
				`impressions` INT UNSIGNED NOT NULL DEFAULT 0,
				`position_sum` DOUBLE NOT NULL DEFAULT 0,
				PRIMARY KEY (`project_id`, `date`, `keyword_id`, `page_id`),
				KEY `project_keyword_date_page` (`project_id`, `keyword_id`, `date`, `page_id`),
				KEY `project_page_date_keyword` (`project_id`, `page_id`, `date`, `keyword_id`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('visibility_daily')}` (
				`project_id` INT UNSIGNED NOT NULL,
				`date` DATE NOT NULL,
				`top3` INT UNSIGNED NOT NULL DEFAULT 0,
				`top10` INT UNSIGNED NOT NULL DEFAULT 0,
				`top20` INT UNSIGNED NOT NULL DEFAULT 0,
				`top50` INT UNSIGNED NOT NULL DEFAULT 0,
				`top100` INT UNSIGNED NOT NULL DEFAULT 0,
				`keywords_total` INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (`project_id`, `date`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('sync_state')}` (
				`project_id` INT UNSIGNED NOT NULL,
				`dataset` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				`status` ENUM('idle','queued','running','failed') NOT NULL DEFAULT 'idle',
				`newest_date` DATE NULL DEFAULT NULL,
				`oldest_date` DATE NULL DEFAULT NULL,
				`consecutive_failures` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`last_success_at` DATETIME NULL DEFAULT NULL,
				`last_attempt_at` DATETIME NULL DEFAULT NULL,
				`last_error` VARCHAR(500) NULL DEFAULT NULL,
				`updated_at` DATETIME NOT NULL,
				PRIMARY KEY (`project_id`, `dataset`)
			) {$options}",

			"CREATE TABLE IF NOT EXISTS `{$db->table('sync_runs')}` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`project_id` INT UNSIGNED NOT NULL,
				`dataset` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				`trigger_type` ENUM('schedule','manual','backfill','connect') NOT NULL,
				`window_start` DATE NOT NULL,
				`window_end` DATE NOT NULL,
				`status` ENUM('queued','running','success','failed','skipped') NOT NULL DEFAULT 'queued',
				`attempt` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				`rows_fetched` INT UNSIGNED NOT NULL DEFAULT 0,
				`rows_written` INT UNSIGNED NOT NULL DEFAULT 0,
				`api_requests` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`error_code` VARCHAR(64) NULL DEFAULT NULL,
				`error_message` TEXT NULL,
				`queued_at` DATETIME NOT NULL,
				`started_at` DATETIME NULL DEFAULT NULL,
				`finished_at` DATETIME NULL DEFAULT NULL,
				PRIMARY KEY (`id`),
				KEY `project_run` (`project_id`, `id`),
				KEY `status_queued` (`status`, `queued_at`)
			) {$options}",
		];
	}
}
