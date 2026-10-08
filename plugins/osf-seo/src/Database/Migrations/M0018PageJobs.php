<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Zlecenia pobrania stron z panelu (STEP 17, faza D — docs/ARCHITECTURE.md, sekcja 25): `page_jobs` — jawne zlecenie użytkownika (najwyżej
 * 5 adresów), wykonywane w tle przez krok `SyncScheduler::onAfterRun` wyłącznie przez `PageIntelligenceService::fetch` (ten sam transport,
 * polityka adresów, robots.txt, limity hosta i cache co CLI). Pozycje zlecenia w JSON (wybór, stan, wynik), klucz deduplikacji, termin
 * kolejnej próby po odstępie hosta (`run_after`) i znacznik życia przebiegu (`heartbeat_at`) do odzyskania przerwanego procesu.
 *
 * Wyłącznie addytywnie (nowa tabela, jak tabele przebiegów modułów STEP 13–15). Idempotentna.
 */
final class M0018PageJobs implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 18;
	}

	public function name(): string
	{
		return 'page_jobs';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('page_jobs')}` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`topic_id` INT UNSIGNED NULL DEFAULT NULL,
			`status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
			`force_fetch` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`items` TEXT NOT NULL,
			`items_total` SMALLINT UNSIGNED NOT NULL,
			`items_done` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`request_key` BINARY(32) NOT NULL,
			`requested_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`run_after` DATETIME NOT NULL,
			`started_at` DATETIME NULL DEFAULT NULL,
			`heartbeat_at` DATETIME NULL DEFAULT NULL,
			`finished_at` DATETIME NULL DEFAULT NULL,
			`attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			KEY `status_run` (`status`, `run_after`),
			KEY `project_created` (`project_id`, `created_at`),
			KEY `project_key` (`project_id`, `request_key`)
		) {$options}");
	}
}
