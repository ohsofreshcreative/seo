<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Jedno płatne żądanie wyszukiwania: seed (postać znormalizowana), metoda, strona wyników i filtry po stronie dostawcy
 * (dostawca nalicza opłatę za każdy zwrócony element, więc min. wolumen i maks. trudność filtrujemy już w żądaniu).
 */
final class DiscoveryQuery
{
	public function __construct(
		public readonly DiscoveryMethod $method,
		public readonly string $seed,
		/** Maksymalna liczba elementów tej strony (1–maxItemsPerRequest). */
		public readonly int $limit,
		public readonly int $offset = 0,
		/** Głębokość powiązań (tylko `related`, 1–4). */
		public readonly ?int $depth = null,
		public readonly int $minVolume = 0,
		public readonly ?int $maxDifficulty = null,
		/** Dane samego seeda (jego metryki) — tylko na pierwszej stronie. */
		public readonly bool $includeSeed = true,
	) {
	}
}
