<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;

/**
 * Dane GSC projektu jako całość: czy istnieją i ich usuwanie (jawny reset przy zmianie property).
 *
 * Usuwanie idzie partiami (DELETE … LIMIT) — bez jednej ogromnej transakcji blokującej tabele;
 * przerwany reset można powtórzyć (projekt ma wtedy nieznane pochodzenie danych, więc kolejny
 * wybór property znów wymaga resetu).
 */
final class GscDataStore
{
	/** Tabele z danymi pochodzącymi z property (faktów i słowników). */
	public const DATA_TABLES = ['gsc_site_daily', 'gsc_query_daily', 'gsc_query_page_daily', 'visibility_daily', 'keywords', 'pages'];

	/** Stan synchronizacji odnosi się do danych — resetowany razem z nimi. */
	public const STATE_TABLES = ['sync_state'];

	private const DELETE_BATCH = 5000;

	public function __construct(private readonly Connection $db)
	{
	}

	public function hasData(ProjectContext $context): bool
	{
		return $this->hasDataFor($context->projectId());
	}

	/**
	 * Usuwa wszystkie dane GSC i stan synchronizacji projektu. Wyłącznie po jawnej decyzji użytkownika.
	 *
	 * @return int liczba usuniętych wierszy
	 */
	public function purge(ProjectContext $context): int
	{
		$deleted = 0;

		foreach ([...self::DATA_TABLES, ...self::STATE_TABLES] as $table) {
			do {
				$affected = $this->db->execute(
					"DELETE FROM `{$this->db->table($table)}` WHERE project_id = %d LIMIT " . self::DELETE_BATCH,
					[$context->projectId()],
				);
				$deleted += $affected;
			} while ($affected >= self::DELETE_BATCH);
		}

		return $deleted;
	}

	private function hasDataFor(int $projectId): bool
	{
		foreach (self::DATA_TABLES as $table) {
			if ($this->db->fetchValue("SELECT 1 FROM `{$this->db->table($table)}` WHERE project_id = %d LIMIT 1", [$projectId]) !== null) {
				return true;
			}
		}

		return false;
	}
}
