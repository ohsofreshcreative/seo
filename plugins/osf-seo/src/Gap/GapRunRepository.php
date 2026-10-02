<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Przebiegi importu (`gap_runs`) i ich domeny (`gap_run_targets`). Przebieg z URL-a szukamy zawsze po
 * (project_id, public_id) — identyfikator z innego projektu daje „nie znaleziono”. Domeny mają własny stan stronicowania,
 * więc przebieg jest wznawialny (żądanie po żądaniu) i nie powtarza opłaconej pracy. `active_project_id` (UNIQUE,
 * NULL po zakończeniu) gwarantuje w bazie jeden aktywny przebieg na projekt.
 */
final class GapRunRepository
{
	public const TARGET_PENDING = 'pending';

	public const TARGET_CACHED = 'cached';

	public const TARGET_RUNNING = 'running';

	public const TARGET_DONE = 'done';

	public const TARGET_PARTIAL = 'partial';

	public const TARGET_FAILED = 'failed';

	public const TARGET_CANCELLED = 'cancelled';

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	/**
	 * Nowy przebieg (`queued`) z domenami planu — zbiory z pamięci zapisane od razu jako `cached` (koszt 0).
	 *
	 * @param array<string, int> $domainIds domena → id zbioru
	 */
	public function create(int $projectId, GapPlan $plan, array $domainIds, string $trigger, ?int $userId): GapRun
	{
		$market = $plan->market ?? throw new \LogicException('Plan without market.');
		$now = $this->now();
		$publicId = Ulid::generate($this->clock->now());
		$coverage = $plan->request->coverage;

		$this->db->transaction(function () use ($projectId, $plan, $domainIds, $trigger, $userId, $now, $publicId, $market, $coverage): void {
			$runId = $this->db->insert($this->table(), [
				'public_id' => $publicId,
				'project_id' => $projectId,
				'provider' => $market->provider,
				'location_code' => $market->locationCode,
				'language_code' => $market->languageCode,
				'status' => GapRun::QUEUED,
				'trigger_type' => $trigger,
				'max_rank' => $coverage->maxRank,
				'min_volume' => $coverage->minVolume,
				'max_rows' => $coverage->maxRows,
				'forced' => $plan->request->force ? 1 : 0,
				'targets_planned' => count($plan->targets),
				'requests_planned' => $plan->requests(),
				'estimated_cost' => $plan->estimatedCost(),
				'active_project_id' => $projectId,
				'created_by' => $userId !== null && $userId > 0 ? $userId : null,
				'created_at' => $now,
				'updated_at' => $now,
			]);
			$done = 0;

			foreach ($plan->targets as $position => $target) {
				$cached = $target->state === PlannedTarget::CACHED;
				$done += $cached ? 1 : 0;
				$this->db->insert($this->targetsTable(), [
					'run_id' => $runId,
					'domain_id' => $domainIds[$target->domain] ?? throw new \LogicException('Missing gap domain.'),
					'role' => $target->role,
					'competitor_id' => $target->competitorId,
					'position' => $position,
					'status' => $cached ? self::TARGET_CACHED : self::TARGET_PENDING,
					'estimated_cost' => $target->maxCost,
					'finished_at' => $cached ? $now : null,
				]);
			}

			if ($done > 0) {
				$this->db->update($this->table(), ['targets_done' => $done], ['id' => $runId]);
			}
		});

		return $this->find($projectId, $publicId) ?? throw new \RuntimeException('Gap run was not created.');
	}

	public function find(int $projectId, string $publicId): ?GapRun
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE project_id = %d AND public_id = %s", [$projectId, $publicId]);

		return $row === null ? null : GapRun::fromRow($row);
	}

	public function findById(int $id): ?GapRun
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE id = %d", [$id]);

		return $row === null ? null : GapRun::fromRow($row);
	}

	/**
	 * @return list<GapRun>
	 */
	public function recent(int $projectId, int $limit = 10): array
	{
		return array_map(
			static fn (array $row): GapRun => GapRun::fromRow($row),
			$this->db->fetchAll("SELECT * FROM `{$this->table()}` WHERE project_id = %d ORDER BY id DESC LIMIT %d", [$projectId, max(1, $limit)]),
		);
	}

	public function active(int $projectId): ?GapRun
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE active_project_id = %d", [$projectId]);

		return $row === null ? null : GapRun::fromRow($row);
	}

	/**
	 * @return list<GapRun>
	 */
	public function activeRuns(int $limit = 20): array
	{
		return array_map(
			static fn (array $row): GapRun => GapRun::fromRow($row),
			$this->db->fetchAll("SELECT * FROM `{$this->table()}` WHERE active_project_id IS NOT NULL ORDER BY id LIMIT %d", [max(1, $limit)]),
		);
	}

	public function hasActive(): bool
	{
		return $this->db->fetchValue("SELECT 1 FROM `{$this->table()}` WHERE active_project_id IS NOT NULL LIMIT 1") !== null;
	}

	/**
	 * Domeny przebiegu z nazwą konkurenta i stanem zbioru (postęp, raport).
	 *
	 * @return list<array<string, string|null>>
	 */
	public function targets(int $runId): array
	{
		return $this->db->fetchAll(
			"SELECT t.*, d.domain, d.status AS domain_status, d.complete, d.total_count AS domain_total, c.public_id AS competitor_public_id, c.name AS competitor_name
			FROM `{$this->targetsTable()}` t
			JOIN `{$this->db->table('gap_domains')}` d ON d.id = t.domain_id
			LEFT JOIN `{$this->db->table('serp_competitors')}` c ON c.id = t.competitor_id
			WHERE t.run_id = %d ORDER BY t.position",
			[$runId],
		);
	}

	/**
	 * Kolejna domena do pobrania: oczekująca albo w toku, bez żądania w locie (proces mógł paść w trakcie — tę obsługuje
	 * `closeInterrupted()`, bez ponawiania) i bez trwającego importu tego zbioru przez inny przebieg.
	 *
	 * @return array<string, string|null>|null
	 */
	public function nextTarget(int $runId): ?array
	{
		return $this->db->fetchRow(
			"SELECT t.*, d.domain FROM `{$this->targetsTable()}` t
			JOIN `{$this->db->table('gap_domains')}` d ON d.id = t.domain_id
			WHERE t.run_id = %d AND t.status IN ('pending', 'running') AND t.inflight_task_id IS NULL
				AND (t.next_attempt_at IS NULL OR t.next_attempt_at <= %s)
				AND NOT (d.status = 'importing' AND d.import_run_id IS NOT NULL AND d.import_run_id <> %d)
			ORDER BY t.position LIMIT 1",
			[$runId, $this->now(), $runId],
		);
	}

	public function markStarted(int $runId): void
	{
		$now = $this->now();
		$this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'running', blocked_by = NULL, paused_at = NULL, started_at = COALESCE(started_at, %s), updated_at = %s
			WHERE id = %d AND status IN ('queued', 'running', 'paused')",
			[$now, $now, $runId],
		);
	}

	public function targetStarted(int $runId, int $domainId, int $taskId): void
	{
		$now = $this->now();
		$this->db->execute(
			"UPDATE `{$this->targetsTable()}` SET status = 'running', inflight_task_id = %d, started_at = COALESCE(started_at, %s) WHERE run_id = %d AND domain_id = %d",
			[$taskId, $now, $runId, $domainId],
		);
	}

	public function targetPage(int $runId, int $domainId, int $received, float $cost, int $nextOffset, int $totalCount, ?int $lastVolume, int $duplicates): void
	{
		$this->db->execute(
			"UPDATE `{$this->targetsTable()}` SET inflight_task_id = NULL, pages_done = pages_done + 1, rows_received = rows_received + %d,
				cost = cost + %f, next_offset = %d, total_count = %d, last_volume = " . ($lastVolume === null ? 'last_volume' : '%d') . ', attempts = 0,
				next_attempt_at = NULL, error_code = ' . ($duplicates > 0 ? "'duplicates'" : 'error_code') . '
			WHERE run_id = %d AND domain_id = %d',
			array_values(array_filter([$received, $cost, $nextOffset, $totalCount, $lastVolume, $runId, $domainId], static fn (mixed $value): bool => $value !== null)),
		);
	}

	/**
	 * @param array<string, int|float> $stats
	 */
	public function targetFinished(int $runId, int $domainId, string $status, int $rowsUnique, int $rowsNew, int $rowsLost, int $rowsChanged, array $stats, ?string $errorCode = null): void
	{
		$this->db->update($this->targetsTable(), [
			'status' => $status,
			'inflight_task_id' => null,
			'rows_unique' => $rowsUnique,
			'rows_new' => $rowsNew,
			'rows_lost' => $rowsLost,
			'rows_changed' => $rowsChanged,
			'stats' => (string) json_encode($stats),
			'error_code' => $errorCode,
			'finished_at' => $this->now(),
		], ['run_id' => $runId, 'domain_id' => $domainId]);
		$this->db->execute("UPDATE `{$this->table()}` SET targets_done = targets_done + 1, updated_at = %s WHERE id = %d", [$this->now(), $runId]);
	}

	/** Zbiór zaimportował w międzyczasie inny przebieg (świeży, z wystarczającym zakresem) — bez żądań. */
	public function targetCached(int $runId, int $domainId): void
	{
		$this->db->update($this->targetsTable(), ['status' => self::TARGET_CACHED, 'finished_at' => $this->now()], ['run_id' => $runId, 'domain_id' => $domainId]);
		$this->db->execute("UPDATE `{$this->table()}` SET targets_done = targets_done + 1, updated_at = %s WHERE id = %d", [$this->now(), $runId]);
	}

	public function targetRetry(int $runId, int $domainId, string $nextAttemptAt, string $errorCode): void
	{
		$this->db->execute(
			"UPDATE `{$this->targetsTable()}` SET inflight_task_id = NULL, attempts = attempts + 1, next_attempt_at = %s, error_code = %s WHERE run_id = %d AND domain_id = %d",
			[$nextAttemptAt, $errorCode, $runId, $domainId],
		);
	}

	/** Liczba fraz zapisanych w danym imporcie zbioru (wiersze z `seen_run_id` tego przebiegu). */
	public function addProgress(int $runId, int $requests, float $cost, int $rows): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET requests_done = requests_done + %d, cost = cost + %f, rows_received = rows_received + %d, updated_at = %s WHERE id = %d",
			[$requests, $cost, $rows, $this->now(), $runId],
		);
	}

	public function addFailure(int $runId, float $cost, string $errorCode, string $message): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET requests_done = requests_done + 1, cost = cost + %f, error_code = %s, updated_at = %s WHERE id = %d",
			[$cost, $errorCode, $this->now(), $runId],
		);
		$this->db->update($this->table(), ['error_message' => mb_substr($message, 0, 255)], ['id' => $runId]);
	}

	/** Wstrzymanie limitem kosztów albo pauzą konta — przebieg pozostaje aktywny i wznawia się, gdy to możliwe. */
	public function pause(int $runId, string $reason): void
	{
		$now = $this->now();
		$this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'paused', blocked_by = %s, paused_at = COALESCE(paused_at, %s), updated_at = %s WHERE id = %d AND active_project_id IS NOT NULL",
			[$reason, $now, $now, $runId],
		);
	}

	/**
	 * Zamknięcie przebiegu bez oczekujących domen: wszystkie pobrane lub z pamięci → `completed`, część niepełna → `partial`,
	 * żadna pobrana → `failed`.
	 */
	public function finishIfDone(int $runId): ?string
	{
		$counts = [];

		foreach ($this->db->fetchAll("SELECT status, COUNT(*) AS n FROM `{$this->targetsTable()}` WHERE run_id = %d GROUP BY status", [$runId]) as $row) {
			$counts[(string) $row['status']] = (int) $row['n'];
		}

		if (($counts[self::TARGET_PENDING] ?? 0) + ($counts[self::TARGET_RUNNING] ?? 0) > 0) {
			return null;
		}

		$ok = ($counts[self::TARGET_DONE] ?? 0) + ($counts[self::TARGET_CACHED] ?? 0);
		$incomplete = ($counts[self::TARGET_PARTIAL] ?? 0) + ($counts[self::TARGET_FAILED] ?? 0) + ($counts[self::TARGET_CANCELLED] ?? 0);
		$status = match (true) {
			$incomplete === 0 => GapRun::COMPLETED,
			$ok === 0 && ($counts[self::TARGET_PARTIAL] ?? 0) === 0 => GapRun::FAILED,
			default => GapRun::PARTIAL,
		};
		$now = $this->now();
		$changed = $this->db->execute(
			"UPDATE `{$this->table()}` SET status = %s, active_project_id = NULL, blocked_by = NULL, finished_at = %s, updated_at = %s
			WHERE id = %d AND active_project_id IS NOT NULL",
			[$status, $now, $now, $runId],
		);

		return $changed > 0 ? $status : null;
	}

	/** Anulowanie: oczekujące domeny nie zostaną pobrane; żądanie w locie (już opłacone) zostanie zapisane. */
	public function cancel(int $runId): bool
	{
		$now = $this->now();
		$changed = $this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'cancelled', active_project_id = NULL, finished_at = %s, updated_at = %s WHERE id = %d AND active_project_id IS NOT NULL",
			[$now, $now, $runId],
		);

		if ($changed > 0) {
			$this->db->execute(
				"UPDATE `{$this->targetsTable()}` SET status = 'cancelled', finished_at = %s WHERE run_id = %d AND status = 'pending' AND inflight_task_id IS NULL",
				[$now, $runId],
			);
		}

		return $changed > 0;
	}

	/**
	 * Domeny w toku przebiegu, który został anulowany albo zakończony z zewnątrz — do domknięcia importu zbioru.
	 *
	 * @return list<array<string, string|null>>
	 */
	public function openTargetsOfInactiveRuns(): array
	{
		return $this->db->fetchAll(
			"SELECT t.*, r.max_rank, r.min_volume, r.max_rows FROM `{$this->targetsTable()}` t JOIN `{$this->table()}` r ON r.id = t.run_id
			WHERE t.status = 'running' AND t.inflight_task_id IS NULL AND r.active_project_id IS NULL LIMIT 50",
		);
	}

	/**
	 * Żądania w locie starsze niż godzina (proces padł między wywołaniem a zapisem): bez ponawiania — dostawca mógł
	 * wykonać i opłacić żądanie. Zwraca domeny do domknięcia jako niepełne.
	 *
	 * @return list<array<string, string|null>>
	 */
	public function interrupted(): array
	{
		return $this->db->fetchAll(
			"SELECT t.*, m.estimated_cost AS task_estimate, r.max_rank, r.min_volume, r.max_rows FROM `{$this->targetsTable()}` t
			JOIN `{$this->table()}` r ON r.id = t.run_id
			LEFT JOIN `{$this->db->table('market_tasks')}` m ON m.id = t.inflight_task_id
			WHERE t.inflight_task_id IS NOT NULL AND (m.id IS NULL OR m.status <> 'pending' OR m.created_at < %s)",
			[$this->clock->now()->modify('-' . GapConfig::INTERRUPTED_HOURS . ' hour')->format('Y-m-d H:i:s')],
		);
	}

	/**
	 * Przebiegi wstrzymane dłużej niż 7 dni (limit kosztów nie pozwolił dokończyć).
	 *
	 * @return list<GapRun>
	 */
	public function expiredPaused(): array
	{
		return array_map(
			static fn (array $row): GapRun => GapRun::fromRow($row),
			$this->db->fetchAll(
				"SELECT * FROM `{$this->table()}` WHERE status = 'paused' AND active_project_id IS NOT NULL AND paused_at < %s",
				[$this->clock->now()->modify('-' . GapConfig::PAUSED_EXPIRE_DAYS . ' days')->format('Y-m-d H:i:s')],
			),
		);
	}

	/** Pozostałe oczekujące domeny przebiegu kończą się jako niepełne (wygaśnięcie, błąd konta trwający zbyt długo). */
	public function closePending(int $runId, string $errorCode): void
	{
		$this->db->execute(
			"UPDATE `{$this->targetsTable()}` SET status = IF(pages_done > 0, 'partial', 'cancelled'), error_code = %s, finished_at = %s
			WHERE run_id = %d AND status IN ('pending', 'running') AND inflight_task_id IS NULL",
			[$errorCode, $this->now(), $runId],
		);
	}

	/**
	 * Ostatni import każdego zbioru z listy (data, koszt) — „Ostatni import” w panelu.
	 *
	 * @param list<int> $domainIds
	 * @return array<int, array<string, string|null>>
	 */
	public function lastImports(array $domainIds): array
	{
		$domainIds = array_values(array_unique(array_map('intval', $domainIds)));

		if ($domainIds === []) {
			return [];
		}

		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT t.domain_id, t.status, t.finished_at, t.cost, t.rows_unique, t.total_count FROM `{$this->targetsTable()}` t
			JOIN (SELECT domain_id, MAX(finished_at) AS last FROM `{$this->targetsTable()}` WHERE domain_id IN (" . Connection::placeholders($domainIds, '%d') . ")
				AND status IN ('done', 'partial') GROUP BY domain_id) x ON x.domain_id = t.domain_id AND x.last = t.finished_at",
			$domainIds,
		) as $row) {
			$result[(int) $row['domain_id']] = $row;
		}

		return $result;
	}

	private function table(): string
	{
		return $this->db->table('gap_runs');
	}

	private function targetsTable(): string
	{
		return $this->db->table('gap_run_targets');
	}
}
