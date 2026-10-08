<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Rekomendacje AI (STEP 17, faza C — docs/ARCHITECTURE.md, sekcja 24): typ analizy zapisujemy w istniejącej kolumnie `ai_runs.task`,
 * a brakujące dane historii dopisujemy addytywnie:
 *
 * - `plan_fingerprint` — odcisk zatwierdzonego planu (typ, dostawca, model, wersje instrukcji i kontraktu, odcisk kontekstu, limit tokenów,
 *   koszt maksymalny i ceny) — wykonanie innego planu niż zatwierdzony jest odrzucane, a ten sam plan nie wykona się przypadkiem dwa razy,
 * - `readiness` — stan gotowości danych w chwili wykonania (`ready`, `partial`),
 * - `sources` — zwarty JSON z identyfikatorami użytych snapshotów stron, pomiaru SERP i braków danych (lista historii bez czytania wejścia).
 *
 * Wyłącznie addytywnie (kolumny z NULL i indeks). Idempotentna.
 */
final class M0017AiRecommendations implements Migration
{
	/** @var array<string, string> kolumna → definicja */
	private const COLUMNS = [
		'plan_fingerprint' => 'BINARY(32) NULL DEFAULT NULL',
		'readiness' => 'VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL',
		'sources' => 'TEXT NULL',
	];

	public function version(): int
	{
		return 17;
	}

	public function name(): string
	{
		return 'ai_recommendations';
	}

	public function up(Connection $db): void
	{
		$table = $db->table('ai_runs');

		foreach (self::COLUMNS as $column => $definition) {
			if (! MigrationHelpers::columnExists($db, 'ai_runs', $column)) {
				$db->execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
			}
		}

		if (! MigrationHelpers::indexExists($db, 'ai_runs', 'project_plan')) {
			$db->execute("ALTER TABLE `{$table}` ADD INDEX `project_plan` (`project_id`, `plan_fingerprint`)");
		}
	}
}
