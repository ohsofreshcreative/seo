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

	private const BINARY = ['context_fingerprint', 'evidence_fingerprint', 'evidence_hash'];

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
	public function create(array $data, string $input, string $inputHash): AiRun
	{
		$publicId = Ulid::generate($this->clock->now());
		$row = ['public_id' => $publicId, 'status' => AiRun::STATUS_RESERVED, 'created_at' => $this->now()] + $data;
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

	/** `reserved` → `running` (warunkowo — zapobiega podwójnemu wysłaniu tego samego uruchomienia). */
	public function markRunning(AiRun $run): bool
	{
		return $this->db->execute(
			"UPDATE `{$this->table()}` SET status = %s, started_at = %s WHERE id = %d AND status = %s",
			[AiRun::STATUS_RUNNING, $this->now(), $run->id, AiRun::STATUS_RESERVED],
		) === 1;
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
				"SELECT id FROM `{$this->table()}` WHERE created_at < %s AND status NOT IN (%s, %s) ORDER BY id LIMIT %d",
				[$before, AiRun::STATUS_RESERVED, AiRun::STATUS_RUNNING, $batch],
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

	private function findById(int $projectId, string $publicId): ?AiRun
	{
		$row = $this->db->fetchRow("{$this->select()} WHERE r.project_id = %d AND r.public_id = %s", [$projectId, $publicId]);

		return $row === null ? null : AiRun::fromRow($row);
	}

	private function select(): string
	{
		return "SELECT r.*, t.public_id AS topic_public_id FROM `{$this->table()}` r
			LEFT JOIN `{$this->db->table('strategy_topics')}` t ON t.id = r.topic_id AND t.project_id = r.project_id";
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
