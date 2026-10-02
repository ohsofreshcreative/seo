<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Widoczność projektu dla frazy — hierarchia dowodów (docs/ARCHITECTURE.md, sekcja 14.6):
 *
 * 1. świeży pomiar SERP (STEP 14, TOP100): wynik projektu → jego pozycja; brak wyniku w TOP100 → brak widoczności,
 * 2. GSC (okno 90 dni, pozycja ważona wyświetleniami): co najmniej 10 wyświetleń → średnia pozycja; pozycja ≤ 10 przy
 *    wyświetleniach < 10% wolumenu okresu → widoczność sporadyczna (słaba),
 * 3. punkt odniesienia Labs (zbiór fraz domeny projektu): fraza w zbiorze → pozycja Labs,
 * 4. brak widoczności tylko z dowodem nieobecności: Labs wiarygodnie bez frazy (`GapDomain::provesNoVisibility()`:
 *    import obejmujący pełne TOP100, spójny, wolumen frazy z zapasem nad granicą zakresu i limitu fraz) ORAZ GSC ma
 *    dane projektu z mniej niż 10 wyświetleniami. Sam brak frazy w GSC ani w niekompletnym zbiorze nigdy nie oznacza
 *    braku widoczności — wtedy „Nieznana”.
 */
final class VisibilityResolver
{
	public function __construct(
		private readonly int $minImpressions = 10,
		private readonly float $visiblePosition = 10.0,
		private readonly float $visibleShare = 0.1,
		private readonly int $windowDays = 90,
	) {
	}

	public static function fromConfig(GapConfig $config): self
	{
		return new self($config->minImpressions(), $config->visiblePosition(), $config->visibleShare(), $config->windowDays());
	}

	/**
	 * @param array{found: bool, rank: ?int, depth: int}|null $serp świeży pomiar SERP frazy (STEP 14) albo null
	 * @param array{has_data: bool, impressions: int, position_sum: float} $gsc
	 * @param array{rank: ?int, absence_reliable: bool}|null $labs punkt odniesienia projektu albo null (brak zbioru)
	 */
	public function resolve(?array $serp, array $gsc, ?array $labs, ?int $searchVolume): ProjectEvidence
	{
		if ($serp !== null) {
			if ($serp['found'] && $serp['rank'] !== null) {
				return new ProjectEvidence($this->byPosition((float) $serp['rank']), ProjectEvidence::SOURCE_SERP, (float) $serp['rank']);
			}

			if (! $serp['found'] && $serp['depth'] >= 100) {
				return new ProjectEvidence(ProjectVisibility::None, ProjectEvidence::SOURCE_SERP, null);
			}
		}

		if ($gsc['has_data'] && $gsc['impressions'] >= $this->minImpressions) {
			$position = round($gsc['position_sum'] / $gsc['impressions'], 2);

			if ($position > $this->visiblePosition) {
				return new ProjectEvidence(ProjectVisibility::Low, ProjectEvidence::SOURCE_GSC, $position);
			}

			$expected = $searchVolume === null ? null : $searchVolume * $this->windowDays / 30;

			if ($expected !== null && $expected > 0 && $gsc['impressions'] < $this->visibleShare * $expected) {
				return new ProjectEvidence(ProjectVisibility::Low, ProjectEvidence::SOURCE_GSC, $position, true);
			}

			return new ProjectEvidence(ProjectVisibility::Visible, ProjectEvidence::SOURCE_GSC, $position);
		}

		if ($labs !== null && $labs['rank'] !== null) {
			return new ProjectEvidence($this->byPosition((float) $labs['rank']), ProjectEvidence::SOURCE_LABS, (float) $labs['rank']);
		}

		if ($labs !== null && $labs['absence_reliable'] && $gsc['has_data'] && $gsc['impressions'] < $this->minImpressions) {
			return new ProjectEvidence(ProjectVisibility::None, ProjectEvidence::SOURCE_LABS, null);
		}

		return ProjectEvidence::unknown();
	}

	private function byPosition(float $position): ProjectVisibility
	{
		return $position <= $this->visiblePosition ? ProjectVisibility::Visible : ProjectVisibility::Low;
	}
}
