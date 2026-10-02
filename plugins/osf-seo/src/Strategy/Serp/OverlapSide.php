<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

/**
 * Jedna strona porównania overlapu: fraza, jej najnowszy zgodny pomiar i TOP10 wyników organicznych.
 */
final class OverlapSide
{
	/**
	 * @param array<int, array{rank: int, domain_id: int, home: bool}> $top10 url_id → wynik organiczny TOP10
	 */
	public function __construct(
		public readonly int $marketKeywordId,
		public readonly string $keyword,
		public readonly ?int $snapshotId,
		/** Lokalizacja | język | urządzenie pomiaru (głębokość nie ma znaczenia dla TOP10). */
		public readonly ?string $contextKey,
		public readonly ?string $freshness,
		public readonly array $top10,
		public readonly ?SerpIntentSignal $serpIntent = null,
		public readonly ?string $serpIntentConfidence = null,
		public readonly ?string $providerIntent = null,
	) {
	}
}
