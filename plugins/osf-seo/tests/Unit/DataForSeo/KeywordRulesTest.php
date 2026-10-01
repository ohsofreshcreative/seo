<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\DataForSeo;

use OsfSeo\DataForSeo\KeywordRules;
use OsfSeo\Market\MarketKeyword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeywordRulesTest extends TestCase
{
	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function accepted(): iterable
	{
		yield 'zwykła fraza' => ['projektowanie stron internetowych'];
		yield 'polskie znaki' => ['żółte buty łódź'];
		yield 'plus i kropka' => ['kurs c++ online.pl'];
		yield 'ampersand' => ['b&b kraków'];
		yield 'cyfry i myślnik' => ['iphone 15 pro-max'];
		yield 'dokładnie 80 znaków' => [str_repeat('a', 80)];
		yield 'dokładnie 10 słów' => ['a b c d e f g h i j'];
	}

	#[DataProvider('accepted')]
	public function test_accepts_valid_keywords(string $keyword): void
	{
		self::assertTrue(KeywordRules::accepts(MarketKeyword::normalize($keyword)));
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function rejected(): iterable
	{
		yield 'pusta' => [''];
		yield '81 znaków' => [str_repeat('a', 81)];
		yield '81 znaków z polskimi literami' => [str_repeat('ż', 81)];
		yield '11 słów' => ['a b c d e f g h i j k'];
		yield 'przecinek' => ['buty, damskie'];
		yield 'pytajnik' => ['jak zrobić stronę?'];
		yield 'wykrzyknik' => ['promocja!'];
		yield 'nawiasy' => ['buty (damskie)'];
		yield 'cudzysłów' => ['"buty damskie"'];
		yield 'nawias kwadratowy' => ['[buty damskie]'];
		yield 'gwiazdka' => ['buty*'];
		yield 'procent' => ['rabat 50%'];
		yield 'małpa' => ['kontakt@example.pl'];
		yield 'pionowa kreska' => ['a | b'];
		yield 'pozioma kreska U+2015' => ["a \u{2015} b"];
		yield 'emoji (4 bajty)' => ["buty \u{1F600}"];
		yield 'operator site:' => ['site:example.pl buty'];
		yield 'znak sterujący' => ["buty\u{0007}"];
		yield 'nieprawidłowy UTF-8' => ["buty \xC3\x28"];
	}

	#[DataProvider('rejected')]
	public function test_rejects_keywords_that_could_fail_a_batch(string $keyword): void
	{
		self::assertFalse(KeywordRules::accepts($keyword));
	}
}
