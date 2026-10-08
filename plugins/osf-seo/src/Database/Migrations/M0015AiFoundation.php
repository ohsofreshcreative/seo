<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Fundament AI (STEP 17, faza A — docs/ARCHITECTURE.md, sekcja 22):
 *
 * - `ai_runs` — uruchomienie analizy AI (jednocześnie rejestr kosztów AI, całkowicie oddzielny od `market_tasks` DataForSEO): projekt,
 *   temat, zadanie, dostawca i model, wersje instrukcji, kontekstu i kontraktu odpowiedzi, odciski kontekstu i dowodów Strategii, status,
 *   czasy, tokeny, koszt szacowany / zarezerwowany / rozliczony z podstawą rozliczenia, kod błędu, decyzja użytkownika (osobno od wyniku),
 * - `ai_run_payloads` — dane uruchomienia oddzielone od metadanych (lista historii ich nie czyta): wejście (kontekst), ograniczona surowa
 *   odpowiedź modelu, zwalidowany wynik strukturalny i raport walidacji.
 *
 * Tylko nowe tabele (dane Strategii i workflow bez zmian). Idempotentna.
 */
final class M0015AiFoundation implements Migration
{
	private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	public function version(): int
	{
		return 15;
	}

	public function name(): string
	{
		return 'ai_foundation';
	}

	public function up(Connection $db): void
	{
		$options = self::TABLE_OPTIONS;

		// `public_id` w utf8mb4_bin i odciski binarne — kolumny binarne pozwalają `$wpdb` zapisywać polskie znaki (uwaga w AGENTS.md).
		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('ai_runs')}` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`public_id` CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`topic_id` INT UNSIGNED NULL DEFAULT NULL,
			`task` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`provider` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`model` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`paid` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`prompt_version` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`context_version` SMALLINT UNSIGNED NOT NULL,
			`contract_version` SMALLINT UNSIGNED NOT NULL,
			`context_fingerprint` BINARY(32) NOT NULL,
			`evidence_hash` BINARY(32) NULL DEFAULT NULL,
			`status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`trigger_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`requested_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`started_at` DATETIME NULL DEFAULT NULL,
			`finished_at` DATETIME NULL DEFAULT NULL,
			`input_tokens_estimate` INT UNSIGNED NULL DEFAULT NULL,
			`input_tokens` INT UNSIGNED NULL DEFAULT NULL,
			`cached_tokens` INT UNSIGNED NULL DEFAULT NULL,
			`output_tokens` INT UNSIGNED NULL DEFAULT NULL,
			`estimated_cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`reserved_cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
			`actual_cost` DECIMAL(12,6) NULL DEFAULT NULL,
			`cost_basis` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`provider_response_id` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`validation_errors` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`decision` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
			`decided_by` BIGINT UNSIGNED NULL DEFAULT NULL,
			`decided_at` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `public_id` (`public_id`),
			KEY `project_created` (`project_id`, `created_at`),
			KEY `project_topic` (`project_id`, `topic_id`, `created_at`),
			KEY `status_created` (`status`, `created_at`)
		) {$options}");

		$db->execute("CREATE TABLE IF NOT EXISTS `{$db->table('ai_run_payloads')}` (
			`run_id` BIGINT UNSIGNED NOT NULL,
			`project_id` INT UNSIGNED NOT NULL,
			`input` LONGTEXT NULL,
			`output_raw` MEDIUMTEXT NULL,
			`result` LONGTEXT NULL,
			`validation` TEXT NULL,
			`input_hash` BINARY(32) NULL DEFAULT NULL,
			PRIMARY KEY (`run_id`),
			KEY `project_id` (`project_id`)
		) {$options}");
	}
}
