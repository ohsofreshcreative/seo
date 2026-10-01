<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

/** Status zadania w `sync_runs`. */
enum RunStatus: string
{
	case Queued = 'queued';
	case Running = 'running';
	case Retrying = 'retrying';
	case Success = 'success';
	case Failed = 'failed';
	case Skipped = 'skipped';
	case Cancelled = 'cancelled';

	/** Zadanie czeka albo trwa (blokuje zaplanowanie identycznego). */
	public function isPending(): bool
	{
		return in_array($this, [self::Queued, self::Running, self::Retrying], true);
	}

	public function label(): string
	{
		return match ($this) {
			self::Queued => 'W kolejce',
			self::Running => 'W toku',
			self::Retrying => 'Ponowienie',
			self::Success => 'Sukces',
			self::Failed => 'Błąd',
			self::Skipped => 'Pominięte',
			self::Cancelled => 'Anulowane',
		};
	}

	/**
	 * @return list<string>
	 */
	public static function pendingValues(): array
	{
		return [self::Queued->value, self::Running->value, self::Retrying->value];
	}
}
