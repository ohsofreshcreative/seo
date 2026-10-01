<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Opportunities;

use OsfSeo\Opportunities\Confidence;
use OsfSeo\Opportunities\ConfidenceModel;
use OsfSeo\Opportunities\CtrModel;
use OsfSeo\Opportunities\Fingerprint;
use OsfSeo\Opportunities\OpportunityConfig;
use OsfSeo\Opportunities\OpportunityScorer;
use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Opportunities\Stats;
use OsfSeo\Opportunities\UrlKey;
use OsfSeo\Support\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoringTest extends TestCase
{
	private static function scorer(): OpportunityScorer
	{
		return new OpportunityScorer(new OpportunityConfig());
	}

	public function test_log_score_is_bounded(): void
	{
		self::assertSame(0.0, OpportunityScorer::logScore(0, 100));
		self::assertSame(0.0, OpportunityScorer::logScore(-5, 100));
		self::assertSame(1.0, OpportunityScorer::logScore(100, 100));
		self::assertSame(1.0, OpportunityScorer::logScore(1e12, 100));
		self::assertEqualsWithDelta(log10(11) / log10(101), OpportunityScorer::logScore(10, 100), 1e-12);
	}

	public function test_exact_formula_for_low_ctr(): void
	{
		// 28 dni: demand_cap = 11 200 wyświetleń, clicks_cap = 280 kliknięć.
		$max = self::scorer()->score(OpportunityType::LowCtr, ['impressions' => 11200, 'click_gap' => 280, 'ctr_ratio' => 0.0, 'ctr_drop' => 0.5], 28);
		self::assertSame(['total' => 100, 'demand' => 30.0, 'size' => 50.0, 'trend' => 20.0], array_slice($max, 0, 4));

		$partial = self::scorer()->score(OpportunityType::LowCtr, ['impressions' => 1000, 'click_gap' => 50, 'ctr_ratio' => 0.4, 'ctr_drop' => 0.1], 28);
		$demand = 30 * log10(1001) / log10(11201);
		$size = 30 * log10(51) / log10(281) + 20 * 0.6;
		$trend = 20 * 0.2;
		self::assertSame((int) round($demand + $size + $trend), $partial['total']);
		self::assertSame(round($demand, 1), $partial['demand']);
		self::assertSame(round($size, 1), $partial['size']);
		self::assertSame(round($trend, 1), $partial['trend']);
	}

	public function test_huge_values_cannot_exceed_component_caps(): void
	{
		foreach (OpportunityType::cases() as $type) {
			$score = self::scorer()->score($type, [
				'impressions' => 1e12, 'click_gap' => 1e12, 'ctr_ratio' => -3, 'ctr_drop' => 50,
				'potential_clicks' => 1e12, 'proximity' => 9, 'impressions_growth' => 1e6, 'position_improvement' => 1e6,
				'clicks_lost' => 1e12, 'relative_loss' => 7, 'position_worsening' => 1e6,
				'balance' => 5, 'queries' => 1e6, 'switch_share' => 3,
			], 28);

			self::assertLessThanOrEqual(30.0, $score['demand'], $type->value);
			self::assertLessThanOrEqual(50.0, $score['size'], $type->value);
			self::assertLessThanOrEqual(20.0, $score['trend'], $type->value);
			self::assertLessThanOrEqual(100, $score['total'], $type->value);
		}
	}

	public function test_more_visibility_means_higher_priority_with_diminishing_returns(): void
	{
		$score = static fn (int $impressions): float => self::scorer()->score(OpportunityType::NearTop, ['impressions' => $impressions, 'potential_clicks' => 10, 'proximity' => 0.5], 28)['demand'];

		self::assertGreaterThan($score(100), $score(1000));
		self::assertGreaterThan($score(1000) - $score(100), $score(100) - $score(10) + 1, 'Logarytm: kolejne rzędy wielkości dodają podobnie, nie liniowo.');
	}

	public function test_caps_scale_with_period(): void
	{
		$config = new OpportunityConfig();

		self::assertSame(11200, $config->volume('demand_cap', 28));
		self::assertSame(2800, $config->volume('demand_cap', 7));
		self::assertSame(36000, $config->volume('demand_cap', 90));
		self::assertSame(100, $config->volume('low_ctr_min_impressions', 28));
		self::assertSame(30, $config->volume('low_ctr_min_impressions', 7), 'Dolna granica, nie 25.');
		self::assertSame(321, $config->volume('low_ctr_min_impressions', 90));
	}

	public function test_thresholds_can_be_overridden_and_are_clamped(): void
	{
		$config = new OpportunityConfig(new Config(), ['low_ctr_max_ratio' => 7, 'low_ctr_min_impressions' => 10]);

		self::assertSame(1.0, $config->ratio('low_ctr_max_ratio'), 'Udział przycięty do 1.');
		self::assertSame(10, $config->volume('low_ctr_min_impressions', 28));
		self::assertSame(10, $config->volume('low_ctr_min_impressions', 7), 'Nadpisanie poniżej dolnej granicy jest respektowane.');
		self::assertNotSame((new OpportunityConfig())->hash(), $config->hash(), 'Zmiana progów zmienia klucz analizy.');
	}

	/**
	 * @return iterable<string, array{int, string, bool, int}>
	 */
	public static function confidenceCases(): iterable
	{
		yield 'mała próba, brak porównania' => [100, 'not_covered', false, Confidence::Low->value];
		yield 'średnia próba, porównanie' => [400, 'ok', false, Confidence::Medium->value];
		yield 'duża próba bez porównania' => [5000, 'not_covered', false, Confidence::Medium->value];
		yield 'duża próba, porównanie, spójny' => [5000, 'ok', true, Confidence::High->value];
		yield 'duża próba, porównanie, niespójny' => [5000, 'ok', false, Confidence::Medium->value];
		yield 'mała próba, spójny' => [100, 'ok', true, Confidence::Medium->value];
	}

	#[DataProvider('confidenceCases')]
	public function test_confidence_points(int $sample, string $comparison, bool $consistent, int $expected): void
	{
		$result = (new ConfidenceModel(new OpportunityConfig()))->evaluate($sample, $comparison, $consistent, 'rule', 28);

		self::assertSame($expected, $result['level']);
		self::assertSame(['sample', 'comparison', 'consistency'], array_column($result['factors'], 'code'));
	}

	public function test_confidence_sample_thresholds_scale_with_period(): void
	{
		$model = new ConfidenceModel(new OpportunityConfig());

		self::assertSame(2, $model->evaluate(250, 'ok', false, 'rule', 7)['factors'][0]['points'], '7 dni: wysoka próba od 250.');
		self::assertSame(0, $model->evaluate(250, 'ok', false, 'rule', 28)['factors'][0]['points']);
	}

	public function test_ctr_model_buckets_and_median(): void
	{
		self::assertSame(0, CtrModel::bucketIndex(1.0));
		self::assertSame(1, CtrModel::bucketIndex(1.5));
		self::assertSame(1, CtrModel::bucketIndex(3.4));
		self::assertSame(2, CtrModel::bucketIndex(3.5));
		self::assertSame(3, CtrModel::bucketIndex(10.4));
		self::assertSame(4, CtrModel::bucketIndex(20.5 - 1e-9));
		self::assertSame(6, CtrModel::bucketIndex(150.0));

		$samples = [new Stats(1, 100, 800.0), new Stats(5, 100, 800.0), new Stats(9, 100, 800.0), new Stats(500, 1000, 1000.0)];
		$model = CtrModel::fromSamples($samples, 50, 3);

		self::assertSame(0.05, $model->reference(8.0), 'Mediana 1% / 5% / 9% = 5%.');
		self::assertSame(0.28, $model->reference(1.0), 'Jedna fraza w przedziale 1 — wartość domyślna.');
		self::assertSame('project', $model->toArray()[3]['source']);
		self::assertSame('default', $model->toArray()[0]['source']);
	}

	public function test_url_key_drops_fragment_only(): void
	{
		self::assertSame('https://example.pl/a/?x=1', UrlKey::normalize('https://example.pl/a/?x=1#sekcja'));
		self::assertSame('https://example.pl/A/', UrlKey::normalize('https://example.pl/A/'));
		self::assertSame(UrlKey::hash('https://example.pl/a/'), UrlKey::hash('https://example.pl/a/#faq'));
	}

	public function test_pair_fingerprint_ignores_order_and_fragments(): void
	{
		$type = OpportunityType::Cannibalization;

		self::assertSame(
			Fingerprint::pair('p', $type, 'https://example.pl/a/', 'https://example.pl/b/'),
			Fingerprint::pair('p', $type, 'https://example.pl/b/#x', 'https://example.pl/a/'),
		);
		self::assertNotSame(Fingerprint::page('p', OpportunityType::LowCtr, 'https://example.pl/a/'), Fingerprint::page('p', OpportunityType::NearTop, 'https://example.pl/a/'), 'Typ jest częścią odcisku.');
		self::assertNotSame(Fingerprint::keyword('p', OpportunityType::LowCtr, 'Buty'), Fingerprint::keyword('p', OpportunityType::LowCtr, 'buty'), 'Frazy jak w GSC — wielkość liter ma znaczenie.');
	}
}
