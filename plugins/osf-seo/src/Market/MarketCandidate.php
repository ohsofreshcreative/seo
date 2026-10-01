<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Fraza wybrana do wzbogacenia: postać znormalizowana (wysyłana do dostawcy), fraza GSC o największej liczbie
 * wyświetleń wśród wariantów (np. „Buty” i „buty” to jedna fraza rynkowa), sygnały GSC i brakujące metryki.
 */
final class MarketCandidate
{
	public function __construct(
		public readonly string $keyword,
		public readonly string $gscKeyword,
		public readonly int $impressions,
		public readonly int $clicks,
		public readonly bool $needsVolume,
		public readonly bool $needsDifficulty,
	) {
	}
}
