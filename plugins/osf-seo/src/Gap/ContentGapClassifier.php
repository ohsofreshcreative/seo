<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Heurystyka luki treści grupy fraz (docs/ARCHITECTURE.md, sekcja 14.10) — bez AI, z pewnością zamiast twierdzeń:
 *
 * 1. brak danych projektu → Niejasne (`no_project_data`),
 * 2. wyświetlenia rozproszone na kilka stron → Niejasne (`scattered`),
 * 3. strona docelowa istnieje:
 *    - fraza wiodąca Porównywalna lub Projekt silniejszy i < 50% fraz z luką → Bez luki treści,
 *    - jedyną stroną jest strona główna, a konkurenci rankują podstronami → Potencjalna luka treści (`homepage_only`),
 *    - w pozostałych przypadkach → Istniejąca strona — do wzmocnienia (`target_<źródło>`),
 * 4. brak strony docelowej:
 *    - konkurenci rankują podstronami i wolumen luk grupy ≥ 50 → Potencjalna luka treści (`no_target`),
 *    - konkurenci rankują wyłącznie stronami głównymi → Niejasne (`competitors_homepages`),
 *    - inaczej → Niejasne (`low_demand`).
 */
final class ContentGapClassifier
{
	public function classify(ClusterEvidence $evidence): ContentGapResult
	{
		if (! $evidence->hasProjectData) {
			return new ContentGapResult(ContentGap::Unclear, 'no_project_data', 'low');
		}

		if ($evidence->scattered) {
			return new ContentGapResult(ContentGap::Unclear, 'scattered', 'low');
		}

		if ($evidence->targetUrlId !== null) {
			if (in_array($evidence->leaderType, [GapType::Competitive, GapType::Stronger], true) && $evidence->gapShare < 0.5) {
				return new ContentGapResult(ContentGap::Covered, 'covered', 'medium');
			}

			if ($evidence->targetIsRoot && $evidence->competitorPages >= 1) {
				return new ContentGapResult(ContentGap::NewPage, 'homepage_only', $evidence->dedicatedPages >= 2 ? 'medium' : 'low');
			}

			return new ContentGapResult(ContentGap::Improve, 'target_' . (string) $evidence->targetSource, match ($evidence->targetSource) {
				ProjectEvidence::SOURCE_SERP, ProjectEvidence::SOURCE_GSC => 'high',
				ProjectEvidence::SOURCE_LABS => 'medium',
				default => 'low',
			});
		}

		if ($evidence->competitorPages >= 1 && $evidence->gapVolume >= GapConfig::MIN_CONTENT_GAP_VOLUME) {
			$confidence = match (true) {
				$evidence->dedicatedPages >= 2 && $evidence->baselineReliable && $evidence->hasGsc => 'high',
				$evidence->hasGsc || $evidence->baselineReliable => 'medium',
				default => 'low',
			};

			return new ContentGapResult(ContentGap::NewPage, 'no_target', $confidence);
		}

		if ($evidence->competitorPages === 0) {
			return new ContentGapResult(ContentGap::Unclear, 'competitors_homepages', 'low');
		}

		return new ContentGapResult(ContentGap::Unclear, 'low_demand', 'low');
	}

	/**
	 * Strona docelowa grupy: pomiar SERP (najczęstszy adres projektu) → GSC (strona z ≥ 60% wyświetleń grupy, co najmniej
	 * próg wyświetleń; bez dominującej, gdy druga ma ≥ 20% → rozproszone) → Labs (adres ważony wolumenem) → dopasowanie
	 * adresu. Zwraca [id adresu, źródło, rozproszone].
	 *
	 * @param array<int, int> $serp id adresu → liczba fraz grupy
	 * @param array<int, int> $gsc id adresu → wyświetlenia fraz grupy
	 * @param array<int, int> $labs id adresu → suma wolumenu fraz grupy
	 * @return array{0: ?int, 1: ?string, 2: bool}
	 */
	public static function target(array $serp, array $gsc, array $labs, ?int $slug, int $minImpressions): array
	{
		if ($serp !== []) {
			return [self::top($serp), ProjectEvidence::SOURCE_SERP, false];
		}

		$total = array_sum($gsc);

		if ($total > 0) {
			arsort($gsc);
			$pages = array_keys($gsc);
			$first = $gsc[$pages[0]];
			$second = isset($pages[1]) ? $gsc[$pages[1]] : 0;

			if ($first >= $minImpressions && $first / $total >= GapConfig::TARGET_SHARE) {
				return [(int) $pages[0], ProjectEvidence::SOURCE_GSC, false];
			}

			if ($first >= $minImpressions && $second / $total >= GapConfig::SCATTER_SHARE) {
				return [null, null, true];
			}
		}

		if ($labs !== []) {
			return [self::top($labs), ProjectEvidence::SOURCE_LABS, false];
		}

		return $slug === null ? [null, null, false] : [$slug, 'slug', false];
	}

	/**
	 * @param array<int, int> $weights
	 */
	private static function top(array $weights): int
	{
		$best = null;

		foreach ($weights as $id => $weight) {
			if ($best === null || $weight > $weights[$best] || ($weight === $weights[$best] && $id < $best)) {
				$best = $id;
			}
		}

		return (int) $best;
	}
}
