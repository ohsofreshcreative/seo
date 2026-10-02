<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Strategy;

use OsfSeo\Market\Market;
use OsfSeo\Strategy\Candidate;
use OsfSeo\Strategy\CandidateSource;
use OsfSeo\Strategy\EvidenceBuilder;
use OsfSeo\Strategy\SignalBatch;
use OsfSeo\Strategy\SourceScope;
use OsfSeo\Strategy\SourceSignal;
use OsfSeo\Strategy\StrategyConfig;
use OsfSeo\Strategy\StrategySource;
use PHPUnit\Framework\TestCase;

final class EvidenceBuilderTest extends TestCase
{
	public function test_gsc_evidence_is_collected_first_and_passed_to_later_sources(): void
	{
		$seen = [];
		$opportunity = new FakeSource(StrategySource::Opportunity, static function (array $keys, array $collected) use (&$seen): array {
			$seen = $collected;

			return [1 => [['id' => '01HOPP', 'link' => 'page']]];
		});
		$gsc = new FakeSource(StrategySource::Gsc, static fn (): array => [1 => self::gsc(120, 4, 8.25, 3, 15, 0.75)]);

		$facts = (new EvidenceBuilder([$opportunity, $gsc]))->build(self::scope(true), [self::candidate(1, StrategySource::Gsc->bit() | StrategySource::Opportunity->bit())]);

		self::assertSame([1 => ['gsc' => self::gsc(120, 4, 8.25, 3, 15, 0.75)]], $seen);
		self::assertCount(1, $facts);
		self::assertSame(120, $facts[0]->gscImpressions);
		self::assertSame(4, $facts[0]->gscClicks);
		self::assertSame(8.25, $facts[0]->gscPosition);
		self::assertSame(3, $facts[0]->gscPages);
		self::assertSame(15, $facts[0]->gscTopUrlId);
		self::assertSame(0.75, $facts[0]->gscTopShare);
		self::assertSame(1, $facts[0]->opportunities);
	}

	public function test_internal_identifiers_never_reach_evidence_json(): void
	{
		$serp = new FakeSource(StrategySource::Serp, static fn (): array => [1 => [
			'_facts' => ['tracked_keyword_id' => 31, 'url_id' => 77],
			'id' => '01HTRACKED',
			'checked_at' => '2026-09-01 10:00:00',
			'found' => true,
			'rank' => 6,
			'nested' => ['_facts' => ['secret' => 1], 'value' => 2],
		]]);
		$gap = new FakeSource(StrategySource::Gap, static fn (): array => [1 => ['_facts' => ['gap_keyword_id' => 5, 'cluster_id' => 9], 'id' => '01HGAP']]);
		$discovery = new FakeSource(StrategySource::Discovery, static fn (): array => [1 => ['_facts' => ['candidate_id' => 4], 'id' => '01HDISC']]);
		$gsc = new FakeSource(StrategySource::Gsc, static fn (): array => [1 => self::gsc(10, 0, 30.0, 1, 3, 1.0)]);

		$facts = (new EvidenceBuilder([$serp, $gap, $discovery, $gsc]))->build(self::scope(true), [self::candidate(1, StrategySource::Serp->bit())])[0];

		self::assertSame(31, $facts->trackedKeywordId);
		self::assertSame(77, $facts->serpUrlId);
		self::assertSame(6, $facts->serpRank);
		self::assertTrue($facts->serpFound);
		self::assertSame('2026-09-01 10:00:00', $facts->serpCheckedAt);
		self::assertSame(5, $facts->gapKeywordId);
		self::assertSame(9, $facts->gapClusterId);
		self::assertSame(4, $facts->discoveryCandidateId);
		self::assertSame(3, $facts->gscTopUrlId);
		self::assertStringNotContainsString('_facts', $facts->evidenceJson());
		self::assertSame(['v', 'keyword', 'sources', 'gsc', 'serp', 'discovery', 'gap'], array_keys($facts->evidence));
		self::assertSame(['value' => 2], $facts->evidence['serp']['nested']);
		self::assertSame(['serp'], $facts->evidence['sources']);
	}

	public function test_gsc_null_without_project_data_and_zero_without_keyword_rows(): void
	{
		$gsc = new FakeSource(StrategySource::Gsc, static fn (): array => []);
		$candidate = self::candidate(1, StrategySource::Manual->bit());

		$without = (new EvidenceBuilder([$gsc]))->build(self::scope(false), [$candidate])[0];
		$empty = (new EvidenceBuilder([$gsc]))->build(self::scope(true), [$candidate])[0];

		// Projekt bez danych GSC: NULL (nieznane), nigdy 0.
		self::assertNull($without->gscImpressions);
		self::assertNull($without->gscClicks);
		self::assertNull($without->gscPosition);
		self::assertNull($without->gscPages);
		self::assertNull($without->evidence['gsc']);
		// Dane GSC są, fraza bez wyświetleń: 0, pozycja nieznana.
		self::assertSame(0, $empty->gscImpressions);
		self::assertSame(0, $empty->gscClicks);
		self::assertNull($empty->gscPosition);
		self::assertSame(0, $empty->gscPages);
		self::assertNull($empty->gscTopUrlId);
		self::assertSame(EvidenceBuilder::EMPTY_GSC, $empty->evidence['gsc']);
		self::assertNotSame($without->hash(), $empty->hash());
	}

	public function test_serp_facts_require_a_measurement_and_cluster_falls_back_to_content_gap(): void
	{
		$serp = new FakeSource(StrategySource::Serp, static fn (): array => [1 => ['_facts' => ['tracked_keyword_id' => 3, 'url_id' => null], 'checked_at' => null, 'found' => null, 'rank' => null]]);
		$content = new FakeSource(StrategySource::ContentGap, static fn (): array => [1 => ['_facts' => ['cluster_id' => 12], 'id' => '01HCLUSTER']]);

		$facts = (new EvidenceBuilder([$serp, $content]))->build(self::scope(false), [self::candidate(1, StrategySource::Serp->bit())])[0];

		self::assertSame(3, $facts->trackedKeywordId);
		self::assertNull($facts->serpCheckedAt);
		self::assertNull($facts->serpFound);
		self::assertNull($facts->serpRank);
		self::assertNull($facts->serpUrlId);
		self::assertSame(12, $facts->gapClusterId);
		self::assertNull($facts->gapKeywordId);
	}

	public function test_candidates_without_market_keyword_are_skipped_and_evidence_of_unknown_ids_ignored(): void
	{
		$gap = new FakeSource(StrategySource::Gap, static fn (): array => [1 => ['id' => 'a'], 99 => ['id' => 'b']]);
		$builder = new EvidenceBuilder([$gap]);

		$facts = $builder->build(self::scope(false), [
			self::candidate(1, StrategySource::Gap->bit()),
			new Candidate('ffff', null, 'nowa', null, StrategySource::Gsc->bit(), SourceSignal::TIER_GSC, 1.0, []),
		]);

		self::assertCount(1, $facts);
		self::assertSame(1, $facts[0]->marketKeywordId);
		self::assertSame(['id' => 'a'], $facts[0]->evidence['gap']);
	}

	public function test_hash_changes_with_facts_and_evidence_only(): void
	{
		$build = static fn (int $rank): string => (new EvidenceBuilder([
			new FakeSource(StrategySource::Serp, static fn (): array => [1 => ['_facts' => ['tracked_keyword_id' => 3, 'url_id' => 1], 'checked_at' => '2026-09-01 10:00:00', 'found' => true, 'rank' => $rank]]),
		]))->build(self::scope(false), [self::candidate(1, StrategySource::Serp->bit())])[0]->hash();

		self::assertSame($build(4), $build(4));
		self::assertNotSame($build(4), $build(5));
		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $build(4));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function gsc(int $impressions, int $clicks, ?float $position, int $pages, int $topUrlId, float $share): array
	{
		return [
			'_facts' => ['top_url_id' => $topUrlId],
			'impressions' => $impressions,
			'clicks' => $clicks,
			'position' => $position,
			'variants' => 1,
			'pages' => [['url' => 'https://example.com/a', 'impressions' => $impressions, 'share' => $share]],
			'pages_total' => $pages,
		];
	}

	private static function candidate(int $id, int $sources): Candidate
	{
		return new Candidate(str_pad(dechex($id), 32, '0', STR_PAD_LEFT), $id, 'fraza ' . $id, null, $sources, SourceSignal::TIER_GSC, 1.0, []);
	}

	private static function scope(bool $gsc): SourceScope
	{
		return new SourceScope(1, new Market('dataforseo', 'PL', 'pl', 2616, 'pl', 'Polska', 'polski'), 'example.com', 'Example', $gsc ? ['2026-06-01', '2026-08-29'] : null, new StrategyConfig());
	}
}

final class FakeSource implements CandidateSource
{
	/**
	 * @param \Closure(array<int, string>, array<int, array<string, mixed>>): array<int, mixed> $evidence
	 */
	public function __construct(private readonly StrategySource $source, private readonly \Closure $evidence)
	{
	}

	public function source(): StrategySource
	{
		return $this->source;
	}

	public function fingerprint(SourceScope $scope): string
	{
		return '';
	}

	public function signals(SourceScope $scope): SignalBatch
	{
		return new SignalBatch([]);
	}

	public function evidence(SourceScope $scope, array $keys, array $collected): array
	{
		return ($this->evidence)($keys, $collected);
	}
}
