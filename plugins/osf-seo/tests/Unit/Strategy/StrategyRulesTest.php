<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Strategy;

use OsfSeo\Serp\RankChange;
use OsfSeo\Strategy\CandidateFilters;
use OsfSeo\Strategy\StrategyConfig;
use OsfSeo\Strategy\StrategySource;
use OsfSeo\Strategy\Sources\SerpSource;
use PHPUnit\Framework\TestCase;

final class StrategyRulesTest extends TestCase
{
	private const ENV = [
		StrategyConfig::MAX_KEYWORDS,
		StrategyConfig::SERP_MAX_PER_RUN,
		StrategyConfig::WINDOW_DAYS,
		StrategyConfig::GSC_MIN_IMPRESSIONS,
		StrategyConfig::GSC_MAX_POSITION,
		StrategyConfig::DISCOVERY_MIN_PRIORITY,
		StrategyConfig::GAP_MIN_PRIORITY,
	];

	protected function tearDown(): void
	{
		foreach (self::ENV as $name) {
			putenv($name);
		}
	}

	public function test_config_defaults_match_accepted_decisions(): void
	{
		self::assertSame([
			'max_keywords' => 5000,
			'serp_max_per_run' => 100,
			'window_days' => 90,
			'gsc_min_impressions' => 50,
			'gsc_max_position' => 50.0,
			'discovery_min_priority' => 50,
			'gap_min_priority' => 40,
		], (new StrategyConfig())->effective());
	}

	public function test_config_values_are_clamped_and_invalid_values_fall_back_to_defaults(): void
	{
		putenv(StrategyConfig::MAX_KEYWORDS . '=10');
		putenv(StrategyConfig::SERP_MAX_PER_RUN . '=99999');
		putenv(StrategyConfig::WINDOW_DAYS . '=abc');
		putenv(StrategyConfig::GSC_MAX_POSITION . '=12.5');
		putenv(StrategyConfig::GAP_MIN_PRIORITY . '=-5');

		$config = new StrategyConfig();

		self::assertSame(100, $config->maxKeywords());
		self::assertSame(1000, $config->serpMaxPerRun());
		self::assertSame(90, $config->windowDays());
		self::assertSame(12.5, $config->gscMaxPosition());
		self::assertSame(0, $config->gapMinPriority());

		putenv(StrategyConfig::MAX_KEYWORDS . '=20000');
		self::assertSame(20000, (new StrategyConfig())->maxKeywords());
	}

	public function test_serp_evidence_reports_bands_and_decline_only_for_comparable_measurements(): void
	{
		$down = SerpSource::describe(self::row(['last_rank' => '12', 'prev_rank' => '7', 'change_type' => RankChange::DOWN, 'change_value' => '-5']));

		self::assertSame(['tracked_keyword_id' => 3, 'url_id' => 8], $down['_facts']);
		self::assertSame(12, $down['rank']);
		self::assertSame(7, $down['prev_rank']);
		self::assertSame(-5, $down['change_value']);
		self::assertSame(['top3' => null, 'top10' => 'left', 'top20' => null], $down['bands']);
		self::assertTrue($down['decline']);

		$small = SerpSource::describe(self::row(['last_rank' => '9', 'prev_rank' => '6', 'change_type' => RankChange::DOWN, 'change_value' => '-3']));
		self::assertSame(['top3' => null, 'top10' => null, 'top20' => null], $small['bands']);
		self::assertFalse($small['decline']);

		$up = SerpSource::describe(self::row(['last_rank' => '2', 'prev_rank' => '11', 'change_type' => RankChange::UP, 'change_value' => '9']));
		self::assertSame(['top3' => 'entered', 'top10' => 'entered', 'top20' => null], $up['bands']);
		self::assertFalse($up['decline']);

		$left = SerpSource::describe(self::row(['last_found' => '0', 'last_rank' => null, 'last_url_id' => null, 'prev_rank' => '15', 'change_type' => RankChange::LEFT, 'change_value' => null]));
		self::assertFalse($left['found']);
		self::assertNull($left['rank']);
		self::assertSame(['top3' => null, 'top10' => null, 'top20' => 'left'], $left['bands']);
		self::assertTrue($left['decline']);
		self::assertNull($left['_facts']['url_id']);

		$entered = SerpSource::describe(self::row(['last_rank' => '18', 'prev_rank' => null, 'change_type' => RankChange::ENTERED, 'change_value' => null]));
		self::assertSame(['top3' => null, 'top10' => null, 'top20' => 'entered'], $entered['bands']);
		self::assertFalse($entered['decline']);
	}

	public function test_serp_evidence_without_comparable_previous_measurement_has_no_change(): void
	{
		foreach ([RankChange::NEW, RankChange::INCOMPARABLE] as $type) {
			$row = SerpSource::describe(self::row(['last_rank' => '30', 'prev_rank' => '2', 'change_type' => $type, 'change_value' => null]));

			self::assertSame($type, $row['change']);
			self::assertNull($row['prev_rank']);
			self::assertNull($row['change_value']);
			self::assertSame(['top3' => null, 'top10' => null, 'top20' => null], $row['bands']);
			self::assertFalse($row['decline']);
		}

		$never = SerpSource::describe(self::row(['last_snapshot_id' => null, 'last_checked_at' => null, 'last_found' => null, 'last_rank' => null, 'change_type' => null]));
		self::assertNull($never['checked_at']);
		self::assertNull($never['found']);
		self::assertNull($never['rank']);
		self::assertNull($never['url']);
		self::assertNull($never['_facts']['url_id']);
		self::assertFalse($never['decline']);
	}

	public function test_candidate_filters_accept_only_whitelisted_values(): void
	{
		$filters = CandidateFilters::fromInput(['source' => 'gap', 'status' => 'all', 'q' => '  seo  ', 'sort' => 'impressions', 'page' => '3', 'per_page' => '20']);

		self::assertSame(StrategySource::Gap, $filters->source);
		self::assertSame('all', $filters->status);
		self::assertSame('seo', $filters->q);
		self::assertSame('impressions', $filters->sort);
		self::assertSame('desc', $filters->direction);
		self::assertSame(40, $filters->offset());

		$fallback = CandidateFilters::fromInput(['source' => 'x', 'status' => 'deleted', 'sort' => 'id; DROP', 'dir' => 'sideways', 'page' => '-4', 'per_page' => '100000', 'q' => ['a']]);

		self::assertNull($fallback->source);
		self::assertSame('active', $fallback->status);
		self::assertSame('tier', $fallback->sort);
		self::assertSame('asc', $fallback->direction);
		self::assertSame(1, $fallback->page);
		self::assertSame(500, $fallback->perPage);
		self::assertSame('', $fallback->q);
		self::assertSame('desc', CandidateFilters::fromInput(['sort' => 'position', 'dir' => 'desc'])->direction);
	}

	/**
	 * @param array<string, string|null> $overrides
	 * @return array<string, string|null>
	 */
	private static function row(array $overrides): array
	{
		return $overrides + [
			'id' => '3',
			'public_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
			'status' => 'active',
			'source' => 'manual',
			'last_snapshot_id' => '40',
			'last_checked_at' => '2026-09-01 10:00:00',
			'last_found' => '1',
			'last_rank' => '5',
			'last_rank_absolute' => '7',
			'last_url_id' => '8',
			'last_depth' => '100',
			'last_featured' => '0',
			'prev_rank' => null,
			'change_type' => RankChange::NEW,
			'change_value' => null,
			'top10_change' => null,
			'url' => 'https://example.com/a',
		];
	}
}
