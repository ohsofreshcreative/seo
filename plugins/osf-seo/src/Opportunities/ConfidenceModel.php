<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Pewność sygnału (osobno od priorytetu) — punkty 0–4:
 *
 * - próba: wyświetlenia grupy ≥ confidence_high_impressions → 2 pkt, ≥ confidence_medium_impressions → 1 pkt,
 * - porównanie: poprzedni okres w pełni zaimportowany i grupa miała w nim wyświetlenia → 1 pkt,
 * - spójność (zależna od typu, wyliczana przez detektor) → 1 pkt.
 *
 * 0–1 pkt = Niska, 2–3 = Średnia, 4 = Wysoka. Bez pozorowania pewności statystycznej.
 */
final class ConfidenceModel
{
	public function __construct(private readonly OpportunityConfig $config)
	{
	}

	/**
	 * @param string $comparison ok | not_covered | no_previous_data
	 * @param string $consistencyRule kod reguły spójności (opis w UI)
	 * @return array{level: int, points: int, factors: list<array<string, mixed>>}
	 */
	public function evaluate(int $sampleImpressions, string $comparison, bool $consistent, string $consistencyRule, int $days): array
	{
		$high = $this->config->volume('confidence_high_impressions', $days);
		$medium = $this->config->volume('confidence_medium_impressions', $days);
		$samplePoints = $sampleImpressions >= $high ? 2 : ($sampleImpressions >= $medium ? 1 : 0);
		$comparisonPoints = $comparison === 'ok' ? 1 : 0;
		$consistencyPoints = $consistent ? 1 : 0;
		$points = $samplePoints + $comparisonPoints + $consistencyPoints;

		return [
			'level' => Confidence::fromPoints($points)->value,
			'points' => $points,
			'factors' => [
				['code' => 'sample', 'points' => $samplePoints, 'max' => 2, 'value' => $sampleImpressions, 'medium' => $medium, 'high' => $high],
				['code' => 'comparison', 'points' => $comparisonPoints, 'max' => 1, 'reason' => $comparison],
				['code' => 'consistency', 'points' => $consistencyPoints, 'max' => 1, 'rule' => $consistencyRule],
			],
		];
	}
}
