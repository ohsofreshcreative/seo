<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Priorytet odkrycia 0–100 — „jak bardzo warto przyjrzeć się frazie”, nie prognoza ruchu ani wartość biznesowa
 * (docs/ARCHITECTURE.md, sekcja 12.7). Składniki ograniczone; wolumen i CPC w skali logarytmicznej z limitem
 * `L(x, cap) = min(1, log10(1 + x) / log10(1 + cap))`, więc jedna ogromna fraza nie dominuje liniowo:
 *
 * - Popyt (0–35) = 35 × L(wolumen, 10 000); brak wolumenu = 0,
 * - Osiągalność (0–25) = 25 × (1 − trudność SEO / 100); brak trudności = 12,5 (neutralnie),
 * - Trafność (0–20) = 20 × min(1, najsilniejsze powiązanie z seedem + 0,1 za każdy kolejny seed, który dał frazę),
 * - Luka GSC (0–15): brak widoczności 15; słaba widoczność 15 × max(0,2; min(1; (pozycja − próg) / 40)),
 *   sporadyczna 6; nieznana 7,5; już widoczna 0,
 * - Sygnał komercyjny (0–5) = 5 × L(CPC, 10 USD) — celowo mała waga: CPC nie może dominować priorytetu SEO.
 */
final class DiscoveryScorer
{
	public const MAX_POINTS = ['demand' => 35, 'attainability' => 25, 'relevance' => 20, 'gap' => 15, 'commercial' => 5];

	public const VOLUME_CAP = 10000;

	public const CPC_CAP = 10.0;

	public function __construct(private readonly float $visiblePosition = 10.0)
	{
	}

	public function score(?int $searchVolume, ?int $difficulty, ?float $cpc, int $bestRelation, int $seeds, VisibilityResult $visibility): DiscoveryScore
	{
		$relation = max(0, min(100, $bestRelation)) / 100;
		$components = [
			'demand' => 35 * ($searchVolume === null ? 0.0 : self::log($searchVolume, self::VOLUME_CAP)),
			'attainability' => $difficulty === null ? 12.5 : 25 * (1 - max(0, min(100, $difficulty)) / 100),
			'relevance' => 20 * min(1.0, $relation + 0.1 * max(0, $seeds - 1)),
			'gap' => $this->gap($visibility),
			'commercial' => 5 * ($cpc === null ? 0.0 : self::log($cpc, self::CPC_CAP)),
		];
		$components = array_map(static fn (float $value): float => round($value, 1), $components);

		return new DiscoveryScore(max(0, min(100, (int) round(array_sum($components)))), $components);
	}

	/** L(x, cap) — skala logarytmiczna z limitem (0–1). */
	public static function log(float $value, float $cap): float
	{
		return $value <= 0 ? 0.0 : min(1.0, log10(1 + $value) / log10(1 + $cap));
	}

	private function gap(VisibilityResult $visibility): float
	{
		return match ($visibility->visibility) {
			Visibility::None => 15.0,
			Visibility::Unknown => 7.5,
			Visibility::Visible => 0.0,
			Visibility::Low => $visibility->sporadic || $visibility->position === null
				? 6.0
				: 15 * max(0.2, min(1.0, ($visibility->position - $this->visiblePosition) / 40)),
		};
	}
}
