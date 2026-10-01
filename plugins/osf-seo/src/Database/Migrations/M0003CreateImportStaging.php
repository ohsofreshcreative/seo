<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Tabela pośrednia importu GSC. Strony wyników trafiają tu w trakcie pobierania okna dat
 * (pamięć PHP ograniczona do jednej strony); dane właściwe są podmieniane dopiero po pobraniu
 * i walidacji całego okna — jedną transakcją (DELETE zakresu + INSERT … SELECT ze stagingu).
 * Nieudany import nie dotyka danych właściwych; wiersze stagingu są usuwane po każdym imporcie.
 *
 * `run_id` = sync_runs.id; `keyword_id`/`page_id` = 0, gdy dataset nie ma tego wymiaru.
 * PK wykrywa zduplikowane klucze w odpowiedziach Google (niestabilna paginacja).
 */
final class M0003CreateImportStaging implements Migration
{
	public function version(): int
	{
		return 3;
	}

	public function name(): string
	{
		return 'create_import_staging';
	}

	public function up(Connection $db): void
	{
		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('gsc_import_staging')}` (
			`run_id` BIGINT UNSIGNED NOT NULL,
			`date` DATE NOT NULL,
			`keyword_id` INT UNSIGNED NOT NULL DEFAULT 0,
			`page_id` INT UNSIGNED NOT NULL DEFAULT 0,
			`clicks` INT UNSIGNED NOT NULL DEFAULT 0,
			`impressions` INT UNSIGNED NOT NULL DEFAULT 0,
			`position_sum` DOUBLE NOT NULL DEFAULT 0,
			PRIMARY KEY (`run_id`, `date`, `keyword_id`, `page_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}
}
