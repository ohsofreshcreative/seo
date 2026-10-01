<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Przebieg wyszukiwania (wiersz `discovery_runs`) — tylko do odczytu.
 */
final class DiscoveryRun
{
	public const QUEUED = 'queued';

	public const RUNNING = 'running';

	public const COMPLETED = 'completed';

	public const PARTIAL = 'partial';

	public const FAILED = 'failed';

	public const CANCELLED = 'cancelled';

	public const ACTIVE = [self::QUEUED, self::RUNNING];

	/**
	 * @param array<string, int> $rejected powód → liczba odrzuconych fraz
	 * @param array<string, mixed> $options
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $projectId,
		public readonly string $provider,
		public readonly int $locationCode,
		public readonly string $languageCode,
		public readonly DiscoveryMethod $method,
		public readonly ?int $depth,
		public readonly string $status,
		public readonly string $trigger,
		public readonly int $seedsCount,
		public readonly int $maxCandidates,
		public readonly int $seedLimit,
		public readonly int $minVolume,
		public readonly ?int $maxDifficulty,
		public readonly bool $forced,
		public readonly array $options,
		public readonly int $tasksPlanned,
		public readonly int $tasksDone,
		public readonly float $estimatedCost,
		public readonly float $cost,
		public readonly int $itemsReceived,
		public readonly int $candidatesNew,
		public readonly int $candidatesSeen,
		public readonly array $rejected,
		public readonly ?string $blockedBy,
		public readonly ?string $errorCode,
		public readonly ?string $errorMessage,
		public readonly ?int $createdBy,
		public readonly string $createdAt,
		public readonly ?string $startedAt,
		public readonly ?string $finishedAt,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$options = json_decode((string) ($row['options'] ?? ''), true);
		$rejected = json_decode((string) ($row['rejected'] ?? ''), true);

		return new self(
			id: (int) $row['id'],
			publicId: (string) $row['public_id'],
			projectId: (int) $row['project_id'],
			provider: (string) $row['provider'],
			locationCode: (int) $row['location_code'],
			languageCode: (string) $row['language_code'],
			method: DiscoveryMethod::tryFrom((string) $row['method']) ?? DiscoveryMethod::Related,
			depth: $row['depth'] === null ? null : (int) $row['depth'],
			status: (string) $row['status'],
			trigger: (string) $row['trigger_type'],
			seedsCount: (int) $row['seeds_count'],
			maxCandidates: (int) $row['max_candidates'],
			seedLimit: (int) $row['seed_limit'],
			minVolume: (int) $row['min_volume'],
			maxDifficulty: $row['max_difficulty'] === null ? null : (int) $row['max_difficulty'],
			forced: (int) $row['forced'] === 1,
			options: is_array($options) ? $options : [],
			tasksPlanned: (int) $row['tasks_planned'],
			tasksDone: (int) $row['tasks_done'],
			estimatedCost: (float) $row['estimated_cost'],
			cost: (float) $row['cost'],
			itemsReceived: (int) $row['items_received'],
			candidatesNew: (int) $row['candidates_new'],
			candidatesSeen: (int) $row['candidates_seen'],
			rejected: is_array($rejected) ? array_map('intval', $rejected) : [],
			blockedBy: $row['blocked_by'],
			errorCode: $row['error_code'],
			errorMessage: $row['error_message'],
			createdBy: $row['created_by'] === null ? null : (int) $row['created_by'],
			createdAt: (string) $row['created_at'],
			startedAt: $row['started_at'],
			finishedAt: $row['finished_at'],
		);
	}

	public function isActive(): bool
	{
		return in_array($this->status, self::ACTIVE, true);
	}

	public function skipsOtherLanguage(): bool
	{
		return (bool) ($this->options['skip_other_language'] ?? true);
	}

	public function statusLabel(): string
	{
		return self::label($this->status);
	}

	public static function label(string $status): string
	{
		return match ($status) {
			self::QUEUED => 'W kolejce',
			self::RUNNING => 'W trakcie',
			self::COMPLETED => 'Zakończone',
			self::PARTIAL => 'Zakończone częściowo',
			self::FAILED => 'Błąd',
			self::CANCELLED => 'Anulowane',
			default => $status,
		};
	}

	public static function rejectedLabel(string $reason): string
	{
		return match ($reason) {
			'excluded' => 'wykluczone słowa projektu',
			'other_language' => 'inny język niż rynek',
			'min_volume' => 'poniżej min. wolumenu',
			'max_difficulty' => 'powyżej maks. trudności SEO',
			'limit' => 'ponad limit kandydatów przebiegu',
			'duplicate' => 'duplikaty (ta sama fraza)',
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
			'method' => $this->method->value,
			'depth' => $this->depth,
			'trigger' => $this->trigger,
			'market' => ['location_code' => $this->locationCode, 'language_code' => $this->languageCode],
			'seeds' => $this->seedsCount,
			'max_candidates' => $this->maxCandidates,
			'seed_limit' => $this->seedLimit,
			'min_volume' => $this->minVolume,
			'max_difficulty' => $this->maxDifficulty,
			'forced' => $this->forced,
			'tasks_planned' => $this->tasksPlanned,
			'tasks_done' => $this->tasksDone,
			'estimated_cost' => round($this->estimatedCost, 6),
			'cost' => round($this->cost, 6),
			'items_received' => $this->itemsReceived,
			'candidates_new' => $this->candidatesNew,
			'candidates_seen' => $this->candidatesSeen,
			'rejected' => $this->rejected,
			'blocked_by' => $this->blockedBy,
			'error' => $this->errorCode,
			'error_message' => $this->errorMessage,
			'created_at' => $this->createdAt,
			'started_at' => $this->startedAt,
			'finished_at' => $this->finishedAt,
		];
	}
}
