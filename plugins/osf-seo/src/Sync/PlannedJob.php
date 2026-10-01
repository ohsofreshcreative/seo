<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Gsc\Dataset;
use OsfSeo\Support\DateRange;

final class PlannedJob
{
	public function __construct(
		public readonly Dataset $dataset,
		public readonly TriggerType $trigger,
		public readonly DateRange $range,
		public readonly int $priority,
	) {
	}

	public function kind(): string
	{
		return self::kindOf($this->trigger);
	}

	/** Rodzaj zadania: `refresh` (najnowsze dane) albo `backfill` (historia) — po jednym oczekującym na dataset. */
	public static function kindOf(TriggerType $trigger): string
	{
		return $trigger->isBackfill() ? 'backfill' : 'refresh';
	}
}
