<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Gap;

use OsfSeo\Gap\ClusterEvidence;
use OsfSeo\Gap\ClusterItem;
use OsfSeo\Gap\ContentGap;
use OsfSeo\Gap\ContentGapClassifier;
use OsfSeo\Gap\GapType;
use OsfSeo\Gap\KeywordClusterer;
use OsfSeo\Gap\TextFold;
use PHPUnit\Framework\TestCase;

final class ClusteringAndContentTest extends TestCase
{
	/**
	 * @param list<int> $urls
	 * @param list<int>|null $serp
	 */
	private static function item(int $id, int $volume, array $urls, string $keyword, ?string $core = null, ?int $target = null, ?array $serp = null): ClusterItem
	{
		return new ClusterItem($id, $volume, $urls, $core, $target, $serp, TextFold::tokenKey($keyword));
	}

	public function test_leader_based_clustering_uses_shared_competitor_urls_without_chaining(): void
	{
		$result = (new KeywordClusterer())->cluster([
			self::item(1, 1300, [10, 20], 'strony internetowe warszawa'),
			self::item(2, 590, [10, 20, 30], 'strona internetowa warszawa'),
			self::item(3, 480, [10], 'tworzenie stron warszawa'),
			// Wspólny z frazą 2 tylko adres 30 — z liderem (1) nie ma powiązania: nie dołącza łańcuchem.
			self::item(4, 300, [30, 40], 'projektowanie logo'),
			self::item(5, 200, [], 'warszawa strony internetowe'),
		]);

		$assignments = $result['assignments'];
		self::assertSame($assignments[1], $assignments[2]);
		self::assertSame($assignments[1], $assignments[3], 'Wspólny jedyny adres frazy.');
		self::assertNotSame($assignments[1], $assignments[4]);
		self::assertSame($assignments[1], $assignments[5], 'Ten sam zbiór wyrazów.');
		self::assertSame([1, 4], $result['leaders']);
		self::assertSame(['1' => 'leader', '2' => 'urls', '3' => 'urls', '4' => 'leader', '5' => 'words'], array_map('strval', $result['reasons']));
	}

	public function test_provider_synonyms_project_target_and_serp_overlap_link_keywords(): void
	{
		$result = (new KeywordClusterer(4))->cluster([
			self::item(1, 1000, [], 'sklep internetowy', 'aa'),
			self::item(2, 900, [], 'sklepy internetowe', 'aa'),
			self::item(3, 800, [], 'oferta seo', null, 77),
			self::item(4, 700, [], 'pozycjonowanie seo', null, 77),
			self::item(5, 600, [], 'audyt seo', null, null, [1, 2, 3, 4, 5]),
			self::item(6, 500, [], 'analiza seo', null, null, [1, 2, 3, 4, 9]),
			self::item(7, 400, [], 'kurs seo', null, null, [1, 2, 3, 8, 9]),
		]);
		$a = $result['assignments'];

		self::assertSame($a[1], $a[2], 'Grupa synonimów dostawcy (bez stemmingu).');
		self::assertSame($a[3], $a[4], 'Ta sama strona projektu.');
		self::assertSame($a[5], $a[6], '4 wspólne adresy TOP10.');
		self::assertNotSame($a[5], $a[7], 'Tylko 3 wspólne adresy.');
	}

	public function test_clustering_is_deterministic_regardless_of_input_order(): void
	{
		$items = [
			self::item(3, 100, [5], 'a b'),
			self::item(1, 100, [5], 'c d'),
			self::item(2, 500, [6], 'e f'),
		];
		$first = (new KeywordClusterer())->cluster($items);
		$second = (new KeywordClusterer())->cluster(array_reverse($items));

		self::assertSame($first, $second);
		self::assertSame([2, 1], $first['leaders'], 'Wolumen malejąco, potem id rosnąco.');
	}

	private static function evidence(array $overrides = []): ClusterEvidence
	{
		$values = $overrides + [
			'hasProjectData' => true, 'targetUrlId' => null, 'targetSource' => null, 'targetIsRoot' => false, 'scattered' => false,
			'competitorPages' => 2, 'dedicatedPages' => 2, 'leaderType' => GapType::Missing, 'gapShare' => 1.0, 'gapVolume' => 1500,
			'baselineReliable' => true, 'hasGsc' => true,
		];

		return new ClusterEvidence(...$values);
	}

	public function test_content_gap_classification_rules(): void
	{
		$classifier = new ContentGapClassifier();

		$new = $classifier->classify(self::evidence());
		self::assertSame(ContentGap::NewPage, $new->gap);
		self::assertSame('no_target', $new->reason);
		self::assertSame('high', $new->confidence);
		self::assertSame('Potencjalna luka treści', $new->gap->label());

		self::assertSame('medium', $classifier->classify(self::evidence(['dedicatedPages' => 1]))->confidence);
		self::assertSame(ContentGap::Unclear, $classifier->classify(self::evidence(['hasProjectData' => false]))->gap);
		self::assertSame('scattered', $classifier->classify(self::evidence(['scattered' => true]))->reason);
		self::assertSame('competitors_homepages', $classifier->classify(self::evidence(['competitorPages' => 0, 'dedicatedPages' => 0]))->reason);
		self::assertSame('low_demand', $classifier->classify(self::evidence(['gapVolume' => 20]))->reason);

		$improve = $classifier->classify(self::evidence(['targetUrlId' => 5, 'targetSource' => 'gsc', 'leaderType' => GapType::Weak]));
		self::assertSame(ContentGap::Improve, $improve->gap);
		self::assertSame('target_gsc', $improve->reason);
		self::assertSame('high', $improve->confidence);
		self::assertSame('Istniejąca strona — do wzmocnienia', $improve->gap->label());

		$homepage = $classifier->classify(self::evidence(['targetUrlId' => 5, 'targetSource' => 'gsc', 'targetIsRoot' => true]));
		self::assertSame(ContentGap::NewPage, $homepage->gap);
		self::assertSame('homepage_only', $homepage->reason);

		$covered = $classifier->classify(self::evidence(['targetUrlId' => 5, 'targetSource' => 'serp', 'leaderType' => GapType::Competitive, 'gapShare' => 0.2]));
		self::assertSame(ContentGap::Covered, $covered->gap);
		self::assertSame('Bez luki treści', $covered->gap->label());
	}

	public function test_target_resolution_order_and_scattered_pages(): void
	{
		self::assertSame([7, 'serp', false], ContentGapClassifier::target([7 => 2, 8 => 1], [9 => 1000], [10 => 500], 11, 10));
		self::assertSame([9, 'gsc', false], ContentGapClassifier::target([], [9 => 700, 12 => 200], [10 => 500], 11, 10), 'GSC: ≥ 60% wyświetleń grupy.');
		self::assertSame([null, null, true], ContentGapClassifier::target([], [9 => 500, 12 => 400], [10 => 500], 11, 10), 'Rozproszone: brak dominującej, druga ≥ 20%.');
		self::assertSame([10, 'labs', false], ContentGapClassifier::target([], [9 => 5], [10 => 500, 13 => 100], 11, 10), 'Za mało wyświetleń GSC → Labs.');
		self::assertSame([11, 'slug', false], ContentGapClassifier::target([], [], [], 11, 10));
		self::assertSame([null, null, false], ContentGapClassifier::target([], [], [], null, 10));
	}
}
