<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Discovery;

use OsfSeo\DataForSeo\KeywordRules;
use OsfSeo\Discovery\CandidateStatus;
use OsfSeo\Discovery\DiscoveryConfig;
use OsfSeo\Discovery\DiscoveryMethod;
use OsfSeo\Discovery\DiscoveryScore;
use OsfSeo\Discovery\DiscoveryScorer;
use OsfSeo\Discovery\ExclusionList;
use OsfSeo\Discovery\Relation;
use OsfSeo\Discovery\SeedList;
use OsfSeo\Discovery\Visibility;
use OsfSeo\Discovery\VisibilityClassifier;
use OsfSeo\Discovery\VisibilityResult;
use OsfSeo\Support\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscoveryRulesTest extends TestCase
{
	private VisibilityClassifier $classifier;

	protected function setUp(): void
	{
		$this->classifier = new VisibilityClassifier(10, 10.0, 0.1, 90);
	}

	protected function tearDown(): void
	{
		foreach ([DiscoveryConfig::MAX_CANDIDATES, DiscoveryConfig::TTL_DAYS, DiscoveryConfig::VISIBLE_SHARE] as $name) {
			putenv($name);
		}
	}

	public function test_no_gsc_data_is_unknown_and_few_impressions_are_no_visibility(): void
	{
		self::assertSame(Visibility::Unknown, $this->classifier->classify(false, 0, 0, 0.0, 1000)->visibility);
		self::assertSame(Visibility::None, $this->classifier->classify(true, 0, 0, 0.0, 1000)->visibility);

		$few = $this->classifier->classify(true, 9, 1, 9 * 3.0, 50);
		self::assertSame([Visibility::None, 9, 3.0], [$few->visibility, $few->impressions, $few->position], 'Poniżej 10 wyświetleń — brak istotnej widoczności, nawet przy dobrej pozycji.');
	}

	public function test_position_45_with_meaningful_volume_is_low_visibility(): void
	{
		$result = $this->classifier->classify(true, 120, 0, 120 * 45.0, 1900);

		self::assertSame([Visibility::Low, 45.0, false], [$result->visibility, $result->position, $result->sporadic]);
	}

	public function test_top_positions_with_strong_impressions_are_already_visible(): void
	{
		// Wolumen 1000/mies. → ok. 3000 wyszukiwań w 90 dniach; 2400 wyświetleń przy pozycji 2 = stała widoczność.
		$result = $this->classifier->classify(true, 2400, 600, 2400 * 2.0, 1000);

		self::assertSame([Visibility::Visible, 2.0], [$result->visibility, $result->position]);
		self::assertSame(Visibility::Visible, $this->classifier->classify(true, 15, 1, 15 * 10.0, null)->visibility, 'Granica TOP 10 włącznie; bez wolumenu udział nie jest sprawdzany.');
	}

	public function test_top_position_with_tiny_share_of_volume_is_sporadic_low_visibility(): void
	{
		// 15 wyświetleń przy wolumenie 2400/mies. (7200 w 90 dniach) = 0,2% — fraza pojawia się sporadycznie.
		$result = $this->classifier->classify(true, 15, 1, 15 * 6.0, 2400);

		self::assertSame([Visibility::Low, true], [$result->visibility, $result->sporadic]);
	}

	public function test_position_is_weighted_by_impressions_not_averaged(): void
	{
		// Dwa warianty frazy: 100 wyświetleń na poz. 4 i 10 wyświetleń na poz. 60 → (400 + 600) / 110 = 9,09 (TOP 10),
		// a średnia arytmetyczna pozycji dałaby 32.
		$result = $this->classifier->classify(true, 110, 20, 100 * 4.0 + 10 * 60.0, 50);

		self::assertSame([Visibility::Visible, 9.09], [$result->visibility, $result->position]);
	}

	public function test_priority_formula_components(): void
	{
		$scorer = new DiscoveryScorer(10.0);
		$score = $scorer->score(1000, 40, 2.5, 80, 1, new VisibilityResult(Visibility::None));

		// Popyt 35 × log10(1001)/log10(10001) = 26,3; osiągalność 25 × 0,6; trafność 20 × 0,8; brak widoczności 15;
		// CPC 5 × log10(3,5)/log10(11) = 2,6 → 74,9 ≈ 75.
		self::assertSame(['demand' => 26.3, 'attainability' => 15.0, 'relevance' => 16.0, 'gap' => 15.0, 'commercial' => 2.6], $score->components);
		self::assertSame(75, $score->priority);
		self::assertSame($score->toArray(), DiscoveryScore::fromArray($score->toArray())?->toArray());
	}

	public function test_volume_is_log_scaled_and_capped_so_huge_volume_does_not_dominate(): void
	{
		$scorer = new DiscoveryScorer(10.0);
		$none = new VisibilityResult(Visibility::None);

		self::assertSame(35.0, $scorer->score(10000, 50, null, 60, 1, $none)->components['demand']);
		self::assertSame(35.0, $scorer->score(5000000, 50, null, 60, 1, $none)->components['demand'], 'Ponad limit — bez dodatkowych punktów.');
		self::assertSame(9.1, $scorer->score(10, 50, null, 60, 1, $none)->components['demand']);
		self::assertSame(0.0, $scorer->score(null, 50, null, 60, 1, $none)->components['demand'], 'Brak wolumenu — brak punktów popytu (nie zero udające dane).');
		self::assertSame(5.0, $scorer->score(10, 50, 250.0, 60, 1, $none)->components['commercial'], 'CPC ograniczone do 5 punktów.');
	}

	public function test_missing_difficulty_is_neutral_and_multiple_seeds_raise_relevance(): void
	{
		$scorer = new DiscoveryScorer(10.0);
		$none = new VisibilityResult(Visibility::None);

		self::assertSame(12.5, $scorer->score(100, null, null, 60, 1, $none)->components['attainability']);
		self::assertSame(25.0, $scorer->score(100, 0, null, 60, 1, $none)->components['attainability']);
		self::assertSame(0.0, $scorer->score(100, 100, null, 60, 1, $none)->components['attainability']);
		self::assertSame(12.0, $scorer->score(100, 50, null, 60, 1, $none)->components['relevance']);
		self::assertSame(14.0, $scorer->score(100, 50, null, 60, 2, $none)->components['relevance'], 'Fraza z dwóch seedów — mocniejsze potwierdzenie.');
		self::assertSame(20.0, $scorer->score(100, 50, null, 100, 6, $none)->components['relevance']);
	}

	public function test_gsc_gap_component(): void
	{
		$scorer = new DiscoveryScorer(10.0);
		$gap = static fn (VisibilityResult $visibility): float => $scorer->score(100, 50, null, 60, 1, $visibility)->components['gap'];

		self::assertSame(15.0, $gap(new VisibilityResult(Visibility::None)));
		self::assertSame(7.5, $gap(new VisibilityResult(Visibility::Unknown)));
		self::assertSame(0.0, $gap(new VisibilityResult(Visibility::Visible, 500, 50, 2.0)));
		self::assertSame(13.1, $gap(new VisibilityResult(Visibility::Low, 120, 0, 45.0, false)), 'Pozycja 45 — prawie pełna luka.');
		self::assertSame(15.0, $gap(new VisibilityResult(Visibility::Low, 40, 0, 62.0, false)));
		self::assertSame(3.0, $gap(new VisibilityResult(Visibility::Low, 120, 0, 11.0, false)), 'Tuż za TOP 10 — mała luka (dolna granica 0,2).');
		self::assertSame(7.5, $gap(new VisibilityResult(Visibility::Low, 120, 0, 30.0, false)));
		self::assertSame(6.0, $gap(new VisibilityResult(Visibility::Low, 15, 1, 6.0, true)));
	}

	#[DataProvider('relations')]
	public function test_relation_to_seed(DiscoveryMethod $method, string $keyword, ?int $depth, int $expected): void
	{
		self::assertSame($expected, Relation::of($method, 'strony internetowe', $keyword, $depth));
	}

	/**
	 * @return iterable<string, array{0: DiscoveryMethod, 1: string, 2: ?int, 3: int}>
	 */
	public static function relations(): iterable
	{
		yield 'sam seed' => [DiscoveryMethod::Related, 'strony internetowe', 0, 100];
		yield 'related, głębokość 1' => [DiscoveryMethod::Related, 'tworzenie stron www', 1, 80];
		yield 'related, głębokość 2' => [DiscoveryMethod::Related, 'agencja interaktywna', 2, 60];
		yield 'related, głębokość 3' => [DiscoveryMethod::Related, 'hosting', 3, 40];
		yield 'suggestions, zawiera frazę seeda' => [DiscoveryMethod::Suggestions, 'tanie strony internetowe kraków', null, 80];
		yield 'suggestions, inna odmiana' => [DiscoveryMethod::Suggestions, 'strona internetowa cena', null, 60];
	}

	public function test_exclusions_match_whole_words_and_prefix_wildcards(): void
	{
		$list = ExclusionList::parse("praca\nDarmow*, torrent;  \n\n* ;jak zrobić");

		self::assertSame(['praca', 'darmow*', 'torrent', 'jak zrobić'], $list->terms);
		self::assertSame('praca', $list->match('praca web developer'));
		self::assertNull($list->match('pracownia graficzna'), 'Całe słowa — „pracownia” to nie „praca”.');
		self::assertSame('darmow*', $list->match('darmowe strony internetowe'));
		self::assertSame('darmow*', $list->match('strona darmowa'));
		self::assertSame('jak zrobić', $list->match('jak zrobić stronę'));
		self::assertNull($list->match('jak szybko zrobić stronę'), 'Wielowyrazowe wykluczenie = ciąg słów.');
		self::assertNull(ExclusionList::parse('')->match('praca'));
		self::assertTrue(ExclusionList::parse(" \n ")->isEmpty());
		self::assertSame(md5("praca\ndarmow*\ntorrent\njak zrobić"), $list->hash());
	}

	public function test_seed_list_normalizes_dedupes_and_rejects_before_any_request(): void
	{
		$list = SeedList::parse([
			SeedList::SOURCE_MANUAL => "Strony Internetowe\n strony   internetowe \nWooCommerce, ux ui\nsklep?\na\n" . str_repeat('słowo ', 11),
			SeedList::SOURCE_GSC => ['projektowanie stron', 'woocommerce'],
		], static fn (string $seed): bool => KeywordRules::accepts($seed), 3);

		self::assertSame(['strony internetowe' => 'manual', 'woocommerce' => 'manual', 'ux ui' => 'manual'], $list->accepted);
		self::assertSame([
			['seed' => 'sklep?', 'reason' => 'provider_rules'],
			['seed' => 'a', 'reason' => 'too_short'],
			['seed' => trim(str_repeat('słowo ', 11)), 'reason' => 'provider_rules'],
			['seed' => 'projektowanie stron', 'reason' => 'limit'],
		], $list->rejected);
	}

	public function test_candidate_status_labels_and_config_options(): void
	{
		self::assertSame(['Nowa', 'Do analizy', 'Zaakceptowana', 'Odrzucona'], array_map(static fn (CandidateStatus $status): string => $status->label(), CandidateStatus::cases()));
		self::assertSame(['new', 'review'], CandidateStatus::openValues());

		$config = new DiscoveryConfig(new Config());
		self::assertSame([100, 250, 500, 1000], $config->candidateOptions());
		self::assertSame(30, $config->ttlDays());
		putenv(DiscoveryConfig::MAX_CANDIDATES . '=300');
		putenv(DiscoveryConfig::TTL_DAYS . '=9999');
		putenv(DiscoveryConfig::VISIBLE_SHARE . '=abc');
		self::assertSame([100, 250], $config->candidateOptions());
		self::assertSame(365, $config->ttlDays(), 'Wartości spoza zakresu są przycinane.');
		self::assertSame(0.1, $config->visibleShare(), 'Nieprawidłowa wartość — domyślna.');
	}
}
