<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Decision;

use OsfSeo\Opportunities\OpportunityType;

/**
 * Priorytet Strategii 0–100 (D59 — docs/ARCHITECTURE.md, sekcja 15.13) — narzędzie sortowania pracy, nie prognoza ruchu. Nie kopiuje
 * wyników modułów (Szanse, Nowe frazy, Luki): każdy składnik ma limit, wspólną normalizację logarytmiczną i jawne rozbicie:
 *
 * - popyt (≤ 30): większy z sumy znanych wolumenów i miesięcznego odpowiednika wyświetleń GSC (źródło w rozbiciu; brak danych ≠ 0),
 * - potencjał (≤ 20): zależny od działania (pozycja strony, poprzednia pozycja, dowody nowej strony),
 * - pilność (≤ 15): wielkość spadku, spadek z GSC, silny konflikt,
 * - konkurencja i SERP (≤ 15): konkurenci w TOP10, luka treści,
 * - osiągalność (≤ 10): 10 × (1 − KD/100); brak KD = 5 (neutralnie — nigdy jak KD 0),
 * - intencja i wartość (≤ 10): intencja dostawcy i CPC (mała waga).
 *
 * Wynik = surowa suma × mnożnik pewności (0,6 + 0,4 × pewność/100), monitorowanie × 0,4. Monotoniczność: większy popyt, większy spadek,
 * niższa KD i wyższa pewność nigdy nie obniżają wyniku.
 */
final class PriorityModel
{
	public const DEMAND_MAX = 30.0;

	public const POTENTIAL_MAX = 20.0;

	public const URGENCY_MAX = 15.0;

	public const COMPETITION_MAX = 15.0;

	public const ATTAINABILITY_MAX = 10.0;

	public const VALUE_MAX = 10.0;

	/** Wolumen (miesięcznie), przy którym składnik popytu osiąga maksimum. */
	public const DEMAND_CAP = 10000;

	/** Utracone pozycje, przy których składnik spadku osiąga maksimum. */
	public const DECLINE_CAP = 50;

	public const CPC_CAP = 20.0;

	public const MONITOR_FACTOR = 0.4;

	public const HIGH_BAND = 60;

	public const MEDIUM_BAND = 35;

	/**
	 * @param int $windowDays okno GSC (przeliczenie wyświetleń na miesiąc, gdy brak wolumenu)
	 */
	public function score(TopicFacts $facts, Decision $decision, ConfidenceResult $confidence, int $windowDays = 90): PriorityResult
	{
		$components = [];

		// Popyt: większy z sumy znanych wolumenów i miesięcznego odpowiednika wyświetleń GSC (wyświetlenia to realny popyt, także gdy
		// dostawca podaje 0 albo nie zna frazy).
		$volume = $facts->demand();
		$impressions = (int) round((int) $facts->gscImpressions() * 30 / max(1, $windowDays));
		$demand = max((int) $volume, $impressions);
		$demandSource = match (true) {
			$volume === null && $impressions === 0 => 'unknown',
			$impressions > (int) $volume => 'gsc_impressions',
			default => 'volume',
		};

		$components['demand'] = ['value' => round(self::DEMAND_MAX * self::log((int) $demand, self::DEMAND_CAP), 1), 'max' => self::DEMAND_MAX, 'input' => $demand, 'source' => $demandSource];

		// Potencjał.
		$components['potential'] = ['value' => $this->potential($facts, $decision), 'max' => self::POTENTIAL_MAX];

		// Pilność.
		$decline = $facts->serpDecline();
		$urgency = $decline === null ? 0.0 : 10.0 * self::log($decline['lost'], self::DECLINE_CAP);
		$urgency += $facts->hasOpportunity(OpportunityType::Decline) ? 5.0 : 0.0;
		$urgency += $decision->action === StrategyAction::Consolidate ? 5.0 : 0.0;
		$components['urgency'] = ['value' => round(min(self::URGENCY_MAX, $urgency), 1), 'max' => self::URGENCY_MAX, 'lost_positions' => $decline['lost'] ?? null];

		// Konkurencja i SERP.
		$competitors = $facts->competitorsTop10();
		$contentGap = $facts->contentGap();
		$competition = min(9.0, 3.0 * $competitors) + match ($contentGap['confidence'] ?? null) {
			'high' => 6.0,
			'medium' => 3.0,
			default => 0.0,
		};
		$components['competition'] = ['value' => round(min(self::COMPETITION_MAX, $competition), 1), 'max' => self::COMPETITION_MAX, 'competitors_top10' => $competitors, 'content_gap' => $contentGap['content_gap'] ?? null];

		// Osiągalność.
		$difficulty = $facts->leader->difficulty;
		$components['attainability'] = [
			'value' => $difficulty === null ? self::ATTAINABILITY_MAX / 2 : round(self::ATTAINABILITY_MAX * (1 - min(100, max(0, $difficulty)) / 100), 1),
			'max' => self::ATTAINABILITY_MAX,
			'difficulty' => $difficulty,
			'difficulty_known' => $difficulty !== null,
		];

		// Intencja i wartość.
		$intent = $facts->leader->intent;
		$cpc = $facts->leader->cpc;
		$value = match ($intent) {
			'commercial', 'transactional' => 6.0,
			'informational' => 4.0,
			'navigational' => 2.0,
			default => 3.0,
		} + ($cpc === null ? 0.0 : 4.0 * min(1.0, log10(1 + max(0.0, $cpc)) / log10(1 + self::CPC_CAP)));
		$components['value'] = ['value' => round(min(self::VALUE_MAX, $value), 1), 'max' => self::VALUE_MAX, 'intent' => $intent, 'cpc' => $cpc];

		$raw = round(array_sum(array_column($components, 'value')), 1);
		$multiplier = round(0.6 + 0.4 * max(0, min(100, $confidence->points)) / 100, 3);
		$actionFactor = $decision->action === StrategyAction::Monitor ? self::MONITOR_FACTOR : 1.0;
		$final = (int) max(0, min(100, round($raw * $multiplier * $actionFactor)));

		return new PriorityResult($final, $raw, $multiplier, $actionFactor, $components);
	}

	public static function band(?int $priority): ?string
	{
		return match (true) {
			$priority === null => null,
			$priority >= self::HIGH_BAND => 'high',
			$priority >= self::MEDIUM_BAND => 'medium',
			default => 'low',
		};
	}

	private function potential(TopicFacts $facts, Decision $decision): float
	{
		return match ($decision->action) {
			StrategyAction::Optimize => $this->optimizePotential($facts),
			StrategyAction::Recover => match (true) {
				($facts->serpDecline()['prev_rank'] ?? 99) <= 10 => 20.0,
				($facts->serpDecline()['prev_rank'] ?? 99) <= 20 => 15.0,
				default => 12.0,
			},
			StrategyAction::Consolidate => 14.0,
			StrategyAction::Create => min(self::POTENTIAL_MAX, 10.0 + (($facts->contentGap()['confidence'] ?? null) === 'high' ? 5.0 : 0.0) + ($facts->competitorsTop10() >= 3 ? 5.0 : 0.0)),
			StrategyAction::Monitor => 2.0,
			StrategyAction::Investigate => 6.0,
		};
	}

	private function optimizePotential(TopicFacts $facts): float
	{
		$position = $facts->primarySerp()?->serpRank() ?? $facts->gscPosition();
		$value = match (true) {
			$position === null => 6.0,
			$position <= 10 => 20.0,
			$position <= 20 => 16.0,
			$position <= 30 => 12.0,
			$position <= 50 => 8.0,
			default => 4.0,
		};

		if ($facts->hasOpportunity(OpportunityType::LowCtr)) {
			$value = max($value, 14.0);
		}

		if ($facts->gapWeak() || ($facts->contentGap()['content_gap'] ?? null) === 'improve') {
			$value = max($value, 10.0);
		}

		return $value;
	}

	/** Wspólna normalizacja logarytmiczna 0–1 (rosnąca, z limitem). */
	public static function log(int|float $value, int|float $cap): float
	{
		return $value <= 0 ? 0.0 : min(1.0, log10(1 + $value) / log10(1 + $cap));
	}
}
