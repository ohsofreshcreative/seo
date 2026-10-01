<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

/**
 * Źródło zadania (`sync_runs.trigger_type`). Zadania odświeżające (najnowsze dane) to wszystkie poza `backfill`.
 */
enum TriggerType: string
{
	/** Codzienne odświeżanie (WP-Cron / cron systemowy). */
	case Schedule = 'schedule';

	/** „Synchronizuj teraz” / CLI. */
	case Manual = 'manual';

	/** Uzupełnianie historii (od najnowszych do najstarszych). */
	case Backfill = 'backfill';

	/** Pierwszy import po wyborze property. */
	case Connect = 'connect';

	public function isBackfill(): bool
	{
		return $this === self::Backfill;
	}

	public function label(): string
	{
		return match ($this) {
			self::Schedule => 'Automatycznie',
			self::Manual => 'Ręcznie',
			self::Backfill => 'Historia',
			self::Connect => 'Pierwszy import',
		};
	}
}
