<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Priorytet luki 0–100 — „jak bardzo warto sprawdzić tę frazę jako potencjalną lukę SEO”, nie prognoza ruchu, wartość
 * biznesowa ani prawdopodobieństwo konwersji (docs/ARCHITECTURE.md, sekcja 14.9). Składniki ograniczone; wolumen i CPC
 * w skali logarytmicznej z limitem `L(x, cap) = min(1, log10(1 + x) / log10(1 + cap))`, więc ogromny wolumen nie dominuje:
 *
 * - Popyt (0–25) = 25 × L(wolumen, 10 000); brak wolumenu = 0,
 * - Osiągalność (0–15) = 15 × (1 − KD / 100); brak KD = 7,5,
 * - Dowód konkurencji (0–20) = 12 × f(C) + 8 × min(1, (n − 1) / 3); f: C 1–3 = 1; 4–10 = 0,85; 11–20 = 0,6; 21–30 = 0,3;
 *   dalej 0,15 (n — konkurenci w progu znaczącej pozycji),
 * - Luka projektu (0–30): brak 30; słaba 30 × max(0,4; min(1; (P − C) / 30)); sporadyczna 15; nieznana 15;
 *   porównywalna 4; projekt silniejszy 0,
 * - Intencja (0–5): transakcyjna lub komercyjna 5, informacyjna 3, nawigacyjna 0, brak 2,5,
 * - Sygnał CPC (0–5) = 5 × L(CPC, 10 USD) — celowo mała waga.
 *
 * Mnożnik typu: Porównywalna × 0,6, Projekt silniejszy × 0,3 — frazy bez luki nie wypierają luk z góry listy.
 * Priorytet szans SEO i priorytet odkrycia (Nowe frazy) pozostają bez zmian.
 */
final class GapScorer
{
	public const MAX_POINTS = ['demand' => 25, 'attainability' => 15, 'evidence' => 20, 'gap' => 30, 'intent' => 5, 'commercial' => 5];

	public const VOLUME_CAP = 10000;

	public const CPC_CAP = 10.0;

	public function score(GapType $type, ProjectEvidence $project, int $competitorRank, int $competitors, ?int $searchVolume, ?int $difficulty, ?float $cpc, ?string $intent): GapScore
	{
		$components = [
			'demand' => 25 * ($searchVolume === null ? 0.0 : self::log($searchVolume, self::VOLUME_CAP)),
			'attainability' => $difficulty === null ? 7.5 : 15 * (1 - max(0, min(100, $difficulty)) / 100),
			'evidence' => 12 * self::rankFactor($competitorRank) + 8 * min(1.0, max(0, $competitors - 1) / 3),
			'gap' => $this->gap($type, $project, $competitorRank),
			'intent' => match ($intent) {
				'transactional', 'commercial' => 5.0,
				'informational' => 3.0,
				'navigational' => 0.0,
				default => 2.5,
			},
			'commercial' => 5 * ($cpc === null ? 0.0 : self::log($cpc, self::CPC_CAP)),
		];
		$components = array_map(static fn (float $value): float => round($value, 1), $components);
		$multiplier = match ($type) {
			GapType::Competitive => 0.6,
			GapType::Stronger => 0.3,
			default => 1.0,
		};

		return new GapScore(max(0, min(100, (int) round(array_sum($components) * $multiplier))), $components, $multiplier);
	}

	/** L(x, cap) — skala logarytmiczna z limitem (0–1). */
	public static function log(float $value, float $cap): float
	{
		return $value <= 0 ? 0.0 : min(1.0, log10(1 + $value) / log10(1 + $cap));
	}

	public static function rankFactor(int $rank): float
	{
		return match (true) {
			$rank <= 3 => 1.0,
			$rank <= 10 => 0.85,
			$rank <= 20 => 0.6,
			$rank <= 30 => 0.3,
			default => 0.15,
		};
	}

	/** Priorytet grupy: 0,7 × najwyższy priorytet frazy + 30 × L(wolumen luk grupy, 50 000). */
	public static function clusterPriority(int $maxKeywordPriority, int $gapVolume): int
	{
		return max(0, min(100, (int) round(0.7 * $maxKeywordPriority + 30 * self::log($gapVolume, 50000))));
	}

	private function gap(GapType $type, ProjectEvidence $project, int $competitorRank): float
	{
		return match ($type) {
			GapType::Missing => 30.0,
			GapType::Weak => $project->sporadic || $project->position === null
				? 15.0
				: 30 * max(0.4, min(1.0, ($project->position - $competitorRank) / 30)),
			GapType::Unknown => 15.0,
			GapType::Competitive => 4.0,
			GapType::Stronger => 0.0,
		};
	}
}
