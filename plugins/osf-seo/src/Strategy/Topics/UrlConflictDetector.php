<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Opportunities\Confidence;
use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Strategy\Target\TargetPageResolver;
use OsfSeo\Strategy\Target\TargetResolution;
use OsfSeo\Strategy\Target\TargetState;

/**
 * Sygnały konfliktu URL frazy (faza C — docs/ARCHITECTURE.md, sekcja 15.13). Każdy z siłą; nie każda zmiana adresu to kanibalizacja:
 *
 * - szansa „Możliwa kanibalizacja” (bezpośrednio): pewność co najmniej średnia → silny, niska → umiarkowany,
 * - podział wyświetleń GSC: dwie strony z udziałem ≥ 30% i bez dominującej (≥ 60%), fraza z ≥ 100 wyświetleniami, średnia pozycja (GSC)
 *   poza TOP3 → silny; druga strona ≥ 20% → umiarkowany (w TOP3 — słaby: dwa wyniki na górze to zwykle nie problem),
 * - adres rankujący ≠ potwierdzona strona docelowa: w dwóch porównywalnych pomiarach → silny, w jednym → umiarkowany,
 * - pojedyncza zmiana adresu rankującego → słaby (`url_flip`),
 * - kilka adresów projektu w TOP10 świeżego pomiaru → umiarkowany (wszystkie w TOP3 — słaby).
 */
final class UrlConflictDetector
{
	public const SPLIT_SHARE = 0.3;

	public const SCATTER_SHARE = 0.2;

	public const SPLIT_MIN_IMPRESSIONS = 100;

	public const SPLIT_MIN_PAGE_IMPRESSIONS = 20;

	public const TOP_POSITION = 3.0;

	/**
	 * @return list<ConflictSignal>
	 */
	public function detect(KeywordSignals $keyword, TargetResolution $target): array
	{
		$signals = [];

		foreach ($keyword->opportunities() as $opportunity) {
			if (($opportunity['type'] ?? null) !== OpportunityType::Cannibalization->value) {
				continue;
			}

			$urls = array_values(array_filter((array) ($opportunity['urls'] ?? [$opportunity['page'] ?? null]), 'is_string'));
			$signals[] = new ConflictSignal(
				ConflictSignal::CANNIBALIZATION,
				(int) ($opportunity['confidence'] ?? 0) >= Confidence::Medium->value ? ConflictSignal::STRONG : ConflictSignal::MODERATE,
				$urls,
				$keyword->keyword,
				['opportunity' => $opportunity['id'] ?? null, 'confidence' => (int) ($opportunity['confidence'] ?? 0)],
			);
		}

		$split = $this->split($keyword);

		if ($split !== null) {
			$signals[] = $split;
		}

		$serpUrl = $keyword->serpUrl();
		$change = $keyword->serpChange();

		if ($target->state === TargetState::Confirmed && $target->url !== null && $serpUrl !== null && $serpUrl !== $target->url) {
			$consistent = $change !== null && $change['prev_url'] !== null && $change['prev_url'] !== $target->url;
			$signals[] = new ConflictSignal(
				ConflictSignal::TARGET_MISMATCH,
				$consistent ? ConflictSignal::STRONG : ConflictSignal::MODERATE,
				[$target->url, $serpUrl],
				$keyword->keyword,
				['rank' => $keyword->serpRank(), 'consistent' => $consistent],
			);
		}

		if ($change !== null && $change['prev_url'] !== null && $serpUrl !== null && $change['prev_url'] !== $serpUrl) {
			$signals[] = new ConflictSignal(ConflictSignal::URL_FLIP, ConflictSignal::WEAK, [$change['prev_url'], $serpUrl], $keyword->keyword, ['prev_rank' => $change['prev_rank'], 'rank' => $keyword->serpRank()]);
		}

		$top10 = $keyword->serpTop10();

		if (count($top10) >= 2) {
			$allTop = max(array_column($top10, 'rank')) <= (int) self::TOP_POSITION;
			$signals[] = new ConflictSignal(
				ConflictSignal::MULTIPLE_TOP10,
				$allTop ? ConflictSignal::WEAK : ConflictSignal::MODERATE,
				array_column($top10, 'url'),
				$keyword->keyword,
				['ranks' => array_column($top10, 'rank')],
			);
		}

		return $signals;
	}

	private function split(KeywordSignals $keyword): ?ConflictSignal
	{
		$pages = array_values(array_filter($keyword->gscPages(), static fn (array $page): bool => $page['share'] !== null && $page['impressions'] >= self::SPLIT_MIN_PAGE_IMPRESSIONS));

		if (count($pages) < 2) {
			return null;
		}

		[$first, $second] = $pages;
		$impressions = (int) $keyword->gscImpressions();
		$position = $keyword->gscPosition();
		$atTop = $position !== null && $position <= self::TOP_POSITION;

		if ($second['share'] < self::SCATTER_SHARE) {
			return null;
		}

		$strong = $second['share'] >= self::SPLIT_SHARE && $first['share'] < TargetPageResolver::GSC_DOMINANT_SHARE && $impressions >= self::SPLIT_MIN_IMPRESSIONS && ! $atTop;

		return new ConflictSignal(
			ConflictSignal::GSC_SPLIT,
			$strong ? ConflictSignal::STRONG : ($atTop ? ConflictSignal::WEAK : ConflictSignal::MODERATE),
			[$first['url'], $second['url']],
			$keyword->keyword,
			['shares' => [$first['share'], $second['share']], 'impressions' => $impressions, 'position_gsc' => $position],
		);
	}
}
