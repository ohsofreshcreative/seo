<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Database\Connection;

/**
 * Aktualność źródeł Strategii dla przeglądu panelu (faza D) — wyłącznie tanie odczyty zapisanego stanu modułów (bez agregacji danych):
 * GSC (najnowsza data danych fraz i ostatnia udana synchronizacja), pomiary SERP projektu (ostatni zakończony pomiar), Luki SEO
 * (ostatni zakończony import DataForSEO Labs i ostatnie przeliczenie luk).
 */
final class StrategyFreshness
{
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * @return array{gsc: array{connected: bool, newest_date: ?string, last_success_at: ?string}, serp: array{last_checked_at: ?string}, labs: array{last_import_at: ?string, recalculated_at: ?string}}
	 */
	public function forProject(int $projectId): array
	{
		$gsc = $this->db->fetchRow(
			"SELECT MAX(newest_date) AS newest, MAX(last_success_at) AS success FROM `{$this->db->table('sync_state')}` WHERE project_id = %d AND dataset IN ('query', 'query_page')",
			[$projectId],
		) ?? [];
		$property = $this->db->fetchValue("SELECT gsc_data_property FROM `{$this->db->table('projects')}` WHERE id = %d", [$projectId]);

		return [
			'gsc' => [
				'connected' => $property !== null && $property !== '',
				'newest_date' => $gsc['newest'] ?? null,
				'last_success_at' => $gsc['success'] ?? null,
			],
			'serp' => [
				// Ostatni pomiar każdej frazy (monitorowanej i analizy) jest w wierszu frazy — bez skanowania historii pomiarów.
				'last_checked_at' => $this->db->fetchValue(
					"SELECT MAX(last_checked_at) FROM `{$this->db->table('serp_tracked_keywords')}` WHERE project_id = %d",
					[$projectId],
				),
			],
			'labs' => [
				'last_import_at' => $this->db->fetchValue(
					"SELECT MAX(finished_at) FROM `{$this->db->table('gap_runs')}` WHERE project_id = %d AND status IN ('completed', 'partial')",
					[$projectId],
				),
				'recalculated_at' => $this->db->fetchValue("SELECT recalculated_at FROM `{$this->db->table('gap_settings')}` WHERE project_id = %d", [$projectId]),
			],
		];
	}
}
