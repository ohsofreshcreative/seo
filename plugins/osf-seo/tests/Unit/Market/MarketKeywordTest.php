<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Market;

use OsfSeo\Market\MarketKeyword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarketKeywordTest extends TestCase
{
	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function normalizations(): iterable
	{
		yield 'wielkie litery' => ['Buty Damskie', 'buty damskie'];
		yield 'polskie wielkie litery' => ['ŻÓŁTY ŁOŚ ĆMA ŚWIĘTO', 'żółty łoś ćma święto'];
		yield 'wielokrotne spacje i krawędzie' => ["  strony   internetowe \t", 'strony internetowe'];
		yield 'twarda spacja i nowa linia' => ["agencja\u{00A0}seo\nwarszawa", 'agencja seo warszawa'];
		yield 'znaki zerowej szerokości' => ["pozycjonowanie\u{200B} stron", 'pozycjonowanie stron'];
		yield 'interpunkcja zostaje' => ['C++ kurs', 'c++ kurs'];
		yield 'ampersand i akcent' => ['Café & Bistro', 'café & bistro'];
		yield 'niemiecki' => ['Straße MÜNCHEN', 'straße münchen'];
	}

	#[DataProvider('normalizations')]
	public function test_normalization(string $input, string $expected): void
	{
		self::assertSame($expected, MarketKeyword::normalize($input));
	}

	public function test_unicode_nfc_merges_decomposed_diacritics(): void
	{
		if (! class_exists(\Normalizer::class)) {
			self::markTestSkipped('Rozszerzenie intl niedostępne.');
		}

		// „z” + łączący znak kropki nad literą (U+0307) = „ż”.
		self::assertSame('żółw', MarketKeyword::normalize("z\u{0307}o\u{0301}łw"));
		self::assertSame(MarketKeyword::key('żółw'), MarketKeyword::key("Z\u{0307}O\u{0301}ŁW"));
	}

	public function test_key_ignores_case_and_whitespace_but_keeps_diacritics(): void
	{
		self::assertSame(MarketKeyword::key('Buty'), MarketKeyword::key(' buty '));
		self::assertSame(16, strlen(MarketKeyword::key('buty')));
		self::assertNotSame(MarketKeyword::key('żółw'), MarketKeyword::key('zolw'), 'Polskie znaki to różne zapytania.');
		self::assertNotSame(MarketKeyword::key('kurs c++'), MarketKeyword::key('kurs c'));
	}

	public function test_loose_form_drops_punctuation_and_symbols(): void
	{
		self::assertSame('wp config php', MarketKeyword::loose('wp-config.php?'));
		self::assertSame('sklep internetowy', MarketKeyword::loose('Sklep — internetowy!'));
		self::assertSame('żółw', MarketKeyword::loose('„Żółw”'));
	}
}
