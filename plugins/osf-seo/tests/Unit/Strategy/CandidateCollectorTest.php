<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Strategy;

use OsfSeo\Discovery\ExclusionList;
use OsfSeo\Gap\BrandMatcher;
use OsfSeo\Strategy\Candidate;
use OsfSeo\Strategy\CandidateCollector;
use OsfSeo\Strategy\CandidateFilter;
use OsfSeo\Strategy\SignalBatch;
use OsfSeo\Strategy\SourceSignal;
use OsfSeo\Strategy\StrategySource;
use PHPUnit\Framework\TestCase;

final class CandidateCollectorTest extends TestCase
{
	public function test_signals_of_one_keyword_merge_into_one_candidate_with_source_mask_and_best_tier(): void
	{
		$set = (new CandidateCollector())->collect([
			new SignalBatch([self::signal('a', StrategySource::Gsc, SourceSignal::TIER_GSC, 900.0, null)]),
			new SignalBatch([self::signal('a', StrategySource::Gap, SourceSignal::TIER_GAP, 55.0, 7)]),
			new SignalBatch([self::signal('a', StrategySource::Discovery, SourceSignal::TIER_DISCOVERY, 80.0, 7)]),
		], CandidateFilter::none(), 10);

		self::assertCount(1, $set->selected);
		$candidate = $set->selected[0];
		self::assertSame(7, $candidate->marketKeywordId);
		self::assertSame(StrategySource::Gsc->bit() | StrategySource::Gap->bit() | StrategySource::Discovery->bit(), $candidate->sources);
		self::assertSame(SourceSignal::TIER_GAP, $candidate->tier);
		// Waga z najważniejszego poziomu (luka 55), nie największa ze wszystkich (GSC 900).
		self::assertSame(55.0, $candidate->weight);
		self::assertSame(['discovery' => 80.0, 'gap' => 55.0, 'gsc' => 900.0], $candidate->weights);
		self::assertSame(['discovery', 'gap', 'gsc'], $candidate->sourceCodes());
		self::assertSame(['discovery' => 1, 'gap' => 1, 'gsc' => 1], $set->signals);
	}

	public function test_weight_is_the_highest_weight_within_the_best_tier(): void
	{
		$set = (new CandidateCollector())->collect([
			new SignalBatch([self::signal('a', StrategySource::Opportunity, SourceSignal::TIER_DECISION, 40.0, 1)]),
			new SignalBatch([self::signal('a', StrategySource::Discovery, SourceSignal::TIER_DECISION, 70.0, 1)]),
			new SignalBatch([self::signal('a', StrategySource::Opportunity, SourceSignal::TIER_DECISION, 20.0, 1)]),
		], CandidateFilter::none(), 10);

		self::assertSame(70.0, $set->selected[0]->weight);
		self::assertSame(['discovery' => 70.0, 'opportunity' => 40.0], $set->selected[0]->weights);
	}

	public function test_order_is_tier_then_weight_descending_then_key_and_limit_moves_rest_to_overflow(): void
	{
		$set = (new CandidateCollector())->collect([
			new SignalBatch([
				self::signal('d', StrategySource::Gsc, SourceSignal::TIER_GSC, 5000.0, 4),
				self::signal('c', StrategySource::Gsc, SourceSignal::TIER_GSC, 100.0, 3),
				self::signal('b', StrategySource::Gsc, SourceSignal::TIER_GSC, 100.0, 2),
			], 12),
			new SignalBatch([self::signal('e', StrategySource::Manual, SourceSignal::TIER_MANUAL, 0.0, 5)]),
			new SignalBatch([self::signal('f', StrategySource::Gap, SourceSignal::TIER_GAP, 10.0, 6)]),
		], CandidateFilter::none(), 4);

		self::assertSame(['e', 'f', 'd', 'b'], array_map(static fn (Candidate $candidate): string => $candidate->keyHex, $set->selected));
		self::assertSame(['c'], array_map(static fn (Candidate $candidate): string => $candidate->keyHex, $set->overflow));
		// Nadmiar = zwróceni ponad limit + pominięci przez źródło (i tak ponad limitem).
		self::assertSame(13, $set->overflowCount());
		self::assertSame(12, $set->omitted);

		$stats = $set->stats();
		self::assertSame(4, $stats['selected']);
		self::assertSame(4, $stats['limit']);
		self::assertSame(13, $stats['overflow']);
		self::assertSame(['manual' => 1, 'serp' => 0, 'opportunity' => 0, 'discovery' => 0, 'gap' => 1, 'content_gap' => 0, 'gsc' => 2], $stats['selected_by_source']);
		self::assertSame(0, $stats['new_market_keywords']);
	}

	public function test_order_does_not_depend_on_source_order(): void
	{
		$batches = [
			new SignalBatch([self::signal('x', StrategySource::Gsc, SourceSignal::TIER_GSC, 10.0, 1), self::signal('y', StrategySource::Gsc, SourceSignal::TIER_GSC, 10.0, 2)]),
			new SignalBatch([self::signal('y', StrategySource::Gap, SourceSignal::TIER_GAP, 50.0, 2)]),
		];
		$keys = static fn (array $selected): array => array_map(static fn (Candidate $candidate): string => $candidate->keyHex, $selected);
		$collector = new CandidateCollector();

		self::assertSame(['y', 'x'], $keys($collector->collect($batches, CandidateFilter::none(), 10)->selected));
		self::assertSame(['y', 'x'], $keys($collector->collect(array_reverse($batches), CandidateFilter::none(), 10)->selected));
	}

	public function test_brand_and_exclusion_filters_skip_keywords_except_manual_ones(): void
	{
		$filter = new CandidateFilter(
			BrandMatcher::build(['wise-people.pl'], ['Wise People']),
			[BrandMatcher::build(['konkurent.pl'], ['Konkurent'], 'konkurent')],
			ExclusionList::parse("darmowe\npraca*"),
		);
		$set = (new CandidateCollector())->collect([
			new SignalBatch([
				self::signal('own', StrategySource::Gsc, SourceSignal::TIER_GSC, 1.0, 1, 'wisepeople opinie'),
				self::signal('rival', StrategySource::Gsc, SourceSignal::TIER_GSC, 1.0, 2, 'konkurent cennik'),
				self::signal('free', StrategySource::Gsc, SourceSignal::TIER_GSC, 1.0, 3, 'darmowe strony'),
				self::signal('job', StrategySource::Gsc, SourceSignal::TIER_GSC, 1.0, 4, 'praca seo'),
				self::signal('ok', StrategySource::Gsc, SourceSignal::TIER_GSC, 1.0, 5, 'strony internetowe'),
			]),
			new SignalBatch([self::signal('job', StrategySource::Manual, SourceSignal::TIER_MANUAL, 0.0, 4, 'praca seo')]),
		], $filter, 10);

		self::assertSame(['job', 'ok'], array_map(static fn (Candidate $candidate): string => $candidate->keyHex, $set->selected));
		self::assertSame(['own' => 'brand_own', 'rival' => 'brand_competitor', 'free' => 'excluded'], array_map(static fn (array $entry): string => $entry['reason'], $set->filtered));
		self::assertSame(['brand_competitor' => 1, 'brand_own' => 1, 'excluded' => 1], $set->filteredByReason());
	}

	public function test_market_keyword_id_and_intent_are_taken_from_any_signal(): void
	{
		$set = (new CandidateCollector())->collect([
			new SignalBatch([new SourceSignal('a', null, 'fraza', null, StrategySource::Gsc, SourceSignal::TIER_GSC, 10.0)]),
			new SignalBatch([new SourceSignal('a', 9, 'fraza', 'commercial', StrategySource::Gap, SourceSignal::TIER_GAP, 10.0)]),
			new SignalBatch([new SourceSignal('b', null, 'nowa fraza', null, StrategySource::Gsc, SourceSignal::TIER_GSC, 5.0)]),
		], CandidateFilter::none(), 10);

		self::assertSame(9, $set->selected[0]->marketKeywordId);
		self::assertSame('commercial', $set->selected[0]->intent);
		self::assertNull($set->selected[1]->marketKeywordId);
		self::assertSame(1, $set->stats()['new_market_keywords']);
		self::assertSame(42, $set->selected[1]->withMarketKeywordId(42)->marketKeywordId);
	}

	public function test_empty_input_gives_empty_set(): void
	{
		$set = (new CandidateCollector())->collect([], CandidateFilter::none(), 100);

		self::assertSame([], $set->selected);
		self::assertSame(0, $set->overflowCount());
		self::assertSame([], $set->filteredByReason());
	}

	public function test_source_bits_are_unique_powers_of_two_and_round_trip(): void
	{
		$bits = array_map(static fn (StrategySource $source): int => $source->bit(), StrategySource::cases());

		self::assertSame($bits, array_values(array_unique($bits)));

		foreach ($bits as $bit) {
			self::assertSame(0, $bit & ($bit - 1));
		}

		self::assertSame([StrategySource::Serp, StrategySource::Gsc], StrategySource::fromBits(StrategySource::Gsc->bit() | StrategySource::Serp->bit()));
		// Wartości są zapisane w bazie — lista jest tylko dopisywana.
		self::assertSame(['manual' => 1, 'serp' => 2, 'opportunity' => 4, 'discovery' => 8, 'gap' => 16, 'content_gap' => 32, 'gsc' => 64], array_combine(StrategySource::values(), $bits));
	}

	private static function signal(string $key, StrategySource $source, int $tier, float $weight, ?int $id, ?string $keyword = null): SourceSignal
	{
		return new SourceSignal($key, $id, $keyword ?? 'fraza ' . $key, null, $source, $tier, $weight);
	}
}
