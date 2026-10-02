<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Dowody grupy fraz do heurystyki luki treści (wyłącznie dane zapisane: GSC, SERP, Labs, adresy).
 */
final class ClusterEvidence
{
	public function __construct(
		/** Projekt ma jakiekolwiek dane widoczności (GSC, pomiar SERP albo punkt odniesienia Labs). */
		public readonly bool $hasProjectData,
		public readonly ?int $targetUrlId,
		public readonly ?string $targetSource,
		public readonly bool $targetIsRoot,
		/** Wyświetlenia grupy rozłożone na kilka stron projektu (bez strony dominującej). */
		public readonly bool $scattered,
		/** Liczba konkurentów rankujących na frazy grupy podstroną (nie stroną główną). */
		public readonly int $competitorPages,
		/** Liczba konkurentów z podstroną, której adres pokrywa większość wyrazów frazy wiodącej. */
		public readonly int $dedicatedPages,
		public readonly GapType $leaderType,
		/** Udział fraz z luką (brak, słaba, nieznana) wśród fraz grupy. */
		public readonly float $gapShare,
		public readonly int $gapVolume,
		/** Punkt odniesienia Labs wiarygodnie obejmuje frazę wiodącą (nieobecność projektu jest dowodem). */
		public readonly bool $baselineReliable,
		public readonly bool $hasGsc,
	) {
	}
}
