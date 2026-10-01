<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

use OsfSeo\Support\DateRange;

/**
 * Okres raportu: ostatnie N dni dostępnych danych (koniec = ostatnia zaimportowana data GSC, nie „dziś”)
 * i poprzedni okres tej samej długości bezpośrednio przed nim.
 */
final class Period
{
	public const ALLOWED_DAYS = [7, 28, 90];

	public const DEFAULT_DAYS = 28;

	public readonly DateRange $current;

	public readonly DateRange $previous;

	public function __construct(public readonly string $latestDate, public readonly int $days)
	{
		$this->current = new DateRange(DateRange::shift($latestDate, -($days - 1)), $latestDate);
		$this->previous = $this->current->previous();
	}

	public static function days(mixed $value): int
	{
		$days = is_numeric($value) ? (int) $value : self::DEFAULT_DAYS;

		return in_array($days, self::ALLOWED_DAYS, true) ? $days : self::DEFAULT_DAYS;
	}

	/** Pełny zakres obu okresów (jeden skan tabeli faktów). */
	public function span(): DateRange
	{
		return new DateRange($this->previous->start, $this->current->end);
	}
}
