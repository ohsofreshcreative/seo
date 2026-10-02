<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Discovery\DiscoveredKeyword;

/**
 * Fraza, na którą rankuje domena, według bazy dostawcy (DataForSEO Labs): najlepszy wynik organiczny domeny w migawce
 * SERP dostawcy. To NIE jest nasz pomiar („Pozycja SERP”, STEP 14) — w UI zawsze „Pozycja konkurenta (Labs)” z datą.
 */
final class RankedKeyword
{
	public function __construct(
		/** Fraza z metrykami rynkowymi z tej samej odpowiedzi. */
		public readonly DiscoveredKeyword $keyword,
		/** Pozycja wśród wyników organicznych (`rank_group`) w migawce dostawcy. */
		public readonly int $rankGroup,
		/** Pozycja wśród wszystkich elementów strony (`rank_absolute`) — pomocniczo. */
		public readonly ?int $rankAbsolute,
		/** Adres wyniku (bez fragmentu i parametru `srsltid`); null, gdy nieprawidłowy. */
		public readonly ?string $url,
		/** Host wyniku znormalizowany jak domeny projektu. */
		public readonly string $host,
		public readonly ?string $title,
		/** Szacowany ruch z frazy według dostawcy (CTR × wolumen). */
		public readonly ?float $etv,
		/** Data migawki SERP dostawcy (UTC). */
		public readonly ?string $serpUpdatedAt,
	) {
	}
}
