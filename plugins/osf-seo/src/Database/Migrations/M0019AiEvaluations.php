<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Oceny jakości analiz AI (STEP 17, faza E — docs/ARCHITECTURE.md, sekcja 26): `ai_evaluations` — ręczna ocena ekspercka zapisanego wyniku
 * według rubryki (`QualityRubric`: 10 kryteriów 1–5 albo „nie dotyczy”, błędy wskazane przy konkretnej rekomendacji, werdykt i działanie
 * naprawcze). Wersje instrukcji, kontraktu i rubryki oraz dostawca i model kopiowane z uruchomienia — ocena przetrwa retencję historii
 * i pozwala porównać wersje instrukcji. Bez łącznego „wyniku SEO”. Ocenia wyłącznie człowiek (identyfikator oceniającego wymagany).
 *
 * Wyłącznie addytywnie (nowa tabela). Idempotentna. Tekst użytkownika (notatki) zapisywany przez `insert()`/`update()`; `public_id` utf8mb4_bin.
 */
final class M0019AiEvaluations implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 19;
	}

	public function name(): string
	{
		return 'ai_evaluations';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('ai_evaluations')}` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`run_id` BIGINT UNSIGNED NOT NULL,
			`evaluator_id` BIGINT UNSIGNED NOT NULL,
			`task` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`provider` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`model` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`prompt_version` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`contract_version` SMALLINT UNSIGNED NOT NULL,
			`rubric_version` SMALLINT UNSIGNED NOT NULL,
			`case_id` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`scores` TEXT NOT NULL,
			`issues` TEXT NOT NULL,
			`verdict` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`fix_action` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`notes` TEXT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			UNIQUE KEY `run_evaluator` (`run_id`, `evaluator_id`),
			KEY `project_created` (`project_id`, `created_at`),
			KEY `task_prompt` (`task`, `prompt_version`)
		) {$options}");
	}
}
