<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;
use RuntimeException;

/**
 * Zlecenia pobrania stron (`page_jobs`). Odczyt po identyfikatorze publicznym zawsze w obrębie projektu (brak IDOR); przejęcie zlecenia
 * przez krok w tle wyłącznie warunkowym UPDATE (dwa równoległe procesy nie wykonają tego samego zlecenia).
 */
final class PageJobRepository
{
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
	 * @param list<array<string, mixed>> $items
	 */
	public function create(int $projectId, ?int $topicId, bool $force, array $items, string $requestKey, ?int $userId): PageJob
	{
		$now = $this->now();
		$publicId = Ulid::generate($this->clock->now());
		$this->db->insert($this->table(), [
			'public_id' => $publicId,
			'project_id' => $projectId,
			'topic_id' => $topicId,
			'status' => PageJob::STATUS_QUEUED,
			'force_fetch' => $force ? 1 : 0,
			'items' => self::encode($items),
			'items_total' => count($items),
			'items_done' => 0,
			'request_key' => hex2bin($requestKey),
			'requested_by' => $userId,
			'created_at' => $now,
			'run_after' => $now,
		]);

		return $this->find($projectId, $publicId) ?? throw new RuntimeException('Page job was not stored.');
	}

	public function find(int $projectId, string $publicId): ?PageJob
	{
		$publicId = Ulid::normalize($publicId);

		if ($publicId === null) {
			return null;
		}

		$row = $this->db->fetchRow("{$this->select()} WHERE j.project_id = %d AND j.public_id = %s", [$projectId, $publicId]);

		return $row === null ? null : PageJob::fromRow($row);
	}

	/** Aktywne zlecenie projektu o tym samym kluczu (ten sam wybór) — podwójne kliknięcie nie tworzy drugiego zlecenia. */
	public function activeByKey(int $projectId, string $requestKey): ?PageJob
	{
		$row = $this->db->fetchRow(
			"{$this->select()} WHERE j.project_id = %d AND j.request_key = UNHEX(%s) AND j.status IN (%s, %s) ORDER BY j.id DESC LIMIT 1",
			[$projectId, $requestKey, PageJob::STATUS_QUEUED, PageJob::STATUS_RUNNING],
		);

		return $row === null ? null : PageJob::fromRow($row);
	}

	public function activeCount(int $projectId): int
	{
		return (int) $this->db->fetchValue(
			"SELECT COUNT(*) FROM `{$this->table()}` WHERE project_id = %d AND status IN (%s, %s)",
			[$projectId, PageJob::STATUS_QUEUED, PageJob::STATUS_RUNNING],
		);
	}

	/**
	 * Ostatnie zlecenia projektu (opcjonalnie tematu).
	 *
	 * @return list<PageJob>
	 */
	public function recent(int $projectId, ?int $topicId = null, int $limit = 10): array
	{
		$where = 'j.project_id = %d';
		$params = [$projectId];

		if ($topicId !== null) {
			$where .= ' AND j.topic_id = %d';
			$params[] = $topicId;
		}

		$params[] = max(1, min(100, $limit));

		return array_map(PageJob::fromRow(...), $this->db->fetchAll("{$this->select()} WHERE {$where} ORDER BY j.created_at DESC, j.id DESC LIMIT %d", $params));
	}

	/**
	 * Zlecenia gotowe do wykonania (wszystkie projekty) — najstarszy termin pierwszy.
	 *
	 * @return list<PageJob>
	 */
	public function due(int $limit): array
	{
		return array_map(PageJob::fromRow(...), $this->db->fetchAll(
			"{$this->select()} WHERE j.status = %s AND j.run_after <= %s ORDER BY j.run_after, j.id LIMIT %d",
			[PageJob::STATUS_QUEUED, $this->now(), max(1, $limit)],
		));
	}

	/** Przejęcie zlecenia (`queued` → `running`) — false, gdy przejął je inny proces. */
	public function claim(PageJob $job): bool
	{
		$now = $this->now();

		return $this->db->execute(
			"UPDATE `{$this->table()}` SET status = %s, started_at = COALESCE(started_at, %s), heartbeat_at = %s, attempts = attempts + 1
			WHERE id = %d AND status = %s AND run_after <= %s",
			[PageJob::STATUS_RUNNING, $now, $now, $job->id, PageJob::STATUS_QUEUED, $now],
		) === 1;
	}

	/**
	 * Postęp przebiegu (pozycje po każdej próbie) — tylko w trakcie przebiegu.
	 *
	 * @param list<array<string, mixed>> $items
	 */
	public function progress(PageJob $job, array $items): void
	{
		$this->db->update($this->table(), [
			'items' => self::encode($items),
			'items_done' => self::done($items),
			'heartbeat_at' => $this->now(),
		], ['id' => $job->id, 'status' => PageJob::STATUS_RUNNING]);
	}

	/**
	 * Powrót do kolejki (odstęp hosta albo koniec czasu kroku) z terminem kolejnej próby.
	 *
	 * @param list<array<string, mixed>> $items
	 */
	public function release(PageJob $job, array $items, string $runAfter): void
	{
		$this->db->update($this->table(), [
			'status' => PageJob::STATUS_QUEUED,
			'items' => self::encode($items),
			'items_done' => self::done($items),
			'run_after' => $runAfter,
			'heartbeat_at' => null,
		], ['id' => $job->id, 'status' => PageJob::STATUS_RUNNING]);
	}

	/**
	 * Zakończenie (warunkowo — tylko ze statusu `$from`).
	 *
	 * @param list<array<string, mixed>>|null $items
	 */
	public function finish(PageJob $job, string $status, ?array $items, ?string $error, string $from = PageJob::STATUS_RUNNING): bool
	{
		$data = ['status' => $status, 'finished_at' => $this->now(), 'heartbeat_at' => null, 'error_code' => $error];

		if ($items !== null) {
			$data['items'] = self::encode($items);
			$data['items_done'] = self::done($items);
		}

		return $this->db->update($this->table(), $data, ['id' => $job->id, 'status' => $from]) === 1;
	}

	/**
	 * Przebiegi przerwane (brak znaku życia od chwili) — do odzyskania.
	 *
	 * @return list<PageJob>
	 */
	public function staleRunning(string $before): array
	{
		return array_map(PageJob::fromRow(...), $this->db->fetchAll(
			"{$this->select()} WHERE j.status = %s AND COALESCE(j.heartbeat_at, j.started_at) < %s ORDER BY j.id LIMIT 50",
			[PageJob::STATUS_RUNNING, $before],
		));
	}

	/**
	 * Liczniki statusów (diagnostyka, ustawienia).
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

	/** Retencja: zakończone zlecenia starsze niż chwila. */
	public function purgeBefore(string $before): int
	{
		return (int) $this->db->execute(
			"DELETE FROM `{$this->table()}` WHERE created_at < %s AND status NOT IN (%s, %s)",
			[$before, PageJob::STATUS_QUEUED, PageJob::STATUS_RUNNING],
		);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	private static function done(array $items): int
	{
		return count(array_filter($items, static fn (array $item): bool => ($item['state'] ?? null) === PageJob::ITEM_DONE));
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	private static function encode(array $items): string
	{
		return (string) json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	private function select(): string
	{
		return "SELECT j.*, p.public_id AS project_public_id, t.public_id AS topic_public_id FROM `{$this->table()}` j
			JOIN `{$this->db->table('projects')}` p ON p.id = j.project_id
			LEFT JOIN `{$this->db->table('strategy_topics')}` t ON t.id = j.topic_id AND t.project_id = j.project_id";
	}

	private function table(): string
	{
		return $this->db->table('page_jobs');
	}
}
