<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Opportunities;

use OsfSeo\Opportunities\Confidence;
use OsfSeo\Opportunities\Fingerprint;
use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Opportunities\Stats;

/**
 * Wykrywanie szans na danych wyliczonych ręcznie. Okres 28 dni: bieżący 2026-03-01..2026-03-28,
 * poprzedni 2026-02-01..2026-02-28. Metryki: [kliknięcia, wyświetlenia, średnia pozycja (GSC)].
 * Referencyjny CTR przy małej próbie = wartości domyślne (pozycja 1: 28%, 2–3: 14%, 4–5: 7%, 6–10: 3%, 11–20: 1%).
 */
final class OpportunityDetectorTest extends DetectorTestCase
{
	private const PAGE = 'https://example.pl/buty/';

	public function test_low_ctr_is_detected_against_position_reference(): void
	{
		$candidates = self::detector()->detect(self::input(
			[self::keyword(1, 'buty damskie', [20, 2000, 2.0], [22, 2000, 2.0])],
			[self::pair(1, self::PAGE, [20, 2000, 2.0], [22, 2000, 2.0])],
		));

		$lowCtr = self::ofType($candidates, OpportunityType::LowCtr);
		self::assertCount(1, $lowCtr);
		self::assertSame(self::PAGE, $lowCtr[0]->pageUrl);
		self::assertSame(['buty damskie'], self::keywordsOf($lowCtr[0]));

		$item = $lowCtr[0]->evidence['keywords'][0];
		self::assertSame(0.14, $item['reference_ctr'], 'Przedział 2–3 bez próbki projektu → domyślne 14%.');
		self::assertSame('2–3', $item['bucket']);
		self::assertEqualsWithDelta(280.0, $item['expected_clicks'], 1e-9, '2000 × 14%');
		self::assertEqualsWithDelta(260.0, $item['click_gap'], 1e-9, '280 − 20');
		self::assertEqualsWithDelta(20 / 280, $lowCtr[0]->evidence['details']['ctr_ratio'], 1e-4);
	}

	public function test_low_ctr_depends_on_average_position(): void
	{
		// Ten sam CTR 1% (20 / 2000): na pozycji 2 to wyraźnie poniżej referencji, na pozycji 15 — typowy wynik.
		$candidates = self::detector()->detect(self::input([
			self::keyword(1, 'wysoko', [20, 2000, 2.0], [20, 2000, 2.0]),
			self::keyword(2, 'nisko', [20, 2000, 15.0], [20, 2000, 15.0]),
		]));

		$lowCtr = self::ofType($candidates, OpportunityType::LowCtr);
		self::assertCount(1, $lowCtr);
		self::assertSame('„wysoko”', \OsfSeo\Opportunities\OpportunityExplainer::title($lowCtr[0]->evidence), 'Bez danych o stronie — grupa = fraza.');
	}

	public function test_low_ctr_reference_comes_from_project_data_when_sample_is_large_enough(): void
	{
		// 8 fraz na pozycjach 2–3 z CTR 3% → mediana projektu 3% zamiast domyślnych 14%.
		$keywords = [];

		for ($i = 1; $i <= 8; $i++) {
			$keywords[] = self::keyword($i, "fraza {$i}", [3, 100, 2.5], [3, 100, 2.5]);
		}

		// CTR 2%: względem 14% byłby „niski” (0,14 ratio), względem mediany projektu 3% — nie (0,67 > 0,6).
		$keywords[] = self::keyword(99, 'badana', [40, 2000, 2.5], [40, 2000, 2.5]);
		$lowCtr = self::ofType(self::detector()->detect(self::input($keywords)), OpportunityType::LowCtr);

		self::assertSame([], array_filter($lowCtr, static fn ($candidate): bool => $candidate->keyword === 'badana'));

		$withDefaults = self::ofType(self::detector(['reference_min_keywords' => 50])->detect(self::input($keywords)), OpportunityType::LowCtr);
		self::assertContains('badana', array_map(static fn ($candidate): ?string => $candidate->keyword, $withDefaults));
	}

	public function test_near_top_detects_both_targets_and_ignores_low_volume(): void
	{
		$candidates = self::detector()->detect(self::input(
			[
				self::keyword(1, 'blisko top3', [30, 1500, 4.8], [28, 1400, 5.0]),
				self::keyword(2, 'mała próba', [0, 3, 9.8], [0, 3, 9.8]),
				self::keyword(3, 'blisko top10', [2, 400, 12.0], [2, 380, 12.5]),
			],
			[
				self::pair(1, self::PAGE, [30, 1500, 4.8]),
				self::pair(2, self::PAGE, [0, 3, 9.8]),
				self::pair(3, self::PAGE, [2, 400, 12.0]),
			],
		));

		$nearTop = self::ofType($candidates, OpportunityType::NearTop);
		self::assertCount(1, $nearTop, 'Jedna karta na podstronę.');
		self::assertSame(['blisko top3', 'blisko top10'], self::keywordsOf($nearTop[0]), 'Kolejność: potencjał kliknięć (1500 × 14% − 30 = 180 vs 400 × 3% − 2 = 10).');
		self::assertSame(1, $nearTop[0]->evidence['details']['top3']);
		self::assertSame(1, $nearTop[0]->evidence['details']['top10']);
		self::assertSame(3, $nearTop[0]->evidence['keywords'][0]['target']);
		self::assertSame(10, $nearTop[0]->evidence['keywords'][1]['target']);
		self::assertEqualsWithDelta(180.0, $nearTop[0]->evidence['keywords'][0]['potential_clicks'], 1e-9);
	}

	public function test_near_top_and_weak_position_ranges_are_disjoint(): void
	{
		$candidates = self::detector()->detect(self::input([
			self::keyword(1, 'pozycja 18', [1, 500, 18.0], [1, 500, 18.0]),
			self::keyword(2, 'pozycja 35', [0, 500, 35.0], [0, 500, 35.0]),
			self::keyword(3, 'pozycja 35 mało', [0, 100, 35.0], [0, 100, 35.0]),
			self::keyword(4, 'pozycja 3', [50, 500, 3.0], [50, 500, 3.0]),
		]));

		self::assertSame(['pozycja 18'], array_map(static fn ($candidate): ?string => $candidate->keyword, self::ofType($candidates, OpportunityType::NearTop)));
		self::assertSame(['pozycja 35'], array_map(static fn ($candidate): ?string => $candidate->keyword, self::ofType($candidates, OpportunityType::WeakPosition)), 'Próg wyświetleń 200 / 28 dni; pozycja 3,0 jest już w TOP 3.');
	}

	public function test_meaningful_declines_are_detected_and_noise_is_suppressed(): void
	{
		$candidates = self::detector()->detect(self::input([
			self::keyword(1, 'jedno kliknięcie', [0, 50, 5.0], [1, 50, 5.0]),
			self::keyword(2, 'dwa wyświetlenia', [0, 0, 0.0], [0, 2, 8.0]),
			self::keyword(3, 'mała zmiana pozycji', [0, 20, 7.3], [0, 20, 7.1]),
			self::keyword(4, 'mała zmiana duża fraza', [10, 1000, 7.3], [10, 1000, 7.1]),
			self::keyword(5, 'spadek kliknięć', [20, 900, 3.2], [50, 1000, 3.0]),
			self::keyword(6, 'utracona', [0, 0, 0.0], [15, 300, 6.0]),
			self::keyword(7, 'spadek pozycji', [3, 400, 9.0], [5, 400, 4.0]),
		]));

		$declines = self::ofType($candidates, OpportunityType::Decline);
		$keywords = array_map(static fn ($candidate): ?string => $candidate->keyword, $declines);
		sort($keywords);

		self::assertSame(['spadek kliknięć', 'spadek pozycji', 'utracona'], $keywords);

		$byKeyword = array_column(array_map(static fn ($candidate): array => [$candidate->keyword, $candidate], $declines), 1, 0);
		self::assertSame(['clicks' => 30], $byKeyword['spadek kliknięć']->evidence['keywords'][0]['signals']);
		self::assertSame(['clicks' => 15, 'impressions' => 300], $byKeyword['utracona']->evidence['keywords'][0]['signals']);
		self::assertSame(['position' => 5.0], $byKeyword['spadek pozycji']->evidence['keywords'][0]['signals']);
	}

	public function test_declining_keyword_is_not_also_reported_as_near_top(): void
	{
		$candidates = self::detector()->detect(self::input([self::keyword(1, 'spada', [20, 900, 4.5], [50, 1000, 3.0])]));

		self::assertCount(1, self::ofType($candidates, OpportunityType::Decline));
		self::assertSame([], self::ofType($candidates, OpportunityType::NearTop));
	}

	public function test_missing_previous_period_disables_declines_and_lowers_confidence(): void
	{
		$candidates = self::detector()->detect(self::input(
			[self::keyword(1, 'spada', [20, 3000, 4.5], [50, 3000, 3.0])],
			previousCovered: false,
		));

		self::assertSame([], self::ofType($candidates, OpportunityType::Decline), 'Bez pełnego poprzedniego okresu nie ma porównania.');
		$nearTop = self::ofType($candidates, OpportunityType::NearTop)[0];
		self::assertSame('not_covered', $nearTop->evidence['confidence']['factors'][1]['reason']);
		self::assertFalse($nearTop->evidence['period']['previous_covered']);
		self::assertSame(0.0, $nearTop->evidence['score']['trend'], 'Bez porównania trend = 0.');
		self::assertSame(Confidence::Medium, $nearTop->confidence, 'Duża próba (2 pkt), brak porównania i spójności.');
	}

	public function test_whole_page_decline_without_single_keyword_above_thresholds(): void
	{
		$keywords = [];
		$pairs = [];

		for ($i = 1; $i <= 10; $i++) {
			// Każda fraza: 10 → 6 kliknięć, 500 → 400 wyświetleń — za mało na spadek frazy (strata 4 < 5, 20% < 30%).
			$keywords[] = self::keyword($i, "fraza {$i}", [6, 400, 25.0], [10, 500, 25.0]);
			$pairs[] = self::pair($i, 'https://example.pl/x/', [6, 400, 25.0], [10, 500, 25.0]);
		}

		$candidates = self::detector()->detect(self::input($keywords, $pairs, [
			'https://example.pl/x/' => [self::stats([60, 4000, 25.0]), self::stats([100, 5000, 25.0])],
		]));

		$declines = self::ofType($candidates, OpportunityType::Decline);
		self::assertCount(1, $declines);
		self::assertSame('https://example.pl/x/', $declines[0]->pageUrl);
		self::assertSame(['clicks' => 40], $declines[0]->evidence['details']['page_signals']);
		self::assertSame(10, $declines[0]->evidence['keywords_total'], 'Dowody: frazy podstrony ze stratą.');
		self::assertTrue($declines[0]->evidence['keywords'][0]['context']);
		self::assertSame(100, $declines[0]->evidence['metrics']['previous']['clicks'], 'Metryki grupy = sumy podstrony.');
	}

	public function test_cannibalization_candidate_with_meaningful_split(): void
	{
		$candidates = self::detector()->detect(self::input(
			[self::keyword(1, 'kanibal', [18, 500, 7.0], [22, 450, 5.5])],
			[
				self::pair(1, 'https://example.pl/a/', [10, 300, 6.0], [20, 400, 5.0]),
				self::pair(1, 'https://example.pl/b/', [8, 200, 9.0], [2, 50, 12.0]),
			],
		));

		$cannibalization = self::ofType($candidates, OpportunityType::Cannibalization);
		self::assertCount(1, $cannibalization);
		self::assertSame('https://example.pl/a/', $cannibalization[0]->pageUrl, 'Główny adres = więcej wyświetleń.');
		self::assertSame(['https://example.pl/a/', 'https://example.pl/b/'], $cannibalization[0]->evidence['entity']['urls']);

		$query = $cannibalization[0]->evidence['keywords'][0];
		self::assertSame('kanibal', $query['keyword']);
		self::assertSame([0.6, 0.4], array_column($query['urls'], 'share'));
		self::assertFalse($query['dominant_changed']);
		self::assertSame(1, $query['previous_meaningful_urls'], 'W poprzednim okresie /b/ miał 11% udziału — poniżej progu.');
		self::assertSame(0, $cannibalization[0]->evidence['confidence']['factors'][2]['points'], 'Brak utrwalonego podziału → bez punktu spójności.');
	}

	public function test_multi_url_cases_that_are_not_meaningful_cannibalization(): void
	{
		$candidates = self::detector()->detect(self::input(
			[
				self::keyword(1, 'dominacja', [50, 1000, 4.0]),
				self::keyword(2, 'marka', [400, 900, 1.3]),
				self::keyword(3, 'mało', [1, 60, 8.0]),
			],
			[
				// 95% / 5% — drugi adres bez istotnego udziału.
				self::pair(1, 'https://example.pl/a/', [48, 950, 4.0]),
				self::pair(1, 'https://example.pl/c/', [2, 50, 9.0]),
				// Oba adresy w ścisłej czołówce (sitelinki / podwójny wynik) — nie problem.
				self::pair(2, 'https://example.pl/', [300, 500, 1.2]),
				self::pair(2, 'https://example.pl/kontakt/', [100, 400, 1.4]),
				// Za mało wyświetleń frazy (60 < 100).
				self::pair(3, 'https://example.pl/a/', [1, 30, 8.0]),
				self::pair(3, 'https://example.pl/b/', [0, 30, 8.0]),
			],
		));

		self::assertSame([], self::ofType($candidates, OpportunityType::Cannibalization));
	}

	public function test_cannibalization_queries_of_the_same_url_pair_form_one_opportunity(): void
	{
		$candidates = self::detector()->detect(self::input(
			[self::keyword(1, 'pierwsza', [10, 500, 7.0]), self::keyword(2, 'druga', [5, 300, 8.0])],
			[
				self::pair(1, 'https://example.pl/a/', [6, 300, 6.0]),
				self::pair(1, 'https://example.pl/b/', [4, 200, 9.0]),
				self::pair(2, 'https://example.pl/b/', [3, 150, 7.0]),
				self::pair(2, 'https://example.pl/a/', [2, 150, 9.0]),
			],
		));

		$cannibalization = self::ofType($candidates, OpportunityType::Cannibalization);
		self::assertCount(1, $cannibalization);
		self::assertSame(['pierwsza', 'druga'], self::keywordsOf($cannibalization[0]));
		self::assertSame(2, $cannibalization[0]->evidence['score']['inputs']['queries']);
	}

	public function test_alternating_dominant_url_strengthens_cannibalization_signal(): void
	{
		$detector = self::detector();
		$input = self::input(
			[self::keyword(1, 'zmienna', [10, 440, 7.0], [10, 440, 7.0])],
			[
				self::pair(1, 'https://example.pl/a/', [5, 220, 6.0], [8, 400, 5.0]),
				self::pair(1, 'https://example.pl/b/', [5, 220, 8.0], [2, 40, 9.0]),
			],
		);

		self::assertSame([1], $detector->cannibalizationKeywordIds($input));

		$segments = [1 => [
			'https://example.pl/a/' => [0 => 100, 1 => 10, 2 => 100, 3 => 10],
			'https://example.pl/b/' => [0 => 10, 1 => 100, 2 => 10, 3 => 100],
		]];
		$candidate = self::ofType($detector->detect($input->withSegments($segments, [])), OpportunityType::Cannibalization)[0];
		$query = $candidate->evidence['keywords'][0];

		self::assertSame([0, 1, 0, 1], $query['dominant_sequence']);
		self::assertSame(3, $query['switches']);
		self::assertSame(7, $query['segment_days']);
		self::assertSame(1, $candidate->evidence['confidence']['factors'][2]['points']);
		self::assertSame(20.0, $candidate->evidence['score']['trend'], 'Cała fraza z naprzemienną dominacją → pełny trend.');
	}

	public function test_page_groups_keep_keywords_and_types_stay_separate(): void
	{
		$keywords = [];
		$pairs = [];

		for ($i = 1; $i <= 15; $i++) {
			$keywords[] = self::keyword($i, "fraza {$i}", [10, 500 + $i, 12.0], [10, 500, 12.0]);
			$pairs[] = self::pair($i, self::PAGE, [10, 500 + $i, 12.0]);
		}

		// Ta sama podstrona: fraza z niskim CTR (inne działanie — osobna karta).
		$keywords[] = self::keyword(99, 'niski ctr', [5, 2000, 2.0], [5, 2000, 2.0]);
		$pairs[] = self::pair(99, self::PAGE, [5, 2000, 2.0]);

		$candidates = self::detector(['evidence_keywords' => 5])->detect(self::input($keywords, $pairs));
		$nearTop = self::ofType($candidates, OpportunityType::NearTop);

		self::assertCount(1, $nearTop, '15 fraz blisko TOP 10 tej samej podstrony = jedna szansa.');
		self::assertSame(15, $nearTop[0]->evidence['keywords_total']);
		self::assertCount(5, $nearTop[0]->evidence['keywords'], 'Dowody ograniczone do najważniejszych fraz.');
		self::assertStringContainsString('fraza 7', $nearTop[0]->searchText, 'Wyszukiwanie obejmuje wszystkie frazy grupy.');
		self::assertCount(1, self::ofType($candidates, OpportunityType::LowCtr));
		self::assertSame(self::PAGE, self::ofType($candidates, OpportunityType::LowCtr)[0]->pageUrl);
	}

	public function test_group_metrics_use_sums_not_averages_of_rows(): void
	{
		$candidates = self::detector()->detect(self::input(
			[self::keyword(1, 'a', [10, 1000, 2.0], [10, 1000, 2.0]), self::keyword(2, 'b', [0, 200, 3.0], [0, 200, 3.0])],
			[self::pair(1, self::PAGE, [10, 1000, 2.0]), self::pair(2, self::PAGE, [0, 200, 3.0])],
		));

		$current = Stats::fromArray(self::ofType($candidates, OpportunityType::LowCtr)[0]->evidence['metrics']['current']);

		self::assertSame(10, $current->clicks);
		self::assertSame(1200, $current->impressions);
		self::assertEqualsWithDelta(10 / 1200, $current->ctr(), 1e-12, 'CTR = SUM(clicks) / SUM(impressions), nie średnia 0,5%.');
		self::assertEqualsWithDelta((2.0 * 1000 + 3.0 * 200) / 1200, $current->position(), 1e-9, 'Pozycja ważona wyświetleniami (2,17), nie średnia 2,5.');
	}

	public function test_fingerprints_are_stable_and_property_scoped(): void
	{
		$keywords = [self::keyword(1, 'buty damskie', [20, 2000, 2.0], [22, 2000, 2.0])];
		$pairs = [self::pair(1, self::PAGE, [20, 2000, 2.0])];
		$first = self::detector()->detect(self::input($keywords, $pairs));
		$second = self::detector()->detect(self::input($keywords, $pairs));
		$otherProperty = self::detector()->detect(self::input($keywords, $pairs, property: 'https://www.example.pl/'));

		self::assertSame(array_map(static fn ($c): string => $c->fingerprint, $first), array_map(static fn ($c): string => $c->fingerprint, $second));
		self::assertSame(Fingerprint::page(self::PROPERTY, OpportunityType::LowCtr, self::PAGE), $first[0]->fingerprint);
		self::assertNotSame($first[0]->fingerprint, $otherProperty[0]->fingerprint, 'Szanse różnych properties nie łączą się.');
		self::assertSame(16, strlen($first[0]->fingerprint));
	}

	public function test_thresholds_scale_with_period_length(): void
	{
		// 40 wyświetleń: wystarcza w 7 dniach (próg 30 = max(dolna granica 30, 100 × 7/28)), nie w 28 dniach (100).
		$keywords = [self::keyword(1, 'krótki okres', [0, 40, 2.0], [0, 40, 2.0])];

		self::assertCount(1, self::ofType(self::detector()->detect(self::input($keywords, days: 7)), OpportunityType::LowCtr));
		self::assertSame([], self::ofType(self::detector()->detect(self::input($keywords, days: 28)), OpportunityType::LowCtr));
		self::assertSame([], self::ofType(self::detector()->detect(self::input($keywords, days: 90)), OpportunityType::LowCtr));
	}

	public function test_priority_and_confidence_are_independent(): void
	{
		// Duża luka (0 kliknięć na pozycji 1), ale mała próba i brak porównania: wysoki priorytet, niska pewność.
		$candidate = self::ofType(
			self::detector()->detect(self::input([self::keyword(1, 'mała próba', [0, 250, 1.2], [0, 0, 0.0])], previousCovered: false)),
			OpportunityType::LowCtr,
		)[0];

		self::assertGreaterThanOrEqual(55, $candidate->priority);
		self::assertSame(Confidence::Low, $candidate->confidence);
	}

	public function test_evidence_records_periods(): void
	{
		$candidate = self::detector()->detect(self::input([self::keyword(1, 'x', [20, 2000, 2.0], [22, 2000, 2.0])], days: 7))[0];

		self::assertSame(['days' => 7, 'current' => ['2026-03-22', '2026-03-28'], 'previous' => ['2026-03-15', '2026-03-21'], 'previous_covered' => true], $candidate->evidence['period']);
	}
}
