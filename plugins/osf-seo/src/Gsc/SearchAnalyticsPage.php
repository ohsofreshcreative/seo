<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

/**
 * Jedna strona wyników searchAnalytics.query po walidacji.
 *
 * Wiersz: `keys` (wartości wymiarów w kolejności z żądania), `clicks`, `impressions` (liczby całkowite ≥ 0)
 * i `position` (średnia pozycja GSC dla wiersza). CTR celowo pomijamy — liczymy go z sum (clicks / impressions).
 */
final class SearchAnalyticsPage
{
	/**
	 * @param list<array{keys: list<string>, clicks: int, impressions: int, position: float}> $rows
	 */
	public function __construct(
		public readonly array $rows,
		public readonly int $startRow,
		public readonly string $aggregationType,
	) {
	}

	public function count(): int
	{
		return count($this->rows);
	}

	/** Odcisk strony do wykrywania powtórzonych odpowiedzi (Google zignorował startRow). */
	public function fingerprint(): string
	{
		if ($this->rows === []) {
			return 'empty';
		}

		$first = $this->rows[0];
		$last = $this->rows[count($this->rows) - 1];

		return md5(count($this->rows) . '|' . json_encode([$first, $last]));
	}
}
