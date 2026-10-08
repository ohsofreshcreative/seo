<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Run;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;
use RuntimeException;

/**
 * Historia uruchomień AI (`ai_runs` + `ai_run_payloads`) — jednocześnie rejestr kosztów AI (całkowicie oddzielny od `market_tasks`).
 * Każdy odczyt i zmiana po identyfikatorze publicznym jest zawężona do projektu (brak IDOR przez identyfikator analizy).
 * Dane Strategii i workflow nigdy nie są tu zmieniane — historia tylko się do nich odwołuje (temat, odcisk dowodów).
 */
final class AiRunRepository
{
	/** Surowa odpowiedź modelu w historii — najwyżej tyle bajtów (dłuższa jest ucinana na granicy znaku i oznaczana). */
	public const OUTPUT_RAW_MAX_BYTES = 65536;

	private const BINARY = ['context_fingerprint', 'evidence_fingerprint', 'evidence_hash', 'plan_fingerprint'];

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
	 * Nowe uruchomienie (status `reserved`) z zapisanym wejściem — w transakcji wywołującego (rezerwacja pod blokadą budżetu).
	 *
	 * @param array<string, int|float|string|null> $data kolumny `ai_runs` (odciski jako hex)
	 */
	public function create(array $data, string $input, string $inputHash, string $status = AiRun::STATUS_RESERVED): AiRun
	{
		$publicId = Ulid::generate($this->clock->now());
		$row = ['public_id' => $publicId, 'status' => $status, 'created_at' => $this->now()] + $data;
		$this->insertRow($this->table(), $row);
		$run = $this->findById((int) $row['project_id'], $publicId) ?? throw new RuntimeException('AI run was not stored.');
		$this->insertRow($this->payloads(), [
			'run_id' => $run->id,
			'project_id' => $run->projectId,
			'input' => $input,
			'input_hash' => $inputHash,
		]);

		return $run;
	}

	/** `reserved` / `queued` → `running` (warunkowo — zapobiega podwójnemu wysłaniu tego samego uruchomienia). */
	public function markRunning(AiRun $run, string $from = AiRun::STATUS_RESERVED): bool
	{
		return $this->db->execute(
			"UPDATE `{$this->table()}` SET status = %s, started_at = %s WHERE id = %d AND status = %s",
			[AiRun::STATUS_RUNNING, $this->now(), $run->id, $from],
		) === 1;
	}

	/**
	 * Zlecenia z panelu czekające na krok w tle (wszystkie projekty) — najstarsze pierwsze.
	 *
	 * @return list<AiRun>
	 */
	public function queued(int $limit): array
	{
		return array_map(AiRun::fromRow(...), $this->db->fetchAll(
			"{$this->select()} WHERE r.status = %s ORDER BY r.created_at, r.id LIMIT %d",
			[AiRun::STATUS_QUEUED, max(1, $limit)],
		));
	}

	/**
	 * Zlecenia w kolejce starsze niż chwila (nieodebrane przez krok w tle).
	 *
	 * @return list<AiRun>
	 */
	public function staleQueued(string $before): array
	{
		return array_map(AiRun::fromRow(...), $this->db->fetchAll(
			"{$this->select()} WHERE r.status = %s AND r.created_at < %s ORDER BY r.id LIMIT 100",
			[AiRun::STATUS_QUEUED, $before],
		));
	}

	/** Uruchomienie po identyfikatorze wewnętrznym (krok w tle, ponowny odczyt stanu). */
	public function reload(AiRun $run): ?AiRun
	{
		return $this->findById($run->projectId, $run->publicId);
	}

	/**
	 * Zakończenie uruchomienia (status, tokeny, koszt, błąd) — warunkowo, tylko ze statusu `$from` (proces w toku i odzyskiwanie
	 * porzuconych uruchomień nie nadpiszą się nawzajem). Null = uruchomienie zakończył już ktoś inny.
	 *
	 * @param array<string, int|float|string|null> $data
	 */
	public function finish(AiRun $run, array $data, string $from = AiRun::STATUS_RUNNING): ?AiRun
	{
		$data['finished_at'] ??= $this->now();

		if ($this->db->update($this->table(), $data, ['id' => $run->id, 'status' => $from]) !== 1) {
			return null;
		}

		return $this->findById($run->projectId, $run->publicId);
	}

	/**
	 * Odpowiedź i wynik walidacji (surowa odpowiedź ograniczona do OUTPUT_RAW_MAX_BYTES).
	 *
	 * @param array<string, mixed>|null $result zwalidowany wynik
	 * @param list<array{path: string, code: string}> $validation
	 */
	public function storeOutput(AiRun $run, ?string $raw, ?array $result, array $validation): void
	{
		$this->db->update($this->payloads(), [
			'output_raw' => $raw === null ? null : self::limit($raw, self::OUTPUT_RAW_MAX_BYTES),
			'result' => $result === null ? null : (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'validation' => (string) json_encode($validation, JSON_UNESCAPED_SLASHES),
		], ['run_id' => $run->id]);
	}

	/**
	 * Blokada generowania tego samego zlecenia (projekt × temat × typ) — bez czekania: drugi równoległy proces dostaje odmowę.
	 */
	public function acquireGenerationLock(int $projectId, int $topicId, string $task): bool
	{
		return $this->db->acquireLock(self::generationLock($projectId, $topicId, $task), 0);
	}

	public function releaseGenerationLock(int $projectId, int $topicId, string $task): void
	{
		$this->db->releaseLock(self::generationLock($projectId, $topicId, $task));
	}

	/** Uruchomienie w toku (rezerwacja albo żądanie) tego samego tematu i typu analizy. */
	public function activeFor(int $projectId, int $topicId, string $task): ?AiRun
	{
		$row = $this->db->fetchRow(
			"{$this->select()} WHERE r.project_id = %d AND r.topic_id = %d AND r.task = %s AND r.status IN (%s, %s, %s) ORDER BY r.id DESC LIMIT 1",
			[$projectId, $topicId, $task, ...AiRun::ACTIVE],
		);

		return $row === null ? null : AiRun::fromRow($row);
	}

	/** Udane uruchomienie z tym samym odciskiem planu (ten sam kontekst, model, wersje i koszt) — ochrona przed przypadkowym powtórzeniem. */
	public function succeededWithPlan(int $projectId, string $planFingerprint): ?AiRun
	{
		$row = $this->db->fetchRow(
			"{$this->select()} WHERE r.project_id = %d AND r.plan_fingerprint = UNHEX(%s) AND r.status = %s ORDER BY r.id DESC LIMIT 1",
			[$projectId, $planFingerprint, AiRun::STATUS_SUCCEEDED],
		);

		return $row === null ? null : AiRun::fromRow($row);
	}

	/** Uruchomienie projektu po identyfikatorze publicznym (inny projekt → null). */
	public function find(int $projectId, string $publicId): ?AiRun
	{
		$publicId = Ulid::normalize($publicId);

		return $publicId === null ? null : $this->findById($projectId, $publicId);
	}

	/**
	 * Dane uruchomienia (wejście, odpowiedź, wynik, walidacja) — tylko w obrębie projektu.
	 *
	 * @return array{input: ?string, output_raw: ?string, result: ?array<string, mixed>, validation: list<array<string, string>>, input_hash: ?string}|null
	 */
	public function payload(AiRun $run): ?array
	{
		$row = $this->db->fetchRow(
			"SELECT input, output_raw, result, validation, HEX(input_hash) AS input_hash FROM `{$this->payloads()}` WHERE run_id = %d AND project_id = %d",
			[$run->id, $run->projectId],
		);

		if ($row === null) {
			return null;
		}

		$result = $row['result'] === null ? null : json_decode($row['result'], true);
		$validation = $row['validation'] === null ? [] : json_decode($row['validation'], true);

		return [
			'input' => $row['input'],
			'output_raw' => $row['output_raw'],
			'result' => is_array($result) ? $result : null,
			'validation' => is_array($validation) ? array_values($validation) : [],
			'input_hash' => $row['input_hash'] === null ? null : strtolower($row['input_hash']),
		];
	}

	/**
	 * Historia projektu (opcjonalnie tematu), od najnowszych — same metadane.
	 *
	 * @return list<AiRun>
	 */
	public function list(int $projectId, ?int $topicId = null, int $limit = 20, int $offset = 0): array
	{
		$where = 'r.project_id = %d';
		$params = [$projectId];

		if ($topicId !== null) {
			$where .= ' AND r.topic_id = %d';
			$params[] = $topicId;
		}

		$params[] = max(1, min(200, $limit));
		$params[] = max(0, $offset);

		return array_map(AiRun::fromRow(...), $this->db->fetchAll(
			"{$this->select()} WHERE {$where} ORDER BY r.created_at DESC, r.id DESC LIMIT %d OFFSET %d",
			$params,
		));
	}

	/**
	 * Historia analiz w panelu (faza D): jedno zapytanie na stronę (filtry i stronicowanie w SQL) z tanim wskaźnikiem zmian Strategii —
	 * bieżący odcisk dowodów tematu (`strategy_topics.evidence_hash`) obok zapisanego w uruchomieniu (bez odbudowy kontekstu).
	 * `$restricted` (bez uprawnienia AI): wyłącznie gotowe analizy (zwalidowane), nieodrzucone, tematów aktywnych w widoku klienta.
	 *
	 * @param array{type?: ?string, status?: ?string, topic_id?: ?int} $filters
	 * @return array{rows: list<array{run: AiRun, topic_status: ?string, topic_evidence_hash: ?string}>, total: int}
	 */
	public function history(int $projectId, array $filters, bool $restricted, int $limit, int $offset): array
	{
		$where = ['r.project_id = %d'];
		$params = [$projectId];

		if (is_string($filters['type'] ?? null) && $filters['type'] !== '') {
			$where[] = 'r.task = %s';
			$params[] = $filters['type'];
		}

		if (is_int($filters['topic_id'] ?? null)) {
			$where[] = 'r.topic_id = %d';
			$params[] = $filters['topic_id'];
		}

		$statuses = match ($filters['status'] ?? null) {
			'ready' => [AiRun::STATUS_SUCCEEDED],
			'active' => AiRun::ACTIVE,
			'problem' => [AiRun::STATUS_FAILED, AiRun::STATUS_INVALID, AiRun::STATUS_UNCERTAIN],
			default => [],
		};

		if ($restricted) {
			$statuses = array_values(array_intersect($statuses === [] ? [AiRun::STATUS_SUCCEEDED] : $statuses, [AiRun::STATUS_SUCCEEDED]));
			$where[] = "(r.decision IS NULL OR r.decision <> 'rejected') AND t.id IS NOT NULL AND t.status <> 'dismissed'";
		}

		if ($restricted && $statuses === []) {
			return ['rows' => [], 'total' => 0];
		}

		if ($statuses !== []) {
			$where[] = 'r.status IN (' . Connection::placeholders($statuses) . ')';
			array_push($params, ...$statuses);
		}

		$params[] = max(1, min(100, $limit));
		$params[] = max(0, $offset);
		$rows = $this->db->fetchAll(
			"SELECT r.*, t.public_id AS topic_public_id, t.label AS topic_label, t.status AS topic_status, LOWER(HEX(t.evidence_hash)) AS topic_evidence_hash,
				p.public_id AS project_public_id, COUNT(*) OVER () AS total_rows
			FROM `{$this->table()}` r
			LEFT JOIN `{$this->db->table('strategy_topics')}` t ON t.id = r.topic_id AND t.project_id = r.project_id
			LEFT JOIN `{$this->db->table('projects')}` p ON p.id = r.project_id
			WHERE " . implode(' AND ', $where) . ' ORDER BY r.created_at DESC, r.id DESC LIMIT %d OFFSET %d',
			$params,
		);

		return [
			'rows' => array_map(static fn (array $row): array => [
				'run' => AiRun::fromRow($row),
				'topic_status' => $row['topic_status'],
				'topic_evidence_hash' => $row['topic_evidence_hash'],
			], $rows),
			'total' => $rows === [] ? 0 : (int) $rows[0]['total_rows'],
		];
	}

	/**
	 * Koszt płatnych uruchomień od chwili (UTC): rozliczony, a bez rozliczenia — rezerwacja (w toku i niepewne).
	 */
	public function spentSince(string $since, ?int $projectId = null): float
	{
		$sql = "SELECT COALESCE(SUM(COALESCE(actual_cost, reserved_cost)), 0) FROM `{$this->table()}` WHERE paid = 1 AND created_at >= %s";
		$params = [$since];

		if ($projectId !== null) {
			$sql .= ' AND project_id = %d';
			$params[] = $projectId;
		}

		return round((float) $this->db->fetchValue($sql, $params), 6);
	}

	/**
	 * Uruchomienia w toku starsze niż chwila (porzucone przez przerwany proces).
	 *
	 * @return list<AiRun>
	 */
	public function staleActive(string $before): array
	{
		return array_map(AiRun::fromRow(...), $this->db->fetchAll(
			"{$this->select()} WHERE r.status IN (%s, %s) AND COALESCE(r.started_at, r.created_at) < %s ORDER BY r.id LIMIT 100",
			[AiRun::STATUS_RESERVED, AiRun::STATUS_RUNNING, $before],
		));
	}

	/** Decyzja użytkownika o wyniku (osobno od wyniku; null = cofnięcie decyzji). */
	public function decide(AiRun $run, ?string $decision, ?int $userId): AiRun
	{
		$this->db->update($this->table(), [
			'decision' => $decision,
			'decided_by' => $decision === null ? null : $userId,
			'decided_at' => $decision === null ? null : $this->now(),
		], ['id' => $run->id]);

		return $this->findById($run->projectId, $run->publicId) ?? throw new RuntimeException('AI run disappeared.');
	}

	/** Usunięcie uruchomienia z danymi (nie usuwa uruchomień w toku — ich koszt jeszcze nie jest rozliczony). */
	public function delete(AiRun $run): bool
	{
		if ($run->isActive()) {
			return false;
		}

		return $this->db->transaction(function () use ($run): bool {
			$this->db->execute("DELETE FROM `{$this->payloads()}` WHERE run_id = %d AND project_id = %d", [$run->id, $run->projectId]);

			return $this->db->execute("DELETE FROM `{$this->table()}` WHERE id = %d AND project_id = %d", [$run->id, $run->projectId]) === 1;
		});
	}

	/**
	 * Retencja: zakończone uruchomienia starsze niż chwila (paczkami). Uruchomienia w toku zostają.
	 *
	 * @return int liczba usuniętych uruchomień
	 */
	public function purgeBefore(string $before, int $batch = 500): int
	{
		$deleted = 0;

		do {
			$ids = array_map('intval', array_column($this->db->fetchAll(
				"SELECT id FROM `{$this->table()}` WHERE created_at < %s AND status NOT IN (%s, %s, %s) ORDER BY id LIMIT %d",
				[$before, ...AiRun::ACTIVE, $batch],
			), 'id'));

			if ($ids === []) {
				break;
			}

			$this->db->transaction(function () use ($ids): void {
				$this->db->execute("DELETE FROM `{$this->payloads()}` WHERE run_id IN (" . Connection::placeholders($ids, '%d') . ')', $ids);
				$this->db->execute("DELETE FROM `{$this->table()}` WHERE id IN (" . Connection::placeholders($ids, '%d') . ')', $ids);
			});
			$deleted += count($ids);
		} while (count($ids) === $batch);

		return $deleted;
	}

	/**
	 * Liczniki statusów (diagnostyka).
	 *
	 * @return array<string, int>
	 */
	public function statusCounts(?int $projectId = null): array
	{
		$rows = $projectId === null
			? $this->db->fetchAll("SELECT status, COUNT(*) AS total FROM `{$this->table()}` GROUP BY status")
			: $this->db->fetchAll("SELECT status, COUNT(*) AS total FROM `{$this->table()}` WHERE project_id = %d GROUP BY status", [$projectId]);
		$counts = [];

		foreach ($rows as $row) {
			$counts[(string) $row['status']] = (int) $row['total'];
		}

		ksort($counts);

		return $counts;
	}

	private static function generationLock(int $projectId, int $topicId, string $task): string
	{
		return 'ai_gen_' . substr(hash('sha256', $projectId . '|' . $topicId . '|' . $task), 0, 16);
	}

	private function findById(int $projectId, string $publicId): ?AiRun
	{
		$row = $this->db->fetchRow("{$this->select()} WHERE r.project_id = %d AND r.public_id = %s", [$projectId, $publicId]);

		return $row === null ? null : AiRun::fromRow($row);
	}

	private function select(): string
	{
		return "SELECT r.*, t.public_id AS topic_public_id, t.label AS topic_label, p.public_id AS project_public_id FROM `{$this->table()}` r
			LEFT JOIN `{$this->db->table('strategy_topics')}` t ON t.id = r.topic_id AND t.project_id = r.project_id
			LEFT JOIN `{$this->db->table('projects')}` p ON p.id = r.project_id";
	}

	/**
	 * INSERT z kolumnami binarnymi przez UNHEX i wartościami NULL jako literał (prepare nie obsługuje NULL).
	 *
	 * @param array<string, int|float|string|null> $row
	 */
	private function insertRow(string $table, array $row): void
	{
		$columns = [];
		$values = [];
		$params = [];

		foreach ($row as $column => $value) {
			$columns[] = '`' . $column . '`';

			if ($value === null) {
				$values[] = 'NULL';

				continue;
			}

			$placeholder = match (true) {
				is_int($value) => '%d',
				is_float($value) => '%f',
				default => '%s',
			};
			$values[] = in_array($column, self::BINARY, true) || $column === 'input_hash' ? 'UNHEX(%s)' : $placeholder;
			$params[] = $value;
		}

		$this->db->execute('INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')', $params);
	}

	/** Ucięcie na granicy znaku UTF-8 z jawnym znacznikiem (bez udawania kompletności). */
	public static function limit(string $value, int $bytes): string
	{
		if (strlen($value) <= $bytes) {
			return $value;
		}

		return mb_strcut($value, 0, $bytes - 32, 'UTF-8') . "\n[truncated: " . strlen($value) . ' bytes]';
	}

	private function table(): string
	{
		return $this->db->table('ai_runs');
	}

	private function payloads(): string
	{
		return $this->db->table('ai_run_payloads');
	}
}
