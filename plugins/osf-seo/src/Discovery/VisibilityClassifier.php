<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Klasyfikacja obecnej widoczności frazy w GSC (docs/ARCHITECTURE.md, sekcja 12.6). Metryki z sum okna:
 * wyświetlenia, kliknięcia i średnia pozycja = Σ position_sum / Σ impressions (wszystkie warianty frazy GSC
 * o tym samym kluczu rynkowym — nigdy średnia pozycji).
 *
 * - brak danych GSC projektu → Nieznana,
 * - wyświetlenia < progu (10 w 90 dniach) → Brak widoczności,
 * - średnia pozycja > progu (10) → Słaba widoczność,
 * - pozycja ≤ 10, ale wyświetlenia < 10% wolumenu okresu (wolumen × dni / 30) → Słaba (widoczność sporadyczna),
 * - w pozostałych przypadkach → Już widoczna.
 */
final class VisibilityClassifier
{
	public function __construct(
		private readonly int $minImpressions,
		private readonly float $visiblePosition,
		private readonly float $visibleShare,
		private readonly int $windowDays,
	) {
	}

	public static function fromConfig(DiscoveryConfig $config): self
	{
		return new self($config->minImpressions(), $config->visiblePosition(), $config->visibleShare(), $config->windowDays());
	}

	public function classify(bool $hasGscData, int $impressions, int $clicks, float $positionSum, ?int $searchVolume): VisibilityResult
	{
		if (! $hasGscData) {
			return new VisibilityResult(Visibility::Unknown);
		}

		$position = $impressions > 0 ? round($positionSum / $impressions, 2) : null;

		if ($impressions < $this->minImpressions || $position === null) {
			return new VisibilityResult(Visibility::None, $impressions, $clicks, $position);
		}

		if ($position > $this->visiblePosition) {
			return new VisibilityResult(Visibility::Low, $impressions, $clicks, $position);
		}

		$expected = $searchVolume === null ? null : $searchVolume * $this->windowDays / 30;

		if ($expected !== null && $expected > 0 && $impressions < $this->visibleShare * $expected) {
			return new VisibilityResult(Visibility::Low, $impressions, $clicks, $position, true);
		}

		return new VisibilityResult(Visibility::Visible, $impressions, $clicks, $position);
	}
}
