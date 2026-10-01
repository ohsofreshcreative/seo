<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Priorytet 0–100: „jak bardzo warto to sprawdzić”, nie prognoza wzrostu.
 *
 * Priorytet = Popyt (0–30) + Skala (0–50) + Trend (0–20), każdy składnik ograniczony:
 *
 * - Popyt = 30 × L(wyświetlenia, demand_cap), gdzie L(x, cap) = min(1, log10(1 + x) / log10(1 + cap)) —
 *   skala logarytmiczna z limitem: jedna ogromna fraza nie dominuje wszystkiego liniowo,
 * - Skala (zależna od typu):
 *   - niski CTR: 30 × L(luka kliknięć, clicks_cap) + 20 × (1 − min(1, CTR / CTR referencyjny)),
 *   - blisko TOP / słaba pozycja: 30 × L(potencjał kliknięć, clicks_cap) + 20 × bliskość celu,
 *   - spadek: 30 × L(utracone kliknięcia, clicks_cap) + 20 × min(1, spadek względny),
 *   - kanibalizacja: 30 × wyrównanie podziału wyświetleń + 20 × min(1, (liczba fraz − 1) / 4),
 * - Trend (zależny od typu):
 *   - niski CTR: 20 × min(1, spadek CTR względem poprzedniego okresu / 50%),
 *   - blisko TOP / słaba pozycja: 10 × min(1, wzrost wyświetleń) + 10 × min(1, poprawa pozycji / 5),
 *   - spadek: 20 × min(1, pogorszenie średniej pozycji / 5),
 *   - kanibalizacja: 20 × udział fraz ze zmianą dominującego adresu.
 *
 * Limity (demand_cap, clicks_cap) są skalowane do długości okresu (OpportunityConfig).
 */
final class OpportunityScorer
{
	public const DEMAND_MAX = 30;

	public const SIZE_MAX = 50;

	public const TREND_MAX = 20;

	public function __construct(private readonly OpportunityConfig $config)
	{
	}

	/**
	 * @param array<string, float|int|null> $inputs wejścia zależne od typu (patrz metody poniżej)
	 * @return array{total: int, demand: float, size: float, trend: float, inputs: array<string, float|int|null>}
	 */
	public function score(OpportunityType $type, array $inputs, int $days): array
	{
		$clicksCap = $this->config->volume('clicks_cap', $days);
		$demand = self::DEMAND_MAX * self::logScore((float) ($inputs['impressions'] ?? 0), $this->config->volume('demand_cap', $days));

		[$size, $trend] = match ($type) {
			OpportunityType::LowCtr => [
				30 * self::logScore((float) ($inputs['click_gap'] ?? 0), $clicksCap)
					+ 20 * (1 - min(1.0, max(0.0, (float) ($inputs['ctr_ratio'] ?? 1)))),
				self::TREND_MAX * self::clamp(((float) ($inputs['ctr_drop'] ?? 0)) / 0.5),
			],
			OpportunityType::NearTop, OpportunityType::WeakPosition => [
				30 * self::logScore((float) ($inputs['potential_clicks'] ?? 0), $clicksCap)
					+ 20 * self::clamp((float) ($inputs['proximity'] ?? 0)),
				10 * self::clamp((float) ($inputs['impressions_growth'] ?? 0))
					+ 10 * self::clamp(((float) ($inputs['position_improvement'] ?? 0)) / 5),
			],
			OpportunityType::Decline => [
				30 * self::logScore((float) ($inputs['clicks_lost'] ?? 0), $clicksCap)
					+ 20 * self::clamp((float) ($inputs['relative_loss'] ?? 0)),
				self::TREND_MAX * self::clamp(((float) ($inputs['position_worsening'] ?? 0)) / 5),
			],
			OpportunityType::Cannibalization => [
				30 * self::clamp((float) ($inputs['balance'] ?? 0))
					+ 20 * self::clamp((((float) ($inputs['queries'] ?? 1)) - 1) / 4),
				self::TREND_MAX * self::clamp((float) ($inputs['switch_share'] ?? 0)),
			],
		};

		$total = (int) round($demand + $size + $trend);

		return [
			'total' => max(0, min(100, $total)),
			'demand' => round($demand, 1),
			'size' => round($size, 1),
			'trend' => round($trend, 1),
			'inputs' => array_map(static fn (mixed $value): mixed => is_float($value) ? round($value, 4) : $value, $inputs),
		];
	}

	/** L(x, cap): 0 dla x ≤ 0, 1 dla x ≥ cap, logarytmicznie pomiędzy. */
	public static function logScore(float $value, float $cap): float
	{
		if ($value <= 0 || $cap <= 0) {
			return 0.0;
		}

		return min(1.0, log10(1 + $value) / log10(1 + $cap));
	}

	public static function clamp(float $value): float
	{
		return max(0.0, min(1.0, $value));
	}
}
