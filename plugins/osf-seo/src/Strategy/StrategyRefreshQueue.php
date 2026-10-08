<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Zadanie przeliczenia Strategii projektu w tle (faza E — docs/ARCHITECTURE.md, sekcja 15.15): stan w wierszu `strategy_settings`
 * (jeden projekt = najwyżej jedno zadanie, bez osobnej tabeli kolejki i bez Action Scheduler — D19). Zadanie jest wyłącznie lokalnym
 * przeliczeniem zapisanych danych — bez żadnego żądania do API.
 *
 * Cykl: idle → queued (zlecenie ręczne z panelu, wykryta zmiana danych) → running (atomowe przejęcie) → idle | queued (ponowienie
 * z odstępem albo nowsze zlecenie) | failed (po MAX_ATTEMPTS próbach — automatycznie dopiero po zmianie danych, ręcznie zawsze).
 *
 * Wzajemne wykluczanie zapewnia blokada `StrategyRefresher::lock()` (GET_LOCK — zwalniana przez serwer bazy, gdy proces PHP zginie):
 * przejęcie, przeliczenie i zakończenie zadania wykonuje proces, który trzyma blokadę projektu. Status `running` bez trzymanej blokady
 * oznacza przerwany proces (odzyskanie: `StrategyScheduler`). Każda zmiana stanu to jedno zapytanie warunkowe — kolejność przypisań
 * w `SET` nie ma znaczenia (warunki czytają wyłącznie kolumny, których zapytanie nie zmienia, albo kolumny przypisane później).
 */
final class StrategyRefreshQueue
{
	public const STATUS_IDLE = 'idle';

	public const STATUS_QUEUED = 'queued';

	public const STATUS_RUNNING = 'running';

	public const STATUS_FAILED = 'failed';

	/** Zlecenie z panelu (przeliczenie wymuszone, pierwszeństwo w kolejce). */
	public const SOURCE_MANUAL = 'manual';

	/** Wykryta zmiana danych modułów albo mutacja ręczna (po debounce). */
	public const SOURCE_AUTO = 'auto';

	/** `wp osf-seo strategy:refresh` (bez kolejki, pod tą samą blokadą). */
	public const SOURCE_CLI = 'cli';

	/** Najwięcej prób na jedno zlecenie (przerwany proces też jest próbą). */
	public const MAX_ATTEMPTS = 3;

	/** Odstęp ponowienia po próbie 1 i 2 (sekundy). */
	public const BACKOFF = [60, 300];

	/** Oczekiwany najdłuższy czas przeliczenia — po nim trzymane zadanie jest w diagnostyce „przekroczone” (bez przejmowania żywego procesu). */
	public const LEASE = 900;

	/** Przesunięcie zadania, gdy projekt przelicza właśnie inny proces (bez liczenia próby). */
	public const BUSY_DELAY = 60;

	public const ERROR_INTERRUPTED = 'interrupted';

	public const ERROR_UNSUPPORTED_MARKET = 'unsupported_market';

	public const ERROR_NO_PROJECT = 'no_project';

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Ręczne zlecenie (panel). Bezczynne, nieudane albo oczekujące zadanie → oczekujące teraz, ze źródłem `manual` i nowym limitem prób.
	 * W trakcie przeliczenia status się nie zmienia — zlecenie później niż początek przeliczenia zleca kolejne po jego zakończeniu.
	 * Klucz danych jest czyszczony (także CLI bez `--force` przeliczy projekt). Wielokrotne kliknięcie nie tworzy kolejnych zadań.
	 */
	public function request(int $projectId, ?int $userId): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, updated_at, refresh_requested_at, refresh_requested_by, refresh_status, refresh_source, refresh_due_at, refresh_attempts)
			VALUES (%d, %s, %s, NULLIF(%d, 0), %s, %s, %s, 0)
			ON DUPLICATE KEY UPDATE data_key = NULL, refresh_requested_at = VALUES(refresh_requested_at), refresh_requested_by = VALUES(refresh_requested_by),
				refresh_source = IF(refresh_status = %s, refresh_source, VALUES(refresh_source)),
				refresh_due_at = IF(refresh_status = %s, refresh_due_at, VALUES(refresh_due_at)),
				refresh_attempts = IF(refresh_status = %s, refresh_attempts, 0),
				refresh_failed_key = IF(refresh_status = %s, refresh_failed_key, NULL),
				refresh_status = IF(refresh_status = %s, refresh_status, VALUES(refresh_status))",
			[
				$projectId, $now, $now, max(0, (int) $userId), self::STATUS_QUEUED, self::SOURCE_MANUAL, $now,
				self::STATUS_RUNNING, self::STATUS_RUNNING, self::STATUS_RUNNING, self::STATUS_RUNNING, self::STATUS_RUNNING,
			],
		);
	}

	/**
	 * Automatyczne zlecenie po wykryciu zmiany danych (tylko z bezczynnego albo nieudanego zadania — oczekujące i trwające już obejmą
	 * nowe dane). Zwraca, czy zadanie zostało zlecone.
	 */
	public function enqueue(int $projectId): bool
	{
		$now = $this->now();

		return $this->db->execute(
			"UPDATE `{$this->table()}` SET refresh_source = %s, refresh_due_at = %s, refresh_attempts = 0, refresh_failed_key = NULL, refresh_status = %s
			WHERE project_id = %d AND refresh_status IN (%s, %s)",
			[self::SOURCE_AUTO, $now, self::STATUS_QUEUED, $projectId, self::STATUS_IDLE, self::STATUS_FAILED],
		) === 1;
	}

	/**
	 * Zadania gotowe do wykonania: najpierw zlecenia ręczne, potem według terminu — z identyfikatorem publicznym projektu zapisanym
	 * w bazie (wykonawca rozwiązuje projekt przez `ProjectGuard::authorizeSystem`, nigdy z wejścia HTTP).
	 *
	 * @return list<array{project_id: int, public_id: string, source: string}>
	 */
	public function dueWithProjects(int $limit): array
	{
		$rows = $this->db->fetchAll(
			"SELECT s.project_id, s.refresh_source, p.public_id FROM `{$this->table()}` s
			LEFT JOIN `{$this->db->table('projects')}` p ON p.id = s.project_id
			WHERE s.refresh_status = %s AND s.refresh_due_at <= %s
			ORDER BY s.refresh_source = %s DESC, s.refresh_due_at, s.project_id LIMIT %d",
			[self::STATUS_QUEUED, $this->now(), self::SOURCE_MANUAL, max(1, $limit)],
		);

		return array_map(static fn (array $row): array => [
			'project_id' => (int) $row['project_id'],
			'public_id' => (string) ($row['public_id'] ?? ''),
			'source' => (string) ($row['refresh_source'] ?? self::SOURCE_AUTO),
		], $rows);
	}

	/**
	 * Projekty do wykrywania zmian: aktywne (bez wstrzymanych i zarchiwizowanych), także bez wiersza stanu Strategii.
	 *
	 * @return list<array{project_id: int, public_id: string}>
	 */
	public function detectionCandidates(): array
	{
		return array_map(static fn (array $row): array => ['project_id' => (int) $row['id'], 'public_id' => (string) $row['public_id']], $this->db->fetchAll(
			"SELECT id, public_id FROM `{$this->db->table('projects')}` WHERE status = 'active' ORDER BY id",
		));
	}

	/**
	 * Atomowe przejęcie oczekującego zadania (wywołujący trzyma blokadę projektu). Zwraca znacznik przejęcia (chwila startu) albo null,
	 * gdy zadanie przejął już inny proces albo nie jest jeszcze gotowe. Przejęcie liczy próbę — także przerwany proces wyczerpuje limit.
	 */
	public function claim(int $projectId): ?string
	{
		$now = $this->clock->now();
		$started = $now->format('Y-m-d H:i:s');
		$claimed = $this->db->execute(
			"UPDATE `{$this->table()}` SET refresh_status = %s, refresh_started_at = %s, refresh_lease_until = %s, refresh_attempts = LEAST(refresh_attempts + 1, 255)
			WHERE project_id = %d AND refresh_status = %s AND refresh_due_at <= %s",
			[self::STATUS_RUNNING, $started, $now->modify('+' . self::LEASE . ' seconds')->format('Y-m-d H:i:s'), $projectId, self::STATUS_QUEUED, $started],
		);

		return $claimed === 1 ? $started : null;
	}

	/**
	 * Przeliczenie poza kolejką (CLI) pod blokadą projektu: zadanie w każdym stanie staje się trwającym ze źródłem `cli` — oczekujące
	 * zlecenie sprzed startu zostanie nim wykonane. Zwraca znacznik przejęcia.
	 */
	public function start(int $projectId, string $source = self::SOURCE_CLI): string
	{
		$now = $this->clock->now();
		$started = $now->format('Y-m-d H:i:s');
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, updated_at, refresh_status, refresh_source, refresh_due_at, refresh_started_at, refresh_lease_until, refresh_attempts)
			VALUES (%d, %s, %s, %s, %s, %s, %s, 1)
			ON DUPLICATE KEY UPDATE refresh_status = VALUES(refresh_status), refresh_source = VALUES(refresh_source), refresh_due_at = VALUES(refresh_due_at),
				refresh_started_at = VALUES(refresh_started_at), refresh_lease_until = VALUES(refresh_lease_until), refresh_attempts = 1",
			[$projectId, $started, self::STATUS_RUNNING, $source, $started, $started, $now->modify('+' . self::LEASE . ' seconds')->format('Y-m-d H:i:s')],
		);

		return $started;
	}

	/**
	 * Zakończenie przejętego zadania (przeliczenie albo brak zmian): bezczynne — albo ponownie oczekujące teraz, gdy w trakcie złożono
	 * nowsze zlecenie ręczne. Zwraca nowy status (null, gdy zadania nie trzymał już ten proces).
	 */
	public function complete(int $projectId, string $token): ?string
	{
		$now = $this->now();
		$updated = $this->db->execute(
			"UPDATE `{$this->table()}` SET refresh_finished_at = %s, refresh_lease_until = NULL, refresh_attempts = 0, refresh_failed_key = NULL,
				refresh_seen_key = NULL, refresh_seen_at = NULL, refresh_dirty_since = NULL,
				refresh_source = IF(refresh_requested_at > refresh_started_at, %s, refresh_source),
				refresh_due_at = IF(refresh_requested_at > refresh_started_at, %s, NULL),
				refresh_status = IF(refresh_requested_at > refresh_started_at, %s, %s)
			WHERE project_id = %d AND refresh_status = %s AND refresh_started_at = %s",
			[$now, self::SOURCE_MANUAL, $now, self::STATUS_QUEUED, self::STATUS_IDLE, $projectId, self::STATUS_RUNNING, $token],
		);

		return $updated === 1 ? $this->status($projectId) : null;
	}

	/**
	 * Nieudana próba (wyjątek albo przerwany proces): ponowienie z odstępem 1 min → 5 min, po MAX_ATTEMPTS próbach `failed` z kluczem
	 * danych, na którym próby się wyczerpały (automatycznie dopiero po zmianie danych). Nowsze zlecenie ręczne → oczekujące teraz z nowym
	 * limitem prób. `$error` to wyłącznie kod (bez treści wyjątku). Zwraca nowy status (null, gdy zadania nie trzymał już ten proces).
	 */
	public function fail(int $projectId, string $token, string $error, ?string $dataKeyHex): ?string
	{
		$row = $this->db->fetchRow(
			"SELECT refresh_attempts FROM `{$this->table()}` WHERE project_id = %d AND refresh_status = %s AND refresh_started_at = %s",
			[$projectId, self::STATUS_RUNNING, $token],
		);

		if ($row === null) {
			return null;
		}

		$attempts = (int) $row['refresh_attempts'];
		$exhausted = $attempts >= self::MAX_ATTEMPTS;
		$now = $this->clock->now();
		$retryAt = $exhausted ? null : $now->modify('+' . self::BACKOFF[min(max(0, $attempts - 1), count(self::BACKOFF) - 1)] . ' seconds')->format('Y-m-d H:i:s');
		$nowSql = $now->format('Y-m-d H:i:s');
		$newer = 'refresh_requested_at > refresh_started_at';
		$updated = $this->db->execute(
			"UPDATE `{$this->table()}` SET refresh_error = %s, refresh_error_at = %s, refresh_lease_until = NULL,
				refresh_finished_at = IF({$newer} OR %d = 0, refresh_finished_at, %s),
				refresh_source = IF({$newer}, %s, refresh_source),
				refresh_failed_key = IF({$newer} OR %d = 0 OR %s = '', NULL, UNHEX(%s)),
				refresh_due_at = IF({$newer}, %s, NULLIF(%s, '')),
				refresh_status = IF({$newer} OR %d = 0, %s, %s),
				refresh_attempts = IF({$newer}, 0, refresh_attempts)
			WHERE project_id = %d AND refresh_status = %s AND refresh_started_at = %s",
			[
				substr($error, 0, 64), $nowSql,
				(int) $exhausted, $nowSql,
				self::SOURCE_MANUAL,
				(int) $exhausted, (string) $dataKeyHex, (string) $dataKeyHex,
				$nowSql, (string) $retryAt,
				(int) $exhausted, self::STATUS_QUEUED, self::STATUS_FAILED,
				$projectId, self::STATUS_RUNNING, $token,
			],
		);

		return $updated === 1 ? $this->status($projectId) : null;
	}

	/**
	 * Zadanie, którego nie da się wykonać (projekt bez obsługiwanego rynku albo usunięty) — `failed` bez ponowień i bez klucza danych.
	 */
	public function abandon(int $projectId, string $token, string $error): void
	{
		$now = $this->now();
		$this->db->execute(
			"UPDATE `{$this->table()}` SET refresh_status = %s, refresh_error = %s, refresh_error_at = %s, refresh_finished_at = %s, refresh_lease_until = NULL,
				refresh_due_at = NULL, refresh_failed_key = NULL
			WHERE project_id = %d AND refresh_status = %s AND refresh_started_at = %s",
			[self::STATUS_FAILED, substr($error, 0, 64), $now, $now, $now, $projectId, self::STATUS_RUNNING, $token],
		);
	}

	/** Zadanie projektu usuniętego albo zarchiwizowanego (a automatyczne — także wstrzymanego) — bez wykonania. */
	public function dismiss(int $projectId, string $error): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET refresh_status = %s, refresh_error = %s, refresh_error_at = %s, refresh_due_at = NULL
			WHERE project_id = %d AND refresh_status = %s",
			[self::STATUS_IDLE, substr($error, 0, 64), $this->now(), $projectId, self::STATUS_QUEUED],
		);
	}

	/** Projekt przelicza właśnie inny proces — zadanie wraca do kolejki z odstępem, bez liczenia próby. */
	public function defer(int $projectId, int $seconds = self::BUSY_DELAY): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET refresh_due_at = %s WHERE project_id = %d AND refresh_status = %s",
			[$this->clock->now()->modify('+' . max(1, $seconds) . ' seconds')->format('Y-m-d H:i:s'), $projectId, self::STATUS_QUEUED],
		);
	}

	/**
	 * Projekty z trwającym zadaniem (kandydaci do odzyskania — czy proces żyje, rozstrzyga blokada projektu).
	 *
	 * @return list<int>
	 */
	public function running(): array
	{
		return array_map('intval', array_column($this->db->fetchAll(
			"SELECT project_id FROM `{$this->table()}` WHERE refresh_status = %s ORDER BY project_id",
			[self::STATUS_RUNNING],
		), 'project_id'));
	}

	/**
	 * Wykrywanie zmian: nowy klucz danych zauważony teraz (od tej chwili liczy się okno ciszy), dane nieaktualne od pierwszego zauważenia.
	 */
	public function markSeen(int $projectId, string $dataKeyHex): void
	{
		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (project_id, updated_at, refresh_seen_key, refresh_seen_at, refresh_dirty_since) VALUES (%d, %s, UNHEX(%s), %s, %s)
			ON DUPLICATE KEY UPDATE refresh_dirty_since = COALESCE(refresh_dirty_since, VALUES(refresh_dirty_since)),
				refresh_seen_at = VALUES(refresh_seen_at), refresh_seen_key = VALUES(refresh_seen_key)",
			[$projectId, $now, $dataKeyHex, $now, $now],
		);
	}

	/** Dane znów zgodne z ostatnim przeliczeniem — bez oczekującego wykrycia. */
	public function clearSeen(int $projectId): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET refresh_seen_key = NULL, refresh_seen_at = NULL, refresh_dirty_since = NULL WHERE project_id = %d AND refresh_seen_key IS NOT NULL",
			[$projectId],
		);
	}

	/**
	 * Diagnostyka (CLI, panel administratora): liczba zadań według statusu, najstarsze oczekujące i przeliczenia przekraczające dzierżawę.
	 *
	 * @return array{counts: array<string, int>, oldest_due_at: ?string, overdue: list<int>}
	 */
	public function summary(): array
	{
		$counts = [self::STATUS_IDLE => 0, self::STATUS_QUEUED => 0, self::STATUS_RUNNING => 0, self::STATUS_FAILED => 0];

		foreach ($this->db->fetchAll("SELECT refresh_status, COUNT(*) AS c FROM `{$this->table()}` GROUP BY refresh_status") as $row) {
			$counts[(string) $row['refresh_status']] = (int) $row['c'];
		}

		return [
			'counts' => $counts,
			'oldest_due_at' => $this->db->fetchValue("SELECT MIN(refresh_due_at) FROM `{$this->table()}` WHERE refresh_status = %s", [self::STATUS_QUEUED]),
			'overdue' => array_map('intval', array_column($this->db->fetchAll(
				"SELECT project_id FROM `{$this->table()}` WHERE refresh_status = %s AND refresh_lease_until < %s ORDER BY project_id",
				[self::STATUS_RUNNING, $this->now()],
			), 'project_id')),
		];
	}

	/**
	 * Projekty z zadaniem albo zapisanym błędem (diagnostyka administratora) — bez treści błędów, tylko kody.
	 *
	 * @return list<array<string, string|null>>
	 */
	public function diagnosticRows(int $limit = 50): array
	{
		return $this->db->fetchAll(
			"SELECT p.public_id, p.name, s.refresh_status, s.refresh_source, s.refresh_due_at, s.refresh_started_at, s.refresh_lease_until,
				s.refresh_finished_at, s.refresh_attempts, s.refresh_error, s.refresh_error_at, s.refreshed_at, s.refresh_ms, s.refresh_requested_at,
				s.refresh_dirty_since
			FROM `{$this->table()}` s LEFT JOIN `{$this->db->table('projects')}` p ON p.id = s.project_id
			WHERE s.refresh_status <> %s OR s.refresh_error IS NOT NULL OR s.refresh_dirty_since IS NOT NULL
			ORDER BY FIELD(s.refresh_status, %s, %s, %s, %s), s.refresh_due_at, s.project_id LIMIT %d",
			[self::STATUS_IDLE, self::STATUS_RUNNING, self::STATUS_QUEUED, self::STATUS_FAILED, self::STATUS_IDLE, max(1, $limit)],
		);
	}

	public function status(int $projectId): ?string
	{
		return $this->db->fetchValue("SELECT refresh_status FROM `{$this->table()}` WHERE project_id = %d", [$projectId]);
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	private function table(): string
	{
		return $this->db->table('strategy_settings');
	}
}
