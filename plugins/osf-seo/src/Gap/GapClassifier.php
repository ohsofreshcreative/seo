<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Typ luki frazy: najlepsza pozycja konkurenta C (Labs) vs widoczność projektu P (punkt 14 planu):
 *
 * 1. widoczność nieznana → Nieznana,
 * 2. brak widoczności (dowód nieobecności) → Brak widoczności,
 * 3. widoczność sporadyczna (GSC) → Słaba,
 * 4. P < C → Projekt silniejszy,
 * 5. P ≤ 10 albo P − C < 5 → Porównywalna,
 * 6. w pozostałych przypadkach (P > 10 i P − C ≥ 5) → Słaba widoczność.
 */
final class GapClassifier
{
	public function __construct(
		private readonly float $visiblePosition = 10.0,
		private readonly int $weakDelta = GapConfig::WEAK_DELTA,
	) {
	}

	public function classify(ProjectEvidence $project, int $competitorRank): GapType
	{
		if ($project->visibility === ProjectVisibility::Unknown) {
			return GapType::Unknown;
		}

		if ($project->visibility === ProjectVisibility::None || $project->position === null) {
			return $project->visibility === ProjectVisibility::None ? GapType::Missing : GapType::Unknown;
		}

		if ($project->sporadic) {
			return GapType::Weak;
		}

		$position = $project->position;

		return match (true) {
			$position < $competitorRank => GapType::Stronger,
			$position <= $this->visiblePosition || $position - $competitorRank < $this->weakDelta => GapType::Competitive,
			default => GapType::Weak,
		};
	}
}
