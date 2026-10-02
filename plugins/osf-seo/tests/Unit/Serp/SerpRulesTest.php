<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Serp;

use OsfSeo\Serp\DomainFamily;
use OsfSeo\Serp\ItemTypes;
use OsfSeo\Serp\RankChange;
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpContext;
use OsfSeo\Serp\SerpDevice;
use OsfSeo\Serp\SerpFrequency;
use OsfSeo\Serp\SerpKeywordRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SerpRulesTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, ?string}>
	 */
	public static function hosts(): iterable
	{
		yield 'https + www + ścieżka' => ['https://www.example.pl/', 'example.pl'];
		yield 'www' => ['www.example.pl', 'example.pl'];
		yield 'goła domena' => ['example.pl', 'example.pl'];
		yield 'wielkie litery i kropka na końcu' => ['EXAMPLE.PL.', 'example.pl'];
		yield 'port' => ['example.pl:8080', 'example.pl'];
		yield 'subdomena zostaje' => ['blog.example.pl', 'blog.example.pl'];
		yield 'IDN → punycode' => ['https://żółw.pl/', 'xn--w-uga1v8h.pl'];
		yield 'adres IP' => ['127.0.0.1', null];
		yield 'pusty' => ['', null];
	}

	#[DataProvider('hosts')]
	public function test_hosts_and_domains_are_normalized_like_the_project_domain(string $input, ?string $expected): void
	{
		if ($expected !== null && str_starts_with($expected, 'xn--') && ! function_exists('idn_to_ascii')) {
			self::markTestSkipped('Brak rozszerzenia intl.');
		}

		self::assertSame($expected, DomainFamily::normalize($input));
	}

	public function test_family_matching_uses_label_boundaries_not_substrings(): void
	{
		self::assertTrue(DomainFamily::matches('example.pl', 'example.pl'));
		self::assertTrue(DomainFamily::matches('blog.example.pl', 'example.pl'), 'Subdomena należy do rodziny.');
		self::assertTrue(DomainFamily::matches('a.b.example.pl', 'example.pl'));
		self::assertFalse(DomainFamily::matches('notexample.pl', 'example.pl'), 'Zwodniczy sufiks.');
		self::assertFalse(DomainFamily::matches('example.pl.evil.com', 'example.pl'));
		self::assertFalse(DomainFamily::matches('example.com', 'example.pl'));
		self::assertFalse(DomainFamily::matches('example.pl', 'blog.example.pl'), 'Domena nadrzędna nie należy do rodziny subdomeny.');
		self::assertTrue(DomainFamily::overlaps('example.pl', 'shop.example.pl'));
		self::assertFalse(DomainFamily::overlaps('example.pl', 'example.com'));
		self::assertSame('pl.example.blog', DomainFamily::reverse('blog.example.pl'));
		self::assertSame('ex.pl', DomainFamily::fromUrl('https://www.ex.pl:443/a?b#c'));
	}

	/**
	 * @return iterable<string, array{bool, bool, ?int, ?int, string, ?int, ?string}>
	 */
	public static function changes(): iterable
	{
		yield '#12 → #7 = +5' => [true, true, 12, 7, RankChange::UP, 5, RankChange::ENTERED];
		yield '#4 → #9 = −5' => [true, true, 4, 9, RankChange::DOWN, -5, null];
		yield '#9 → #11 wypada z TOP10' => [true, true, 9, 11, RankChange::DOWN, -2, RankChange::LEFT];
		yield 'bez zmiany' => [true, true, 3, 3, RankChange::SAME, 0, null];
		yield 'poza TOP → #34' => [true, true, null, 34, RankChange::ENTERED, null, null];
		yield 'poza TOP → #5' => [true, true, null, 5, RankChange::ENTERED, null, RankChange::ENTERED];
		yield '#28 → poza TOP' => [true, true, 28, null, RankChange::LEFT, null, null];
		yield '#2 → poza TOP' => [true, true, 2, null, RankChange::LEFT, null, RankChange::LEFT];
		yield 'poza TOP w obu' => [true, true, null, null, RankChange::OUT, null, null];
		yield 'pierwszy pomiar' => [false, false, null, 3, RankChange::NEW, null, null];
		yield 'inny kontekst' => [false, true, null, 3, RankChange::INCOMPARABLE, null, null];
	}

	#[DataProvider('changes')]
	public function test_rank_change_never_computes_a_number_from_an_empty_position(bool $hasPrevious, bool $hadAny, ?int $previous, ?int $current, string $type, ?int $value, ?string $top10): void
	{
		$change = RankChange::compare($hasPrevious, $hadAny, $previous, $current);

		self::assertSame([$type, $value, $top10], [$change->type, $change->value, $change->top10]);
	}

	public function test_rank_change_labels_use_checked_depth(): void
	{
		self::assertSame('+5', RankChange::label(RankChange::UP, 5, 100));
		self::assertSame('−5', RankChange::label(RankChange::DOWN, -5, 100));
		self::assertSame('Weszła do TOP100', RankChange::label(RankChange::ENTERED, null, 100));
		self::assertSame('Wypadła z TOP50', RankChange::label(RankChange::LEFT, null, 50));
		self::assertSame('Poza TOP100', RankChange::label(RankChange::OUT, null, 100));
	}

	public function test_item_types_mask_keeps_known_types_and_marks_unknown_as_other(): void
	{
		$mask = ItemTypes::mask(['organic', 'ai_overview', 'zupelnie_nowy', 7]);

		self::assertSame(['organic', 'ai_overview', 'other'], ItemTypes::fromMask($mask));
		self::assertSame(['AI Overview'], ItemTypes::labels($mask));
		self::assertLessThan(64, count(ItemTypes::TYPES), 'Maska mieści się w BIGINT.');
	}

	public function test_keyword_rules_reject_operators_control_characters_and_length(): void
	{
		self::assertNull(SerpKeywordRules::rejection('strony internetowe warszawa'));
		self::assertSame('search_operator', SerpKeywordRules::rejection('site:example.pl strony'));
		self::assertSame('search_operator', SerpKeywordRules::rejection('buty intitle:sklep'));
		self::assertNull(SerpKeywordRules::rejection('jak zrobić stronę: poradnik'), 'Dwukropek bez operatora jest dozwolony.');
		self::assertSame('too_short', SerpKeywordRules::rejection('a'));
		self::assertSame('too_long', SerpKeywordRules::rejection(str_repeat('a', 201)));
		self::assertSame('invalid_characters', SerpKeywordRules::rejection("fraza\x07"));
	}

	public function test_context_identity_pages_and_frequencies(): void
	{
		$top100 = new SerpContext(2616, 'pl', SerpDevice::Desktop, 100);

		self::assertSame(10, $top100->pages());
		self::assertSame(2, (new SerpContext(2616, 'pl', SerpDevice::Desktop, 20))->pages());
		self::assertSame($top100->key(), (new SerpContext(2616, 'pl', SerpDevice::Desktop, 100))->key());
		self::assertNotSame($top100->key(), (new SerpContext(2616, 'pl', SerpDevice::Mobile, 100))->key(), 'Urządzenie = inny kontekst.');
		self::assertNotSame($top100->key(), (new SerpContext(2616, 'pl', SerpDevice::Desktop, 50))->key(), 'Głębokość = inny kontekst.');
		self::assertSame('windows', $top100->os());
		self::assertSame('android', SerpDevice::Mobile->os());
		self::assertEqualsWithDelta(4.35, SerpFrequency::Weekly->checksPerMonth(), 0.01);
		self::assertEqualsWithDelta(30.44, SerpFrequency::Daily->checksPerMonth(), 0.01);
		self::assertEqualsWithDelta(10.15, SerpFrequency::EveryThreeDays->checksPerMonth(), 0.01);
		self::assertTrue(SerpConfig::validDepth(100));
		self::assertFalse(SerpConfig::validDepth(101));
	}

	public function test_keyword_limit_is_a_configurable_soft_default(): void
	{
		self::assertSame(500, (new SerpConfig())->maxKeywords());
		putenv(SerpConfig::MAX_KEYWORDS . '=5000');

		try {
			self::assertSame(5000, (new SerpConfig())->maxKeywords(), 'Limit nie jest zaszyty — konfiguracja pozwala na tysiące fraz.');
		} finally {
			putenv(SerpConfig::MAX_KEYWORDS);
		}
	}
}
