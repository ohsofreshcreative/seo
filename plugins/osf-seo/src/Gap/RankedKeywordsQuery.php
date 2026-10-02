<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Jedna strona fraz domeny: zakres (najgorsza pozycja, minimalny wolumen) jest filtrowany po stronie dostawcy —
 * płacimy tylko za zwrócone frazy.
 */
final class RankedKeywordsQuery
{
	public function __construct(
		/** Domena znormalizowana (bez schematu i `www.`). */
		public readonly string $domain,
		public readonly int $limit,
		public readonly int $offset,
		public readonly int $maxRank,
		public readonly int $minVolume,
	) {
	}
}
