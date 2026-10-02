<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Przebieg pomiaru pozycji projektu (harmonogram albo ręczny).
 */
final class SerpRun
{
	public const QUEUED = 'queued';

	public const SUBMITTING = 'submitting';

	public const SUBMITTED = 'submitted';

	public const COMPLETED = 'completed';

	public const PARTIAL = 'partial';

	public const FAILED = 'failed';

	public const SKIPPED = 'skipped';

	public const CANCELLED = 'cancelled';

	public const ACTIVE = [self::QUEUED, self::SUBMITTING, self::SUBMITTED];

	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $projectId,
		public readonly int $contextId,
		public readonly string $trigger,
		public readonly string $status,
		public readonly ?string $skipReason,
		public readonly int $keywordsPlanned,
		public readonly int $keywordsSkipped,
		public readonly int $tasksSubmitted,
		public readonly int $tasksCompleted,
		public readonly int $tasksFailed,
		public readonly float $estimatedCost,
		public readonly float $cost,
		public readonly ?string $errorCode,
		public readonly ?string $errorMessage,
		public readonly ?int $createdBy,
		public readonly string $createdAt,
		public readonly ?string $submittedAt,
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
			(int) $row['context_id'],
			(string) $row['trigger_type'],
			(string) $row['status'],
			$row['skip_reason'],
			(int) $row['keywords_planned'],
			(int) $row['keywords_skipped'],
			(int) $row['tasks_submitted'],
			(int) $row['tasks_completed'],
			(int) $row['tasks_failed'],
			(float) $row['estimated_cost'],
			(float) $row['cost'],
			$row['error_code'],
			$row['error_message'],
			$row['created_by'] === null ? null : (int) $row['created_by'],
			(string) $row['created_at'],
			$row['submitted_at'],
			$row['finished_at'],
		);
	}

	public function isActive(): bool
	{
		return in_array($this->status, self::ACTIVE, true);
	}

	public function statusLabel(): string
	{
		return self::label($this->status);
	}

	public static function label(string $status): string
	{
		return match ($status) {
			self::QUEUED => 'w kolejce',
			self::SUBMITTING => 'zlecanie',
			self::SUBMITTED => 'czeka na wyniki',
			self::COMPLETED => 'zakończony',
			self::PARTIAL => 'zakończony częściowo',
			self::FAILED => 'błąd',
			self::SKIPPED => 'pominięty',
			self::CANCELLED => 'anulowany',
			default => $status,
		};
	}

	public static function skipLabel(?string $reason): string
	{
		return match ($reason) {
			'daily_limit' => 'limit dzienny kosztów DataForSEO',
			'monthly_limit' => 'limit miesięczny kosztów DataForSEO',
			'paused' => 'wstrzymanie po błędzie konta DataForSEO',
			'not_configured' => 'brak konfiguracji DataForSEO',
			'unsupported_market' => 'nieobsługiwany rynek projektu',
			'nothing_to_do' => 'brak fraz do sprawdzenia',
			null => '—',
			default => $reason,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(bool $withCost = true): array
	{
		$data = [
			'id' => $this->publicId,
			'trigger' => $this->trigger,
			'status' => $this->status,
			'status_label' => $this->statusLabel(),
			'skip_reason' => $this->skipReason,
			'keywords_planned' => $this->keywordsPlanned,
			'keywords_skipped' => $this->keywordsSkipped,
			'tasks_submitted' => $this->tasksSubmitted,
			'tasks_completed' => $this->tasksCompleted,
			'tasks_failed' => $this->tasksFailed,
			'error_code' => $this->errorCode,
			'created_at' => $this->createdAt,
			'submitted_at' => $this->submittedAt,
			'finished_at' => $this->finishedAt,
			'active' => $this->isActive(),
		];

		if ($withCost) {
			$data['estimated_cost'] = round($this->estimatedCost, 6);
			$data['cost'] = round($this->cost, 6);
		}

		return $data;
	}
}
