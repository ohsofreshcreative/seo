<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Gap;

use OsfSeo\Gap\BrandMatcher;
use OsfSeo\Gap\TextFold;
use PHPUnit\Framework\TestCase;

final class BrandMatcherTest extends TestCase
{
	public function test_domain_label_skips_suffixes_and_subdomains(): void
	{
		self::assertSame('wisepeople', BrandMatcher::domainLabel('wisepeople.pl'));
		self::assertSame('wise-people', BrandMatcher::domainLabel('blog.wise-people.com.pl'));
		self::assertSame('example', BrandMatcher::domainLabel('example.co.uk'));
		self::assertNull(BrandMatcher::domainLabel('localhost'));
	}

	public function test_multiword_brand_written_as_one_word_matches_without_intent(): void
	{
		$brand = BrandMatcher::build(['wise-people.pl'], ['Wise People Sp. z o.o.']);

		self::assertSame('wise people', $brand->match('wisepeople opinie', 'commercial'));
		self::assertSame('wise people', $brand->match('wise people kontakt', 'navigational'));
		self::assertNull($brand->match('wise people kontakt', 'commercial'), 'Bez intencji nawigacyjnej wyrazy osobno nie wystarczą.');
	}

	public function test_navigational_intent_required_for_single_word_and_exact_match_domains(): void
	{
		$brand = BrandMatcher::build(['wisepeople.pl'], ['WisePeople']);

		self::assertSame('wisepeople', $brand->match('wisepeople', 'navigational'));
		self::assertNull($brand->match('wisepeople cennik', 'commercial'));

		$emd = BrandMatcher::build(['strony-internetowe.pl'], []);

		self::assertNull($emd->match('strony internetowe warszawa', 'commercial'), 'Domena-fraza nie ukrywa fraz branżowych.');
		self::assertSame('strony internetowe', $emd->match('stronyinternetowe', 'commercial'), 'Nazwa domeny wpisana łącznie = zapytanie o markę.');
		self::assertSame('strony internetowe', $emd->match('strony internetowe pl', 'navigational'));
	}

	public function test_user_terms_always_match_with_wildcards(): void
	{
		$brand = BrandMatcher::build([], [], "konkurent*\nkk agency");

		self::assertSame('konkurent*', $brand->match('konkurentów opinie', 'informational'));
		self::assertSame('kk agency', $brand->match('kk agency kraków', null));
		self::assertNull($brand->match('agencja kk', null));
		self::assertFalse($brand->isEmpty());
	}

	public function test_text_fold_tokens_and_slugs(): void
	{
		self::assertSame('strony internetowe lodz', TextFold::fold('Strony  Internetowe Łódź!'));
		self::assertSame('strony warszawa www', TextFold::tokenKey('www warszawa strony'));
		self::assertSame(['strony', 'internetowe', 'warszawa'], TextFold::pathTokens('https://example.pl/strony-internetowe/warszawa.html?x=1'));
		self::assertTrue(TextFold::isRoot('https://example.pl/'));
		self::assertTrue(TextFold::isRoot('https://example.pl'));
		self::assertFalse(TextFold::isRoot('https://example.pl/oferta/'));
		self::assertSame(1, TextFold::pageType('https://example.pl/oferta/'));
		self::assertSame(2, TextFold::pageType('https://example.pl/blog/wpis/'));
		self::assertSame(1.0, TextFold::slugCoverage(['strony', 'internetowe', 'łódź'], 'https://example.pl/strony-internetowe-lodz/'), 'Polskie znaki pasują do adresu bez nich.');
		self::assertEqualsWithDelta(0.667, TextFold::slugCoverage(TextFold::tokens('strony internetowe łódź'), 'https://example.pl/strony-internetowe/'), 0.001);
	}
}
