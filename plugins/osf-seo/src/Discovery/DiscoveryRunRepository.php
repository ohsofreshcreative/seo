<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Przebiegi wyszukiwania (`discovery_runs`) i ich seedy (`discovery_run_seeds`). Przebieg z URL-a szukamy zawsze po
 * (project_id, public_id) — identyfikator z innego projektu daje „nie znaleziono”. Seedy mają własny stan, więc
 * przebieg jest wznawialny w tle (żądanie po żądaniu) i nie powtarza opłaconej pracy.
 */
final class DiscoveryRunRepository
{
	public const SEED_PENDING = 'pending';

	public const SEED_RUNNING = 'running';

	public const SEED_DONE = 'done';

	public const SEED_CACHED = 'cached';

	public const SEED_FAILED = 'failed';

	public const SEED_CANCELLED = 'cancelled';

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
	 * Nowy przebieg (stan `queued`) z seedami planu — seedy z cache zapisane jako `cached` (koszt 0).
	 */
	public function create(int $projectId, Market $market, DiscoveryPlan $plan, string $trigger, ?int $userId): DiscoveryRun
	{
		$now = $this->now();
		$request = $plan->request;
		$publicId = Ulid::generate($this->clock->now());

		$this->db->transaction(function () use ($projectId, $market, $plan, $request, $trigger, $userId, $now, $publicId): void {
			$runId = $this->db->insert($this->table(), [
				'public_id' => $publicId,
				'project_id' => $projectId,
				'provider' => $market->provider,
				'location_code' => $market->locationCode,
				'language_code' => $market->languageCode,
				'method' => $request->method->value,
				'depth' => $request->depth,
				'status' => DiscoveryRun::QUEUED,
				'trigger_type' => $trigger,
				'seeds_count' => count($plan->seeds),
				'max_candidates' => $request->maxCandidates,
				'seed_limit' => $plan->seedLimit,
				'min_volume' => $request->minVolume,
				'max_difficulty' => $request->maxDifficulty,
				'forced' => $request->force ? 1 : 0,
				'options' => (string) json_encode(['skip_other_language' => $request->skipOtherLanguage]),
				'tasks_planned' => $plan->requests(),
				'estimated_cost' => $plan->estimatedCost(),
				'rejected' => (string) json_encode((object) []),
				'created_by' => $userId !== null && $userId > 0 ? $userId : null,
				'created_at' => $now,
				'updated_at' => $now,
			]);

			$insert = new BulkInsert(
				$this->db,
				$this->seedsTable(),
				['run_id', 'seed_key', 'seed', 'source', 'position', 'status', 'finished_at'],
				['%d', 'UNHEX(%s)', '%s', '%s', '%d', '%s', "NULLIF(%s, '')"],
			);

			foreach ($plan->seeds as $position => $seed) {
				$insert->add([
					$runId,
					bin2hex(MarketKeyword::key($seed->seed)),
					mb_substr($seed->seed, 0, 255, 'UTF-8'),
					$seed->source,
					$position,
					$seed->isCached() ? self::SEED_CACHED : self::SEED_PENDING,
					$seed->isCached() ? $now : '',
				]);
			}

			$insert->flush();
		});

		return $this->find($projectId, $publicId) ?? throw new \RuntimeException('Discovery run was not saved.');
	}

	public function find(int $projectId, string $publicId): ?DiscoveryRun
	{
		$normalized = Ulid::normalize($publicId);

		if ($normalized === null) {
			return null;
		}

		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE project_id = %d AND public_id = %s", [$projectId, $normalized]);

		return $row === null ? null : DiscoveryRun::fromRow($row);
	}

	public function findById(int $id): ?DiscoveryRun
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE id = %d", [$id]);

		return $row === null ? null : DiscoveryRun::fromRow($row);
	}

	/**
	 * @return list<DiscoveryRun>
	 */
	public function recent(int $projectId, int $limit = 10): array
	{
		return array_map(
			static fn (array $row): DiscoveryRun => DiscoveryRun::fromRow($row),
			$this->db->fetchAll("SELECT * FROM `{$this->table()}` WHERE project_id = %d ORDER BY id DESC LIMIT %d", [$projectId, max(1, $limit)]),
		);
	}

	/** Aktywny (w kolejce lub w trakcie) przebieg projektu — najwyżej jeden naraz. */
	public function active(int $projectId): ?DiscoveryRun
	{
		$row = $this->db->fetchRow(
			"SELECT * FROM `{$this->table()}` WHERE project_id = %d AND status IN ('queued', 'running') ORDER BY id LIMIT 1",
			[$projectId],
		);

		return $row === null ? null : DiscoveryRun::fromRow($row);
	}

	/**
	 * Aktywne przebiegi wszystkich projektów (najstarsze najpierw) — kolejka przetwarzania w tle.
	 *
	 * @return list<DiscoveryRun>
	 */
	public function activeRuns(int $limit = 10): array
	{
		return array_map(
			static fn (array $row): DiscoveryRun => DiscoveryRun::fromRow($row),
			$this->db->fetchAll("SELECT * FROM `{$this->table()}` WHERE status IN ('queued', 'running') ORDER BY id LIMIT %d", [max(1, $limit)]),
		);
	}

	public function hasActive(): bool
	{
		return $this->db->fetchValue("SELECT 1 FROM `{$this->table()}` WHERE status IN ('queued', 'running') LIMIT 1") === '1';
	}

	/**
	 * Seedy przebiegu w kolejności planu.
	 *
	 * @return list<array<string, string|null>>
	 */
	public function seeds(int $runId): array
	{
		return $this->db->fetchAll(
			"SELECT LOWER(HEX(seed_key)) AS seed_hex, seed, source, position, status, pages_done, next_offset, items, candidates_new, cost, task_id,
				attempts, error_code, next_attempt_at, started_at, finished_at
			FROM `{$this->seedsTable()}` WHERE run_id = %d ORDER BY position",
			[$runId],
		);
	}

	/**
	 * Następny seed do pobrania (oczekujący, bez odłożonej próby).
	 *
	 * @return array<string, string|null>|null
	 */
	public function nextSeed(int $runId): ?array
	{
		// Tylko seed aktywnego przebiegu — anulowanie w trakcie żądania nie może dopuścić kolejnej płatnej strony.
		return $this->db->fetchRow(
			"SELECT LOWER(HEX(s.seed_key)) AS seed_hex, s.seed, s.source, s.position, s.status, s.pages_done, s.next_offset, s.items, s.candidates_new, s.cost, s.attempts
			FROM `{$this->seedsTable()}` s
			JOIN `{$this->table()}` r ON r.id = s.run_id AND r.status IN ('queued', 'running')
			WHERE s.run_id = %d AND s.status = 'pending' AND (s.next_attempt_at IS NULL OR s.next_attempt_at <= %s)
			ORDER BY s.position LIMIT 1",
			[$runId, $this->now()],
		);
	}

	public function markStarted(int $runId): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'running', started_at = COALESCE(started_at, %s), updated_at = %s WHERE id = %d AND status = 'queued'",
			[$this->now(), $this->now(), $runId],
		);
	}

	/** Seed w trakcie żądania (zadanie w rejestrze kosztów utworzone przed wywołaniem). */
	public function seedStarted(int $runId, string $seedHex, int $taskId): void
	{
		$this->db->execute(
			"UPDATE `{$this->seedsTable()}` SET status = 'running', task_id = %d, attempts = attempts + 1, started_at = COALESCE(started_at, %s), next_attempt_at = NULL
			WHERE run_id = %d AND seed_key = UNHEX(%s)",
			[$taskId, $this->now(), $runId, $seedHex],
		);
	}

	/** Strona wyników zapisana; seed kończy się albo czeka na kolejną stronę. */
	public function seedPage(int $runId, string $seedHex, int $items, int $newCandidates, float $cost, int $nextOffset, bool $finished): void
	{
		$this->db->execute(
			"UPDATE `{$this->seedsTable()}` SET status = %s, pages_done = pages_done + 1, next_offset = %d, items = items + %d,
				candidates_new = candidates_new + %d, cost = cost + %f, error_code = NULL, finished_at = NULLIF(%s, '')
			WHERE run_id = %d AND seed_key = UNHEX(%s)",
			[$finished ? self::SEED_DONE : self::SEED_PENDING, $nextOffset, $items, $newCandidates, $cost, $finished ? $this->now() : '', $runId, $seedHex],
		);
	}

	public function seedFailed(int $runId, string $seedHex, string $errorCode, float $cost = 0.0): void
	{
		$this->db->execute(
			"UPDATE `{$this->seedsTable()}` SET status = 'failed', error_code = %s, cost = cost + %f, finished_at = %s WHERE run_id = %d AND seed_key = UNHEX(%s)",
			[$errorCode, $cost, $this->now(), $runId, $seedHex],
		);
	}

	/** Seed wraca do kolejki (limit żądań dostawcy, wstrzymanie po błędzie konta) — bez kosztu. */
	public function seedRetry(int $runId, string $seedHex, string $nextAttemptAt, string $errorCode): void
	{
		$this->db->execute(
			"UPDATE `{$this->seedsTable()}` SET status = 'pending', error_code = %s, next_attempt_at = %s WHERE run_id = %d AND seed_key = UNHEX(%s)",
			[$errorCode, $nextAttemptAt, $runId, $seedHex],
		);
	}

	/**
	 * Liczniki przebiegu po żądaniu.
	 *
	 * @param array<string, int> $rejected
	 */
	public function addProgress(int $runId, int $tasks, float $cost, int $items, int $new, int $seen, array $rejected): void
	{
		$row = $this->db->fetchRow("SELECT rejected FROM `{$this->table()}` WHERE id = %d", [$runId]);
		$current = json_decode((string) ($row['rejected'] ?? ''), true);
		$current = is_array($current) ? $current : [];

		foreach ($rejected as $reason => $count) {
			$current[$reason] = (int) ($current[$reason] ?? 0) + $count;
		}

		$this->db->execute(
			"UPDATE `{$this->table()}` SET tasks_done = tasks_done + %d, cost = cost + %f, items_received = items_received + %d,
				candidates_new = candidates_new + %d, candidates_seen = candidates_seen + %d, rejected = %s, blocked_by = NULL, updated_at = %s
			WHERE id = %d",
			[$tasks, $cost, $items, $new, $seen, (string) json_encode((object) $current), $this->now(), $runId],
		);
	}

	/** Koszt żądania zakończonego błędem (gdy dostawca mógł je opłacić). */
	public function addFailedTask(int $runId, float $cost, string $errorCode, string $message): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET tasks_done = tasks_done + 1, cost = cost + %f, error_code = %s, updated_at = %s WHERE id = %d",
			[$cost, $errorCode, $this->now(), $runId],
		);
		// Komunikat dostawcy może zawierać znaki spoza ASCII — zapis z kontrolą zestawu znaków kolumny.
		$this->db->update($this->table(), ['error_message' => mb_substr($message, 0, 250)], ['id' => $runId]);
	}

	/** Przebieg czeka (limit kosztów, wstrzymanie po błędzie konta) — zostaje aktywny i wznowi się w ramach limitów. */
	public function block(int $runId, string $reason): void
	{
		$this->db->execute("UPDATE `{$this->table()}` SET blocked_by = %s, updated_at = %s WHERE id = %d", [$reason, $this->now(), $runId]);
	}

	/**
	 * Zamknięcie przebiegu, gdy nie ma już seedów do pobrania: wszystkie pobrane/z cache → `completed`,
	 * część błędów → `partial`, same błędy → `failed`. Zwraca nowy status albo null (przebieg trwa).
	 */
	public function finishIfDone(int $runId): ?string
	{
		$counts = [];

		foreach ($this->db->fetchAll("SELECT status, COUNT(*) AS n FROM `{$this->seedsTable()}` WHERE run_id = %d GROUP BY status", [$runId]) as $row) {
			$counts[(string) $row['status']] = (int) $row['n'];
		}

		if (($counts[self::SEED_PENDING] ?? 0) > 0 || ($counts[self::SEED_RUNNING] ?? 0) > 0) {
			return null;
		}

		$ok = ($counts[self::SEED_DONE] ?? 0) + ($counts[self::SEED_CACHED] ?? 0);
		$failed = $counts[self::SEED_FAILED] ?? 0;
		$status = match (true) {
			$failed === 0 => DiscoveryRun::COMPLETED,
			$ok > 0 => DiscoveryRun::PARTIAL,
			default => DiscoveryRun::FAILED,
		};

		$updated = $this->db->execute(
			"UPDATE `{$this->table()}` SET status = %s, blocked_by = NULL, finished_at = %s, updated_at = %s WHERE id = %d AND status IN ('queued', 'running')",
			[$status, $this->now(), $this->now(), $runId],
		);

		return $updated > 0 ? $status : null;
	}

	/** Anulowanie: przebieg i seedy oczekujące; wykonane (opłacone) żądania zostają w rejestrze kosztów. */
	public function cancel(int $runId): bool
	{
		return $this->db->transaction(function () use ($runId): bool {
			$updated = $this->db->execute(
				"UPDATE `{$this->table()}` SET status = 'cancelled', finished_at = %s, updated_at = %s WHERE id = %d AND status IN ('queued', 'running')",
				[$this->now(), $this->now(), $runId],
			);

			if ($updated > 0) {
				$this->cancelPendingSeeds($runId);
			}

			return $updated > 0;
		});
	}

	/** Seedy oczekujące przebiegu (także te, które wróciły do kolejki po żądaniu trwającym w chwili anulowania). */
	public function cancelPendingSeeds(int $runId): void
	{
		$this->db->execute("UPDATE `{$this->seedsTable()}` SET status = 'cancelled', finished_at = %s WHERE run_id = %d AND status = 'pending'", [$this->now(), $runId]);
	}

	/**
	 * Seed „w trakcie” od ponad godziny: proces padł w trakcie żądania (mogło zostać opłacone) — kończymy go błędem
	 * bez ponawiania, żeby nie zapłacić drugi raz. Zwraca liczbę zamkniętych seedów.
	 */
	public function closeInterrupted(): int
	{
		$before = $this->clock->now()->modify('-' . DiscoveryConfig::INTERRUPTED_HOURS . ' hour')->format('Y-m-d H:i:s');

		return $this->db->execute(
			"UPDATE `{$this->seedsTable()}` SET status = 'failed', error_code = 'interrupted', finished_at = %s WHERE status = 'running' AND started_at < %s",
			[$this->now(), $before],
		);
	}

	/**
	 * Seedy pobrane w projekcie w ciągu TTL z co najmniej tak szerokimi parametrami (ta sama metoda i rynek, głębokość
	 * ≥, limit na seed ≥, min. wolumen ≤, maks. trudność ≥ lub brak) — nie są pobierane ponownie (bez `force`).
	 *
	 * @param list<string> $seeds postacie znormalizowane
	 * @return array<string, string> seed → data ostatniego pobrania
	 */
	public function cachedSeeds(int $projectId, Market $market, DiscoveryRequest $request, int $seedLimit, string $since, array $seeds): array
	{
		if ($seeds === []) {
			return [];
		}

		$bySeed = [];

		foreach ($seeds as $seed) {
			$bySeed[bin2hex(MarketKeyword::key($seed))] = $seed;
		}

		$params = [$projectId, $market->provider, $market->locationCode, $market->languageCode, $request->method->value, $request->depth ?? 0, $seedLimit, $request->minVolume];
		$difficulty = '';

		if ($request->maxDifficulty !== null) {
			$difficulty = ' AND (r.max_difficulty IS NULL OR r.max_difficulty >= %d)';
			$params[] = $request->maxDifficulty;
		} else {
			$difficulty = ' AND r.max_difficulty IS NULL';
		}

		$rows = $this->db->fetchAll(
			"SELECT LOWER(HEX(s.seed_key)) AS h, MAX(s.finished_at) AS fetched_at
			FROM `{$this->table()}` r
			JOIN `{$this->seedsTable()}` s ON s.run_id = r.id
			WHERE r.project_id = %d AND r.provider = %s AND r.location_code = %d AND r.language_code = %s AND r.method = %s
				AND COALESCE(r.depth, 0) >= %d AND r.seed_limit >= %d AND r.min_volume <= %d{$difficulty}
				AND s.status = 'done' AND s.finished_at >= %s AND s.seed_key IN (" . Connection::placeholders(array_keys($bySeed), 'UNHEX(%s)') . ')
			GROUP BY s.seed_key',
			[...$params, $since, ...array_keys($bySeed)],
		);

		$cached = [];

		foreach ($rows as $row) {
			if (isset($bySeed[(string) $row['h']])) {
				$cached[$bySeed[(string) $row['h']]] = (string) $row['fetched_at'];
			}
		}

		return $cached;
	}

	private function table(): string
	{
		return $this->db->table('discovery_runs');
	}

	private function seedsTable(): string
	{
		return $this->db->table('discovery_run_seeds');
	}
}
