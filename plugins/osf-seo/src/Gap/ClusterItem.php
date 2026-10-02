<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Fraza do grupowania z deterministycznymi sygnałami powiązań.
 */
final class ClusterItem
{
	/**
	 * @param list<int> $urls adresy konkurentów rankujących na frazę (bez stron głównych i adresów-hubów)
	 * @param list<int>|null $serpTop10 adresy TOP10 z ostatniej naszej migawki SERP (fraza monitorowana) albo null
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $volume,
		public readonly array $urls,
		/** Grupa synonimów dostawcy (hex klucza frazy głównej). */
		public readonly ?string $coreKey,
		/** Strona docelowa projektu (id adresu, bez strony głównej). */
		public readonly ?int $target,
		public readonly ?array $serpTop10,
		/** Zbiór wyrazów bez kolejności i znaków diakrytycznych. */
		public readonly string $tokenKey,
	) {
	}
}
