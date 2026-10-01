<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Wolumen i dane reklamowe jednej frazy od dostawcy. `null` = dostawca nie ma danych (to nie jest 0).
 */
final class VolumeMetrics
{
	/**
	 * @param list<array{month: string, search_volume: ?int}> $monthly miesiące `Y-m-01` (rosnąco)
	 */
	public function __construct(
		/** Fraza w postaci zwróconej przez dostawcę. */
		public readonly string $keyword,
		public readonly ?int $searchVolume,
		/** CPC (USD) — średni koszt kliknięcia w Google Ads. */
		public readonly ?float $cpc,
		/** Konkurencja Ads: `low`, `medium`, `high` (płatne wyniki, nie trudność SEO). */
		public readonly ?string $competitionLevel,
		/** Konkurencja Ads 0–100. */
		public readonly ?int $competitionIndex,
		public readonly ?float $lowTopOfPageBid,
		public readonly ?float $highTopOfPageBid,
		public readonly array $monthly,
	) {
	}
}
