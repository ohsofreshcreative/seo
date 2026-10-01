<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Gsc\Dataset;

/** Dane wejściowe planisty — migawka stanu projektu (bez dostępu do bazy). */
final class PlanInput
{
	/**
	 * @param string $today dzisiejsza data GSC (czas pacyficzny, Y-m-d)
	 * @param string $now teraz w UTC (Y-m-d H:i:s)
	 * @param array<string, SyncState> $states dataset → stan
	 * @param array<string, true> $pending klucze `dataset:refresh` / `dataset:backfill` zadań oczekujących
	 * @param array<string, float|null> $density dataset → wiersze/dzień z ostatniego udanego importu
	 */
	public function __construct(
		public readonly int $projectId,
		public readonly string $today,
		public readonly string $now,
		public readonly array $states,
		public readonly array $pending = [],
		public readonly array $density = [],
		public readonly int $historyMonths = SyncConfig::HISTORY_MONTHS,
		public readonly int $refreshDays = SyncConfig::REFRESH_DAYS,
		public readonly bool $force = false,
		public readonly TriggerType $refreshTrigger = TriggerType::Schedule,
	) {
	}

	public function state(Dataset $dataset): SyncState
	{
		return $this->states[$dataset->value] ?? new SyncState($this->projectId, $dataset);
	}

	public function isPending(Dataset $dataset, string $kind): bool
	{
		return isset($this->pending[$dataset->value . ':' . $kind]);
	}
}
