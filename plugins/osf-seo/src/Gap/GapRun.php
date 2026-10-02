<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Przebieg importu fraz konkurencji (`gap_runs`) — potwierdzony przez użytkownika plan: konkurenci, punkt odniesienia
 * projektu, zakres i maksymalny koszt. Wykonuje go tło albo CLI (strona po stronie, pod wspólnymi limitami kosztów).
 */
final class GapRun
{
	public const QUEUED = 'queued';

	public const RUNNING = 'running';

	/** Wstrzymany limitem kosztów (dziennym/miesięcznym) albo pauzą konta — wznawia się, gdy limit na to pozwoli. */
	public const PAUSED = 'paused';

	public const COMPLETED = 'completed';

	public const PARTIAL = 'partial';

	public const FAILED = 'failed';

	public const CANCELLED = 'cancelled';

	public const ACTIVE = [self::QUEUED, self::RUNNING, self::PAUSED];

	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $projectId,
		public readonly string $provider,
		public readonly int $locationCode,
		public readonly string $languageCode,
		public readonly string $status,
		public readonly string $trigger,
		public readonly Coverage $coverage,
		public readonly bool $forced,
		public readonly int $targetsPlanned,
		public readonly int $targetsDone,
		public readonly int $requestsPlanned,
		public readonly int $requestsDone,
		public readonly float $estimatedCost,
		public readonly float $cost,
		public readonly int $rowsReceived,
		public readonly ?string $blockedBy,
		public readonly ?string $errorCode,
		public readonly ?string $errorMessage,
		public readonly ?int $createdBy,
		public readonly string $createdAt,
		public readonly ?string $startedAt,
		public readonly ?string $pausedAt,
		public readonly ?string $finishedAt,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			(int) $row['id'],
			(string) $row['public_id'],
			(int) $row['project_id'],
			(string) $row['provider'],
			(int) $row['location_code'],
			(string) $row['language_code'],
			(string) $row['status'],
			(string) $row['trigger_type'],
			new Coverage(max(1, min(100, (int) $row['max_rank'])), (int) $row['min_volume'], max(1, (int) $row['max_rows'])),
			(int) $row['forced'] === 1,
			(int) $row['targets_planned'],
			(int) $row['targets_done'],
			(int) $row['requests_planned'],
			(int) $row['requests_done'],
			round((float) $row['estimated_cost'], 6),
			round((float) $row['cost'], 6),
			(int) $row['rows_received'],
			$row['blocked_by'],
			$row['error_code'],
			$row['error_message'],
			$row['created_by'] === null ? null : (int) $row['created_by'],
			(string) $row['created_at'],
			$row['started_at'],
			$row['paused_at'],
			$row['finished_at'],
		);
	}

	public function isActive(): bool
	{
		return in_array($this->status, self::ACTIVE, true);
	}

	public function statusLabel(): string
	{
		return match ($this->status) {
			self::QUEUED => 'w kolejce',
			self::RUNNING => 'w toku',
			self::PAUSED => 'wstrzymany' . ($this->blockedBy === null ? '' : ' (' . self::reasonLabel($this->blockedBy) . ')'),
			self::COMPLETED => 'zakończony',
			self::PARTIAL => 'zakończony częściowo',
			self::FAILED => 'nieudany',
			self::CANCELLED => 'anulowany',
			default => $this->status,
		};
	}

	public static function reasonLabel(string $reason): string
	{
		return match ($reason) {
			'daily_limit' => 'dzienny limit kosztów',
			'monthly_limit' => 'miesięczny limit kosztów',
			'paused' => 'płatne wywołania wstrzymane po błędzie konta',
			'interrupted' => 'przerwane żądanie — bez automatycznego ponowienia',
			'expired' => 'limit kosztów nie pozwolił dokończyć w ciągu 7 dni',
			'cancelled' => 'anulowany',
			default => $reason,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->publicId,
			'status' => $this->status,
			'status_label' => $this->statusLabel(),
			'trigger' => $this->trigger,
			'coverage' => $this->coverage->toArray(),
			'forced' => $this->forced,
			'targets_planned' => $this->targetsPlanned,
			'targets_done' => $this->targetsDone,
			'requests_planned' => $this->requestsPlanned,
			'requests_done' => $this->requestsDone,
			'estimated_cost' => $this->estimatedCost,
			'cost' => $this->cost,
			'rows_received' => $this->rowsReceived,
			'blocked_by' => $this->blockedBy,
			'error_code' => $this->errorCode,
			'created_at' => $this->createdAt,
			'started_at' => $this->startedAt,
			'paused_at' => $this->pausedAt,
			'finished_at' => $this->finishedAt,
			'active' => $this->isActive(),
		];
	}
}
