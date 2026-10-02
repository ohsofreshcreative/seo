<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Wynik jednego żądania: frazy strony (po jednej na frazę — najlepszy wynik domeny), liczba wszystkich fraz zakresu
 * u dostawcy i koszt zgłoszony przez API.
 */
final class RankedKeywordsBatch
{
	/**
	 * @param list<RankedKeyword> $items
	 * @param array<string, int> $skipped powód → liczba elementów odrzuconych przy odczycie (np. obcy host, brak frazy)
	 */
	public function __construct(
		public readonly array $items,
		/** Liczba elementów zwróconych przez dostawcę na tej stronie (przed odrzuceniem) — podstawa paginacji. */
		public readonly int $received,
		public readonly int $totalCount,
		public readonly ?float $cost,
		public readonly array $skipped = [],
	) {
	}
}
