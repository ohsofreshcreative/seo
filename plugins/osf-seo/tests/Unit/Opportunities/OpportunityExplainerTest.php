<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Opportunities;

use OsfSeo\Opportunities\OpportunityExplainer;
use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Opportunities\Text;

final class OpportunityExplainerTest extends DetectorTestCase
{
	/** Sformułowania sugerujące pewność przyczyny albo analizę, której system nie wykonuje. */
	private const FORBIDDEN = ['/jest zły/iu', '/przeanalizowali/iu', '/sprawdziliśmy/iu', '/gwarantuje/iu', '/spowodował/iu', '/pozycja w google/iu'];

	public function test_every_type_has_explainable_summary_and_hypothesis_recommendations(): void
	{
		$keywords = [
			self::keyword(1, 'niski ctr', [20, 2000, 2.0], [22, 2000, 2.0]),
			self::keyword(2, 'blisko', [30, 1500, 4.8], [28, 1400, 5.0]),
			self::keyword(3, 'daleko', [0, 900, 35.0], [0, 800, 36.0]),
			self::keyword(4, 'spada', [20, 900, 3.2], [50, 1000, 3.0]),
			self::keyword(5, 'kanibal', [18, 500, 7.0], [22, 450, 5.5]),
		];
		$pairs = [
			self::pair(1, 'https://example.pl/a/', [20, 2000, 2.0]),
			self::pair(2, 'https://example.pl/a/', [30, 1500, 4.8]),
			self::pair(3, 'https://example.pl/c/', [0, 900, 35.0]),
			self::pair(4, 'https://example.pl/d/', [20, 900, 3.2], [50, 1000, 3.0]),
			self::pair(5, 'https://example.pl/a/', [10, 300, 6.0], [20, 400, 5.0]),
			self::pair(5, 'https://example.pl/b/', [8, 200, 9.0], [2, 50, 12.0]),
		];
		$candidates = self::detector()->detect(self::input($keywords, $pairs));
		$types = [];

		foreach ($candidates as $candidate) {
			$types[$candidate->type->value] = true;
			$summary = OpportunityExplainer::summary($candidate->type, $candidate->evidence);
			$recommendations = OpportunityExplainer::recommendations($candidate->type, $candidate->evidence);
			$text = $summary . ' ' . implode(' ', $recommendations);

			self::assertNotSame('', $summary);
			self::assertGreaterThanOrEqual(3, count($recommendations), $candidate->type->value);

			foreach (self::FORBIDDEN as $pattern) {
				self::assertDoesNotMatchRegularExpression($pattern, $text, $candidate->type->value);
			}

			self::assertCount(3, OpportunityExplainer::scoreBreakdown($candidate->type, $candidate->evidence));
			self::assertCount(3, OpportunityExplainer::confidenceReasons($candidate->evidence));
		}

		self::assertEqualsCanonicalizing(OpportunityType::values(), array_keys($types), 'Fixture obejmuje wszystkie typy.');
	}

	public function test_low_ctr_wording_describes_symptom_not_cause(): void
	{
		$candidate = self::detector()->detect(self::input(
			[self::keyword(1, 'buty', [20, 2000, 2.0], [22, 2000, 2.0])],
			[self::pair(1, 'https://example.pl/buty/', [20, 2000, 2.0])],
		))[0];

		$summary = OpportunityExplainer::summary($candidate->type, $candidate->evidence);
		self::assertStringContainsString('średniej pozycji (GSC) 2,0', $summary);
		self::assertStringContainsString("CTR 1,00\u{00A0}%", $summary);
		self::assertStringContainsString("ok. 14,00\u{00A0}%", $summary);
		self::assertStringContainsString('ok. 260 kliknięć', $summary);
		self::assertSame('Sprawdź title i meta description tej podstrony dla głównych fraz — czy odpowiadają na zapytanie i zachęcają do kliknięcia.', OpportunityExplainer::recommendations($candidate->type, $candidate->evidence)[0]);
		self::assertSame('/buty/', OpportunityExplainer::title($candidate->evidence));
	}

	public function test_decline_summary_names_both_periods(): void
	{
		$candidate = self::detector()->detect(self::input([self::keyword(1, 'spada', [20, 900, 3.2], [50, 1000, 3.0])]))[0];
		$summary = OpportunityExplainer::summary(OpportunityType::Decline, $candidate->evidence);

		self::assertStringContainsString('Kliknięcia 50 → 20', $summary);
		self::assertStringContainsString("−60,0\u{00A0}%", $summary);
		self::assertStringContainsString('01.03.2026–28.03.2026 vs 01.02.2026–28.02.2026', $summary);
	}

	public function test_polish_plurals(): void
	{
		self::assertSame("1\u{00A0}fraza", Text::keywords(1));
		self::assertSame("3\u{00A0}frazy", Text::keywords(3));
		self::assertSame("5\u{00A0}fraz", Text::keywords(5));
		self::assertSame("12\u{00A0}fraz", Text::keywords(12));
		self::assertSame("22\u{00A0}frazy", Text::keywords(22));
	}
}
