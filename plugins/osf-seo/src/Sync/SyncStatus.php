<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

/**
 * Stan synchronizacji projektu dla panelu i CLI. Daty `*_date` to daty GSC (PT), czasy `*_at` — UTC.
 */
final class SyncStatus
{
	public const LABELS = [
		'not_ready' => 'Niegotowa',
		'needs_reauth' => 'Wymaga ponownej autoryzacji',
		'never' => 'Jeszcze nie synchronizowano',
		'queued' => 'W kolejce',
		'running' => 'W toku',
		'retrying' => 'Ponawianie po błędzie',
		'success' => 'Aktualna',
		'partial' => 'Częściowo (część danych z błędem)',
		'failed' => 'Błąd',
	];

	/**
	 * @param list<array{dataset: string, label: string, status: string, newest_date: ?string, oldest_date: ?string, covered_days: int, total_days: int, progress: int, backfill_complete: bool, last_success_at: ?string, last_attempt_at: ?string, last_error: ?string, retry_after: ?string, pending: int}> $datasets
	 * @param list<SyncRun> $recentRuns
	 */
	public function __construct(
		public readonly string $overall,
		public readonly ?string $notReadyReason,
		public readonly array $datasets,
		/** Ostatnia data z danymi GSC (sumy witryny). */
		public readonly ?string $latestDataDate,
		/** Ostatnia data z frazami. */
		public readonly ?string $keywordsDataDate,
		public readonly ?string $lastSuccessAt,
		public readonly ?string $lastAttemptAt,
		public readonly int $pendingJobs,
		/** Postęp backfillu całej historii (0–100). */
		public readonly int $backfillProgress,
		public readonly array $recentRuns,
	) {
	}

	public function label(): string
	{
		return self::LABELS[$this->overall] ?? $this->overall;
	}

	public function isActive(): bool
	{
		return in_array($this->overall, ['queued', 'running', 'retrying'], true);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'overall' => $this->overall,
			'label' => $this->label(),
			'not_ready_reason' => $this->notReadyReason,
			'latest_data_date' => $this->latestDataDate,
			'keywords_data_date' => $this->keywordsDataDate,
			'last_success_at' => $this->lastSuccessAt,
			'last_attempt_at' => $this->lastAttemptAt,
			'pending_jobs' => $this->pendingJobs,
			'backfill_progress' => $this->backfillProgress,
			'datasets' => $this->datasets,
		];
	}
}
