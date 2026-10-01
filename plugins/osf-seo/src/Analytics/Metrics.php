<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

/**
 * Reguły obliczeń metryk GSC (docs/ARCHITECTURE.md, sekcja 8). Te same reguły realizuje SQL raportów.
 *
 * - CTR = SUM(clicks) / SUM(impressions) — nigdy średnia z CTR wierszy,
 * - średnia pozycja (GSC) = SUM(position_sum) / SUM(impressions), gdzie position_sum = position × impressions
 *   — nigdy AVG(position),
 * - zmiana pozycji = poprzednia − obecna: dodatnia = poprawa (15 → 7 = +8), ujemna = spadek.
 */
final class Metrics
{
	/** Składnik ważonej pozycji zapisywany w tabelach faktów. Przy 0 wyświetleń: 0 (wiersz nie wpływa na średnią). */
	public static function positionSum(float $position, int $impressions): float
	{
		return $impressions > 0 ? $position * $impressions : 0.0;
	}

	public static function ctr(int|float $clicks, int|float $impressions): ?float
	{
		return $impressions > 0 ? $clicks / $impressions : null;
	}

	public static function position(float $positionSum, int|float $impressions): ?float
	{
		return $impressions > 0 ? $positionSum / $impressions : null;
	}

	/** Dodatnia = poprawa pozycji (mniejsza liczba). Brak pozycji w którymkolwiek okresie → brak zmiany. */
	public static function positionChange(?float $previous, ?float $current): ?float
	{
		return $previous === null || $current === null ? null : $previous - $current;
	}

	public static function change(int|float $previous, int|float $current): int|float
	{
		return $current - $previous;
	}

	/** Zmiana procentowa; przy 0 w poprzednim okresie — brak (nie dzielimy przez zero). */
	public static function percentChange(int|float $previous, int|float $current): ?float
	{
		return $previous > 0 ? ($current - $previous) / $previous * 100 : null;
	}
}
