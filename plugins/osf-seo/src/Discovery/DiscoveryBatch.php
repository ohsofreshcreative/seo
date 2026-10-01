<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Wynik jednego żądania wyszukiwania (jedna strona wyników dla jednego seeda).
 */
final class DiscoveryBatch
{
	/**
	 * @param list<DiscoveredKeyword> $items
	 */
	public function __construct(
		public readonly array $items,
		/** Metryki samego seeda (jeśli dostawca je zwrócił). */
		public readonly ?DiscoveredKeyword $seed,
		/** Liczba wszystkich wyników u dostawcy dla zapytania (do paginacji). */
		public readonly int $totalCount,
		/** Koszt zgłoszony przez API (USD); null — brak informacji. */
		public readonly ?float $cost,
	) {
	}
}
