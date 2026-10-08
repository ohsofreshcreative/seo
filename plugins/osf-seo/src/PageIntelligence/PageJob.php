<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

/**
 * Zlecenie pobrania stron z panelu (`page_jobs`, faza D): pozycje (po jednym adresie — wybór, stan, wynik próby), status, termin kolejnej
 * próby po odstępie hosta i znacznik życia przebiegu. Wykonuje je wyłącznie krok w tle przez `PageIntelligenceService::fetch`.
 *
 * Statusy: `queued` (czeka na krok w tle albo na odstęp hosta) → `running` → `completed` (każda pozycja ma wynik — także odmowę lub błąd
 * strony) | `failed` (zlecenie nie mogło być wykonane, np. projekt niedostępny) | `cancelled`.
 */
final class PageJob
{
	public const STATUS_QUEUED = 'queued';

	public const STATUS_RUNNING = 'running';

	public const STATUS_COMPLETED = 'completed';

	public const STATUS_FAILED = 'failed';

	public const STATUS_CANCELLED = 'cancelled';

	public const ACTIVE = [self::STATUS_QUEUED, self::STATUS_RUNNING];

	public const ITEM_PENDING = 'pending';

	public const ITEM_DONE = 'done';

	/** Wyniki pozycji uznawane za udane (jest aktualny snapshot). */
	public const OK_OUTCOMES = ['created', 'changed', 'unchanged', 'cached', 'not_modified'];

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $projectId,
		public readonly string $projectPublicId,
		public readonly ?int $topicId,
		public readonly ?string $topicPublicId,
		public readonly string $status,
		public readonly bool $force,
		public readonly array $items,
		public readonly int $itemsTotal,
		public readonly int $itemsDone,
		public readonly ?int $requestedBy,
		public readonly string $createdAt,
		public readonly string $runAfter,
		public readonly ?string $startedAt,
		public readonly ?string $heartbeatAt,
		public readonly ?string $finishedAt,
		public readonly int $attempts,
		public readonly ?string $errorCode,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$items = json_decode((string) $row['items'], true);

		return new self(
			(int) $row['id'],
			(string) $row['public_id'],
			(int) $row['project_id'],
			(string) ($row['project_public_id'] ?? ''),
			$row['topic_id'] === null ? null : (int) $row['topic_id'],
			$row['topic_public_id'] ?? null,
			(string) $row['status'],
			(int) $row['force_fetch'] === 1,
			is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
			(int) $row['items_total'],
			(int) $row['items_done'],
			$row['requested_by'] === null ? null : (int) $row['requested_by'],
			(string) $row['created_at'],
			(string) $row['run_after'],
			$row['started_at'],
			$row['heartbeat_at'],
			$row['finished_at'],
			(int) $row['attempts'],
			$row['error_code'],
		);
	}

	public function isActive(): bool
	{
		return in_array($this->status, self::ACTIVE, true);
	}

	/** Liczba pozycji z aktualnym snapshotem (pobrane, bez zmian albo świeże z pamięci). */
	public function succeeded(): int
	{
		return count(array_filter($this->items, static fn (array $item): bool => in_array($item['outcome'] ?? null, self::OK_OUTCOMES, true)));
	}

	/**
	 * Dane dla panelu i CLI — bez identyfikatorów wewnętrznych; użytkownik tylko na życzenie.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(bool $withUsers = false): array
	{
		return [
			'id' => $this->publicId,
			'topic' => $this->topicPublicId,
			'status' => $this->status,
			'active' => $this->isActive(),
			'force' => $this->force,
			'items_total' => $this->itemsTotal,
			'items_done' => $this->itemsDone,
			'items_succeeded' => $this->succeeded(),
			'items' => array_map(static fn (array $item): array => array_diff_key($item, ['selection' => true]), $this->items),
			'created_at' => $this->createdAt,
			'run_after' => $this->runAfter,
			'started_at' => $this->startedAt,
			'finished_at' => $this->finishedAt,
			'attempts' => $this->attempts,
			'error' => $this->errorCode,
		] + ($withUsers ? ['requested_by' => $this->requestedBy] : []);
	}
}
