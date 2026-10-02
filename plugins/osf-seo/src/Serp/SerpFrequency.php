<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Częstotliwość automatycznych pomiarów projektu.
 */
enum SerpFrequency: string
{
	case Daily = 'daily';
	case EveryThreeDays = 'every_3_days';
	case Weekly = 'weekly';

	public function days(): int
	{
		return match ($this) {
			self::Daily => 1,
			self::EveryThreeDays => 3,
			self::Weekly => 7,
		};
	}

	/** Średnia liczba pomiarów w miesiącu (365,25 / 12 dni) — do szacunku kosztu miesięcznego. */
	public function checksPerMonth(): float
	{
		return (365.25 / 12) / $this->days();
	}

	public function label(): string
	{
		return match ($this) {
			self::Daily => 'codziennie',
			self::EveryThreeDays => 'co 3 dni',
			self::Weekly => 'co tydzień',
		};
	}
}
