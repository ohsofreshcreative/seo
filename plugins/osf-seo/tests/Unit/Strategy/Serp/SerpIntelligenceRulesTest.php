<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Strategy\Serp;

use DateTimeImmutable;
use DateTimeZone;
use OsfSeo\Serp\ItemTypes;
use OsfSeo\Serp\SerpItem;
use OsfSeo\Strategy\Serp\OverlapSide;
use OsfSeo\Strategy\Serp\ResultShape;
use OsfSeo\Strategy\Serp\ResultShapeClassifier;
use OsfSeo\Strategy\Serp\SerpConfidence;
use OsfSeo\Strategy\Serp\SerpFreshness;
use OsfSeo\Strategy\Serp\SerpIntentSignal;
use OsfSeo\Strategy\Serp\SerpOverlap;
use OsfSeo\Strategy\Serp\SerpProfile;
use OsfSeo\Strategy\Serp\SerpProfiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SerpIntelligenceRulesTest extends TestCase
{
	public function test_freshness_policy_is_30_and_90_days(): void
	{
		$now = new DateTimeImmutable('2026-03-31 12:00:00', new DateTimeZone('UTC'));

		self::assertNull(SerpFreshness::of(null, $now));
		self::assertSame(SerpFreshness::FRESH, SerpFreshness::of('2026-03-01 12:00:00', $now));
		self::assertSame(SerpFreshness::STALE, SerpFreshness::of('2026-03-01 11:59:59', $now));
		self::assertSame(SerpFreshness::STALE, SerpFreshness::of('2025-12-31 12:00:00', $now));
		self::assertSame(SerpFreshness::EXPIRED, SerpFreshness::of('2025-12-31 11:59:59', $now));
		self::assertTrue(SerpFreshness::allowsProjectRank(SerpFreshness::FRESH));
		self::assertFalse(SerpFreshness::allowsProjectRank(SerpFreshness::STALE), '31–90 dni: bez Pozycji SERP projektu.');
		self::assertTrue(SerpFreshness::usableForClassification(SerpFreshness::STALE));
		self::assertFalse(SerpFreshness::usableForClassification(SerpFreshness::EXPIRED));
		self::assertSame(SerpConfidence::MEDIUM, SerpConfidence::forFreshness(SerpConfidence::HIGH, SerpFreshness::STALE));
		self::assertNull(SerpConfidence::forFreshness(SerpConfidence::HIGH, SerpFreshness::EXPIRED));
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: ?string, 3: ResultShape, 4: string, 5: string}>
	 */
	public static function shapes(): array
	{
		return [
			'strona główna' => ['https://example.pl/', 0, null, ResultShape::Home, SerpConfidence::HIGH, 'root_path'],
			'katalog języka' => ['https://example.com/pl/', 0, null, ResultShape::Home, SerpConfidence::MEDIUM, 'language_root'],
			'wideo (platforma)' => ['https://www.youtube.com/watch?v=abc', 0, null, ResultShape::Video, SerpConfidence::HIGH, 'video_host'],
			'wyszukiwarka sklepu' => ['https://allegro.pl/listing?string=buty+damskie', 0, null, ResultShape::Listing, SerpConfidence::MEDIUM, 'search_query'],
			'kategoria' => ['https://sklep.pl/kategoria/buty-damskie/', 0, null, ResultShape::Listing, SerpConfidence::MEDIUM, 'listing_path'],
			'produkt z ceną' => ['https://sklep.pl/produkt/kozaki-czarne/', SerpItem::FLAG_PRICE, null, ResultShape::Product, SerpConfidence::HIGH, 'product_path'],
			'oferta z identyfikatorem' => ['https://allegro.pl/oferta/kozaki-damskie-12345678901', 0, null, ResultShape::Product, SerpConfidence::MEDIUM, 'product_path'],
			'oferta usług firmy' => ['https://agencja.pl/oferta/pozycjonowanie-stron/', 0, null, ResultShape::Subpage, SerpConfidence::LOW, 'deep_path'],
			'cena bez wzorca adresu' => ['https://sklep.pl/kozaki-czarne', SerpItem::FLAG_PRICE, null, ResultShape::Product, SerpConfidence::MEDIUM, 'price'],
			'data publikacji' => ['https://serwis.pl/jak-wybrac-buty', 0, '2026-01-02 10:00:00', ResultShape::Article, SerpConfidence::HIGH, 'published_date'],
			'encyklopedia' => ['https://pl.wikipedia.org/wiki/But', 0, null, ResultShape::Article, SerpConfidence::HIGH, 'encyclopedia'],
			'blog' => ['https://serwis.pl/blog/jak-wybrac-buty/', 0, null, ResultShape::Article, SerpConfidence::MEDIUM, 'article_path'],
			'data w ścieżce' => ['https://serwis.pl/2025/11/buty-zimowe/', 0, null, ResultShape::Article, SerpConfidence::MEDIUM, 'date_path'],
			'flaga wideo' => ['https://serwis.pl/film-o-butach', SerpItem::FLAG_VIDEO, null, ResultShape::Video, SerpConfidence::MEDIUM, 'video_flag'],
			'zwykła podstrona' => ['https://firma.pl/pozycjonowanie-stron-lodz/', 0, null, ResultShape::Subpage, SerpConfidence::LOW, 'deep_path'],
		];
	}

	#[DataProvider('shapes')]
	public function test_result_shape_rules(string $url, int $flags, ?string $published, ResultShape $shape, string $confidence, string $reason): void
	{
		$host = (string) parse_url($url, PHP_URL_HOST);

		self::assertSame(['shape' => $shape, 'confidence' => $confidence, 'reason' => $reason], (new ResultShapeClassifier())->classify($url, $host, $flags, $published));
	}

	public function test_profile_composition_dominant_shape_and_informational_signal(): void
	{
		$rows = [];

		foreach (range(1, 20) as $rank) {
			$rows[] = self::row($rank, $rank <= 7 ? 'serwis' . $rank . '.pl' : 'inny' . $rank . '.pl', $rank <= 7 ? '/blog/wpis-' . $rank . '/' : '/strona-' . $rank . '/');
		}

		$rows[0] = self::row(1, 'example.pl', '/');
		$profile = (new SerpProfiler())->profile(5, $rows, ItemTypes::mask(['organic', 'people_also_ask', 'ai_overview']));

		self::assertSame([10, 20, 10, 20, 1, 1], [$profile->organicTop10, $profile->organicTop20, $profile->domainsTop10, $profile->domainsTop20, $profile->topDomainTop10, $profile->homeTop10]);
		self::assertSame(['home' => 1, 'subpage' => 3, 'article' => 6], $profile->shapesTop10);
		self::assertSame(['article', 0.6, SerpConfidence::MEDIUM], [$profile->shape, $profile->shapeShare, $profile->shapeConfidence], 'Ścieżki /blog/ — pewność średnia.');
		self::assertSame([SerpIntentSignal::Informational, SerpConfidence::HIGH], [$profile->intent, $profile->intentConfidence], 'Artykuły + pytania w SERP.');
		self::assertSame(['questions', 'ai_overview'], $profile->features);
		self::assertSame([1, 'home', 'high', 'root_path'], $profile->results[0]);
		self::assertCount(20, $profile->results);

		$copy = SerpProfile::fromRow($profile->toRow() + ['project_id' => '1', 'created_at' => '2026-01-01 00:00:00']);
		self::assertEquals($profile, $copy, 'Profil zapisany i odczytany bez strat.');
	}

	public function test_intent_signal_rules_never_claim_certain_commercial_intent_from_fallback_subpages(): void
	{
		$profiler = new SerpProfiler();
		$make = static fn (array $paths, array $types = ['organic'], ?string $host = null): SerpProfile => $profiler->profile(1, array_map(
			static fn (int $index, string $path): array => self::row($index + 1, $host ?? 'domena' . $index . '.pl', $path),
			array_keys($paths),
			$paths,
		), ItemTypes::mask($types));

		$services = $make(array_map(static fn (int $i): string => '/uslugi-' . $i . '/', range(1, 10)));
		self::assertSame(['subpage', SerpConfidence::LOW, SerpIntentSignal::Commercial, SerpConfidence::MEDIUM], [$services->shape, $services->shapeConfidence, $services->intent, $services->intentConfidence]);

		$shop = $make([...array_fill(0, 5, '/kategoria/buty/'), ...array_fill(0, 5, '/produkt/but/')], ['organic', 'shopping']);
		self::assertSame([SerpIntentSignal::Transactional, SerpConfidence::HIGH], [$shop->intent, $shop->intentConfidence]);

		$local = $make(array_map(static fn (int $i): string => '/firma-' . $i . '/', range(1, 10)), ['organic', 'local_pack']);
		self::assertSame(SerpIntentSignal::Local, $local->intent);

		$brand = $make(array_map(static fn (int $i): string => '/sekcja-' . $i . '/', range(1, 10)), ['organic'], 'marka.pl');
		self::assertSame([SerpIntentSignal::Navigational, SerpConfidence::HIGH, 10], [$brand->intent, $brand->intentConfidence, $brand->topDomainTop10]);

		$mixed = $make(['/', '/blog/a/', '/kategoria/b/', '/produkt/c/', '/d/', 'https://youtube.com/watch?v=1']);
		self::assertSame(['mixed', SerpIntentSignal::Mixed], [$mixed->shape, $mixed->intent]);

		$tie = $make([...array_fill(0, 5, '/blog/wpis/'), ...array_map(static fn (int $i): string => '/uslugi-' . $i . '/', range(1, 5))], ['organic', 'people_also_ask']);
		self::assertSame(['mixed', 0.5, SerpIntentSignal::Informational, SerpConfidence::MEDIUM], [$tie->shape, $tie->shapeShare, $tie->intent, $tie->intentConfidence], 'Remis kształtów — mieszany; 50% artykułów z pytaniami — pewność średnia.');

		$few = $make(['/a/', '/b/']);
		self::assertSame(['unknown', SerpIntentSignal::Unknown, SerpConfidence::LOW], [$few->shape, $few->intent, $few->shapeConfidence], 'Za mało wyników do klasyfikacji.');
	}

	public function test_strong_overlap_needs_four_counted_urls_in_fresh_comparable_measurements(): void
	{
		$a = self::side(1, [11 => [1, 101], 12 => [2, 102], 13 => [3, 103], 14 => [4, 104], 15 => [5, 105]]);
		$b = self::side(2, [11 => [2, 101], 12 => [1, 102], 13 => [5, 103], 14 => [7, 104], 16 => [3, 106]]);

		$result = SerpOverlap::compare($a, $b);

		self::assertSame([SerpOverlap::STRONG, true, 4, 4, 0, 4, 0.4], [$result['level'], $result['mergeable'], $result['shared_urls'], $result['counted_urls'], $result['discounted_urls'], $result['shared_domains'], $result['score']]);
		self::assertSame([11, 12, 13, 14], array_column($result['urls'], 'url_id'), 'Od najwyższych łącznych pozycji.');

		$three = SerpOverlap::compare($a, self::side(2, [11 => [1, 101], 12 => [2, 102], 13 => [3, 103]]));
		self::assertSame([SerpOverlap::MODERATE, false], [$three['level'], $three['mergeable']]);
		self::assertSame(SerpOverlap::WEAK, SerpOverlap::compare($a, self::side(2, [11 => [9, 101]]))['level']);
		self::assertSame(SerpOverlap::WEAK, SerpOverlap::compare($a, self::side(2, [21 => [1, 101], 22 => [2, 102], 23 => [3, 103]]))['level'], 'Same wspólne domeny — tylko słaby sygnał.');
		self::assertSame(SerpOverlap::NONE, SerpOverlap::compare($a, self::side(2, [31 => [1, 201]]))['level']);
	}

	public function test_overlap_safeguards_against_false_merging(): void
	{
		$top = [11 => [1, 101], 12 => [2, 102], 13 => [3, 103], 14 => [4, 104], 15 => [5, 105]];
		$a = self::side(1, $top);

		// Domeny wszechobecne i strony główne nie wchodzą do progu.
		$ubiquitous = SerpOverlap::compare($a, self::side(2, $top), [101 => true, 102 => true]);
		self::assertSame([SerpOverlap::MODERATE, 5, 3, 2], [$ubiquitous['level'], $ubiquitous['shared_urls'], $ubiquitous['counted_urls'], $ubiquitous['discounted_urls']]);
		self::assertContains('discounted_ubiquitous_or_home', $ubiquitous['reasons']);
		$homes = [11 => [1, 101, true], 12 => [2, 102, true], 13 => [3, 103], 14 => [4, 104]];
		self::assertSame(SerpOverlap::MODERATE, SerpOverlap::compare(self::side(1, $homes), self::side(2, $homes))['level']);

		// Niezgodny kontekst, brak albo wygaśnięcie pomiaru — nieporównywalne.
		self::assertSame(['incomparable', ['context_mismatch']], self::levelAndReasons(SerpOverlap::compare($a, self::side(2, $top, '2616|pl|mobile'))));
		self::assertSame(['incomparable', ['expired_measurement']], self::levelAndReasons(SerpOverlap::compare($a, self::side(2, $top, freshness: SerpFreshness::EXPIRED))));
		self::assertSame(['incomparable', ['no_measurement']], self::levelAndReasons(SerpOverlap::compare($a, new OverlapSide(2, 'b', null, null, null, []))));

		// Nieaktualny pomiar i sprzeczne intencje — najwyżej umiarkowany.
		self::assertSame(['moderate', ['stale_measurement']], self::levelAndReasons(SerpOverlap::compare($a, self::side(2, $top, freshness: SerpFreshness::STALE))));
		self::assertSame(['moderate', ['provider_intent_mismatch']], self::levelAndReasons(SerpOverlap::compare(self::side(1, $top, provider: 'informational'), self::side(2, $top, provider: 'commercial'))));
		self::assertSame(['moderate', ['serp_intent_mismatch']], self::levelAndReasons(SerpOverlap::compare(
			self::side(1, $top, intent: SerpIntentSignal::Informational),
			self::side(2, $top, intent: SerpIntentSignal::Transactional),
		)));
		self::assertSame('strong', SerpOverlap::compare(self::side(1, $top, intent: SerpIntentSignal::Informational), self::side(2, $top, intent: SerpIntentSignal::Mixed))['level'], 'Mieszana intencja nie jest sprzecznością.');
		self::assertSame('strong', SerpOverlap::compare(self::side(1, $top, provider: 'commercial'), self::side(2, $top))['level'], 'Brak intencji dostawcy nie jest sprzecznością.');
	}

	public function test_ubiquitous_domains_are_detected_only_with_enough_measurements(): void
	{
		// Domena 1 we wszystkich, 2 w czterech (40%), 3 w trzech pomiarach z dziesięciu.
		$sets = array_map(static fn (int $i): array => array_filter([1 => true, 2 => $i < 4, 3 => $i < 3, 100 + $i => true]), range(0, 9));

		self::assertSame(['domains' => [1 => true, 2 => true], 'known' => true, 'measurements' => 10, 'threshold' => 4], SerpOverlap::ubiquitous($sets));
		self::assertSame(['domains' => [], 'known' => false, 'measurements' => 9, 'threshold' => 4], SerpOverlap::ubiquitous(array_slice($sets, 0, 9)), 'Mała próba — wspólni konkurenci tematyczni nie są wszechobecni.');
		self::assertContains('ubiquity_unknown', SerpOverlap::compare(self::side(1, [11 => [1, 101]]), self::side(2, [11 => [1, 101]]), [], false)['reasons']);
	}

	/**
	 * @param array<int, array{0: int, 1: int, 2?: bool}> $top url_id → [pozycja, domena, strona główna?]
	 */
	private static function side(int $id, array $top, string $context = '2616|pl|desktop', string $freshness = SerpFreshness::FRESH, ?string $provider = null, ?SerpIntentSignal $intent = null): OverlapSide
	{
		$rows = [];

		foreach ($top as $urlId => $row) {
			$rows[$urlId] = ['rank' => $row[0], 'domain_id' => $row[1], 'home' => $row[2] ?? false];
		}

		return new OverlapSide($id, 'fraza ' . $id, 100 + $id, $context, $freshness, $rows, $intent, $intent === null ? null : SerpConfidence::HIGH, $provider);
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array{0: string, 1: list<string>}
	 */
	private static function levelAndReasons(array $result): array
	{
		return [$result['level'], $result['reasons']];
	}

	/**
	 * @return array{rank: int, host: string, url: string, flags: int, published_at: ?string}
	 */
	private static function row(int $rank, string $host, string $path, int $flags = 0, ?string $published = null): array
	{
		return ['rank' => $rank, 'host' => $host, 'url' => str_starts_with($path, 'http') ? $path : 'https://' . $host . $path, 'flags' => $flags, 'published_at' => $published];
	}
}
