<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Strategy\Core;

use OsfSeo\Strategy\Decision\ConfidenceModel;
use OsfSeo\Strategy\Decision\ConfidenceResult;
use OsfSeo\Strategy\Decision\PriorityModel;
use OsfSeo\Strategy\Decision\StrategyAction;
use OsfSeo\Tests\Support\StrategyFakes;
use PHPUnit\Framework\TestCase;

/**
 * Pewność (czynniki i limity) i Priorytet Strategii (ograniczone składniki, mnożnik pewności, monotoniczność) — faza C, D59.
 */
final class ConfidencePriorityTest extends TestCase
{
	private const PAGE = 'https://example.pl/pozycjonowanie/';

	public function test_confidence_is_explainable_and_capped(): void
	{
		$strong = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 1500]], 'position' => 15.0],
			'serp' => ['rank' => 14, 'url' => self::PAGE],
		])]);
		$data = $strong['confidence']->toArray();

		self::assertSame('high', $strong['confidence']->level);
		self::assertSame(['target_confirmed', 'family_gsc', 'family_serp_fresh', 'gsc_sample_large', 'volume_known'], array_column($data['positive'], 'code'));
		self::assertSame(ConfidenceModel::BASE + array_sum(array_column($strong['confidence']->factors, 'points')), $strong['confidence']->points);

		// Do sprawdzenia — najwyżej średnia, nawet przy wielu dowodach.
		$investigate = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 1500]], 'position' => 75.0],
			'serp' => ['rank' => 70, 'url' => self::PAGE],
			'gap' => ['gap_type' => 'unknown', 'project_labs_rank' => 18, 'competitors' => 3, 'target' => ['url' => self::PAGE, 'source' => 'labs']],
		])]);
		self::assertSame(StrategyAction::Investigate, $investigate['decision']->action);
		self::assertLessThanOrEqual(ConfidenceModel::MEDIUM_CAP, $investigate['confidence']->points);
		self::assertContains('investigate', $investigate['confidence']->caps);

		// Korekta pisowni i sprzeczna intencja obniżają pewność.
		$spell = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 1500]], 'position' => 15.0],
			'serp' => ['rank' => 14, 'url' => self::PAGE, 'spell' => 'showing_results_for'],
		])]);
		self::assertSame(-8, self::factor($spell['confidence'], 'spell_correction'));

		$intent = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'intent' => 'commercial',
			'gsc' => ['pages' => [[self::PAGE, 1500]], 'position' => 15.0],
			'serp' => ['rank' => 14, 'url' => self::PAGE, 'profile' => StrategyFakes::profile('article', 'high', 'informational', 'high')],
		])]);
		self::assertSame([StrategyAction::Investigate, 'intent_mismatch'], [$intent['decision']->action, $intent['decision']->reason]);
		self::assertSame(-10, self::factor($intent['confidence'], 'intent_mismatch'));
	}

	public function test_priority_components_are_capped_and_volume_cannot_dominate(): void
	{
		$huge = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'volume' => 5000000,
			'gsc' => ['pages' => [[self::PAGE, 1500]], 'position' => 15.0],
			'serp' => ['rank' => 14, 'url' => self::PAGE],
		])])['priority'];

		self::assertSame(PriorityModel::DEMAND_MAX, $huge->components['demand']['value']);
		self::assertLessThanOrEqual(100, $huge->value);

		foreach ($huge->components as $name => $component) {
			self::assertLessThanOrEqual($component['max'], $component['value'], $name);
		}

		self::assertSame(['volume', 5000000], [$huge->components['demand']['source'], $huge->components['demand']['input']]);
	}

	public function test_priority_is_monotonic(): void
	{
		$priority = static fn (array $options): int => StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', $options + [
			'gsc' => ['pages' => [[self::PAGE, 400]], 'position' => 15.0],
			'serp' => ['rank' => 14, 'url' => self::PAGE],
		])])['priority']->value;

		// Większy popyt nigdy nie obniża.
		$previous = -1;

		foreach ([0, 10, 100, 1000, 10000, 100000] as $volume) {
			$value = $priority(['volume' => $volume]);
			self::assertGreaterThanOrEqual($previous, $value, 'wolumen ' . var_export($volume, true));
			$previous = $value;
		}

		// Brak wolumenu ≠ 0: popyt z wyświetleń GSC.
		self::assertSame($priority(['volume' => 0]), $priority(['volume' => null]));

		// Niższa KD nigdy nie obniża.
		$previous = -1;

		foreach ([100, 80, 50, 30, 0] as $kd) {
			$value = $priority(['kd' => $kd]);
			self::assertGreaterThanOrEqual($previous, $value, 'KD ' . $kd);
			$previous = $value;
		}

		// Większy spadek nigdy nie obniża pilności.
		$previous = -1.0;

		foreach ([9, 12, 20, 40, 90] as $rank) {
			$urgency = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
				'gsc' => ['pages' => [[self::PAGE, 400]], 'position' => 9.0],
				'serp' => ['rank' => $rank, 'url' => self::PAGE, 'prev_rank' => 4, 'prev_url' => self::PAGE],
			])])['priority']->components['urgency']['value'];
			self::assertGreaterThanOrEqual($previous, $urgency, 'spadek do #' . $rank);
			$previous = $urgency;
		}

		// Wyższa pewność nigdy nie obniża wyniku (ten sam temat, rosnące punkty pewności).
		$facts = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', ['gsc' => ['pages' => [[self::PAGE, 400]]], 'serp' => ['rank' => 14, 'url' => self::PAGE]])]);
		$previous = -1;

		foreach ([0, 20, 44, 45, 69, 70, 100] as $points) {
			$value = (new PriorityModel())->score($facts['facts'], $facts['decision'], new ConfidenceResult($points, ConfidenceModel::level($points), [], []))->value;
			self::assertGreaterThanOrEqual($previous, $value, 'pewność ' . $points);
			$previous = $value;
		}
	}

	public function test_monitor_priority_is_lowered(): void
	{
		$monitor = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 1500]], 'position' => 2.0],
			'serp' => ['rank' => 2, 'url' => self::PAGE],
		])]);
		$optimize = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 1500]], 'position' => 6.0],
			'serp' => ['rank' => 6, 'url' => self::PAGE],
		])]);

		self::assertSame(StrategyAction::Monitor, $monitor['decision']->action);
		self::assertLessThan($optimize['priority']->value, $monitor['priority']->value);
	}

	private static function factor(ConfidenceResult $confidence, string $code): ?int
	{
		foreach ($confidence->factors as $factor) {
			if ($factor['code'] === $code) {
				return $factor['points'];
			}
		}

		return null;
	}
}
