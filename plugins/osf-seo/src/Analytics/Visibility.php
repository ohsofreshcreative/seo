<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

/**
 * Widoczność fraz wg średniej pozycji (GSC) w okresie — progi SKUMULOWANE:
 * TOP 3 = pozycja ≤ 3, TOP 10 = ≤ 10 (zawiera TOP 3), TOP 20, TOP 50, TOP 100.
 * Pozycja frazy w okresie = SUM(position_sum) / SUM(impressions) — to nie jest dokładny ranking SERP.
 */
final class Visibility
{
	public const BUCKETS = [3, 10, 20, 50, 100];

	/**
	 * @param array<int, int> $current próg → liczba fraz
	 * @param array<int, int> $previous
	 */
	public function __construct(
		public readonly array $current,
		public readonly array $previous,
		/** Frazy z wyświetleniami w bieżącym / poprzednim okresie. */
		public readonly int $keywordsCurrent,
		public readonly int $keywordsPrevious,
	) {
	}

	public function change(int $bucket): int
	{
		return ($this->current[$bucket] ?? 0) - ($this->previous[$bucket] ?? 0);
	}
}
