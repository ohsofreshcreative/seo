<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Panel Strategii (STEP 16, faza D — docs/ARCHITECTURE.md, sekcja 15.14): kolumny wyliczane tematu potrzebne do filtrów i listy backlogu
 * bez dekodowania analizy (maska źródeł fraz, Pozycja SERP i chwila pomiaru odniesienia, wyświetlenia i średnia pozycja GSC tematu) oraz
 * znacznik ręcznie zleconego przeliczenia (`strategy_settings.refresh_requested_at/by` — wykona je krok w tle, faza E, albo CLI) i chwila
 * najnowszego zgodnego pomiaru SERP frazy (`strategy_keywords.serp_intel_at` — lista SERP Intelligence filtruje świeżość w SQL).
 *
 * Wyłącznie addytywnie (nowe kolumny z NULL / 0). Idempotentna. Wartości uzupełni najbliższe przeliczenie (nowa wersja reguł).
 */
final class M0013StrategyPanel implements Migration
{
	/** @var array<string, array<string, string>> tabela → kolumna → definicja (kolejność = kolejność dopisania) */
	private const COLUMNS = [
		'strategy_topics' => [
			'sources' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
			'serp_rank' => 'SMALLINT UNSIGNED NULL DEFAULT NULL',
			'serp_checked_at' => 'DATETIME NULL DEFAULT NULL',
			'gsc_impressions' => 'INT UNSIGNED NULL DEFAULT NULL',
			'gsc_position' => 'DECIMAL(6,2) NULL DEFAULT NULL',
		],
		'strategy_settings' => [
			'refresh_requested_at' => 'DATETIME NULL DEFAULT NULL',
			'refresh_requested_by' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
		],
		'strategy_keywords' => [
			'serp_intel_at' => 'DATETIME NULL DEFAULT NULL',
		],
	];

	public function version(): int
	{
		return 13;
	}

	public function name(): string
	{
		return 'strategy_panel';
	}

	public function up(Connection $db): void
	{
		foreach (self::COLUMNS as $table => $columns) {
			foreach ($columns as $column => $definition) {
				if (! MigrationHelpers::columnExists($db, $table, $column)) {
					$db->execute("ALTER TABLE `{$db->table($table)}` ADD COLUMN `{$column}` {$definition}");
				}
			}
		}
	}
}
