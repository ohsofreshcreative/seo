<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Wynik klasyfikacji widoczności: klasa, metryki GSC okresu (sumy) i średnia pozycja ważona wyświetleniami.
 */
final class VisibilityResult
{
	public function __construct(
		public readonly Visibility $visibility,
		public readonly ?int $impressions = null,
		public readonly ?int $clicks = null,
		/** Średnia pozycja (GSC) = Σ position_sum / Σ impressions. */
		public readonly ?float $position = null,
		/** Pozycja w TOP 10, ale wyświetlenia stanowią mały udział wolumenu — widoczność sporadyczna. */
		public readonly bool $sporadic = false,
	) {
	}
}
