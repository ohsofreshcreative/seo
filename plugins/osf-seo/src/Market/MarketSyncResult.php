<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Wynik synchronizacji danych rynkowych projektu (CLI, panel, przebieg w tle).
 */
final class MarketSyncResult
{
	public const SUCCESS = 'success';

	public const NOTHING_TO_DO = 'nothing_to_do';

	public const PARTIAL = 'partial';

	public const FAILED = 'failed';

	public const SKIPPED = 'skipped';

	public const NOT_CONFIGURED = 'not_configured';

	public const ALREADY_RUNNING = 'already_running';

	public const RATE_LIMITED = 'rate_limited';

	public const PLAN_CHANGED = 'plan_changed';

	public int $volumeTasks = 0;

	public int $volumeKeywords = 0;

	public int $difficultyTasks = 0;

	public int $difficultyKeywords = 0;

	public int $unmatched = 0;

	public float $cost = 0.0;

	public ?string $blockedBy = null;

	public ?ProviderErrorCategory $error = null;

	public ?string $errorMessage = null;

	public function __construct(
		public string $status,
		public readonly ?SyncPlan $plan = null,
		public readonly ?string $reason = null,
	) {
	}

	public function tasks(): int
	{
		return $this->volumeTasks + $this->difficultyTasks;
	}

	/** Status końcowy po wykonaniu zadań planu. */
	public function finish(): self
	{
		$this->status = match (true) {
			$this->error !== null => $this->tasks() > 0 ? self::PARTIAL : self::FAILED,
			$this->tasks() === 0 && $this->blockedBy !== null => self::SKIPPED,
			$this->tasks() === 0 => self::NOTHING_TO_DO,
			$this->blockedBy !== null => self::PARTIAL,
			default => self::SUCCESS,
		};

		return $this;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'status' => $this->status,
			'reason' => $this->reason,
			'keywords_selected' => $this->plan?->keywordCount() ?? 0,
			'volume_tasks' => $this->volumeTasks,
			'volume_keywords_queued' => $this->volumeKeywords,
			'difficulty_tasks' => $this->difficultyTasks,
			'difficulty_keywords_stored' => $this->difficultyKeywords,
			'unmatched_results' => $this->unmatched,
			'cost' => round($this->cost, 6),
			'blocked_by' => $this->blockedBy,
			'error' => $this->error?->value,
			'error_message' => $this->errorMessage,
		];
	}
}
