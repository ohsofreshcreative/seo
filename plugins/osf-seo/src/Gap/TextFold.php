<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use Normalizer;
use OsfSeo\Market\MarketKeyword;

/**
 * Deterministyczne składanie tekstu do porównań heurystyk (marka, slug adresu, grupowanie po zbiorze wyrazów):
 * małe litery, bez znaków diakrytycznych (adresy URL zwykle ich nie mają), separatory → spacja. Nie zmienia tożsamości
 * frazy (klucz rynkowy) i nie jest stemmingiem — „strona” ≠ „strony”.
 */
final class TextFold
{
	private const SPECIAL = ['ł' => 'l', 'Ł' => 'l', 'ø' => 'o', 'đ' => 'd', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe'];

	public static function fold(string $text): string
	{
		$text = strtr(MarketKeyword::normalize($text), self::SPECIAL);

		if (class_exists(Normalizer::class)) {
			$decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
			$text = is_string($decomposed) ? $decomposed : $text;
		}

		$text = (string) preg_replace('/\p{Mn}+/u', '', $text);
		$text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text, 'UTF-8'));

		return trim($text);
	}

	/**
	 * @return list<string>
	 */
	public static function tokens(string $text): array
	{
		$folded = self::fold($text);

		return $folded === '' ? [] : array_values(array_filter(explode(' ', $folded), static fn (string $token): bool => $token !== ''));
	}

	/** Klucz zbioru wyrazów (bez kolejności) — „warszawa strony www” = „strony www warszawa”. */
	public static function tokenKey(string $text): string
	{
		$tokens = array_values(array_unique(self::tokens($text)));
		sort($tokens, SORT_STRING);

		return implode(' ', $tokens);
	}

	/**
	 * Wyrazy ścieżki adresu (bez domeny, parametrów i rozszerzenia pliku).
	 *
	 * @return list<string>
	 */
	public static function pathTokens(string $url): array
	{
		$path = parse_url($url, PHP_URL_PATH);
		$path = is_string($path) ? rawurldecode($path) : '';
		$path = (string) preg_replace('/\.(html?|php|aspx?)$/i', '', $path);

		return self::tokens(str_replace(['/', '-', '_', '.'], ' ', $path));
	}

	/** Strona główna (ścieżka pusta albo „/”). */
	public static function isRoot(string $url): bool
	{
		$path = parse_url($url, PHP_URL_PATH);

		return ! is_string($path) || trim($path, '/') === '';
	}

	/** Typ strony: 0 — strona główna, 1 — podstrona 1. poziomu, 2 — podstrona głębsza. */
	public static function pageType(string $url): int
	{
		$path = parse_url($url, PHP_URL_PATH);
		$segments = is_string($path) ? array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '')) : [];

		return match (true) {
			$segments === [] => 0,
			count($segments) === 1 => 1,
			default => 2,
		};
	}

	/**
	 * Udział wyrazów frazy obecnych w ścieżce adresu (0–1) — „dedykowana podstrona”.
	 *
	 * @param list<string> $keywordTokens
	 */
	public static function slugCoverage(array $keywordTokens, string $url): float
	{
		$keywordTokens = array_values(array_unique(array_merge([], ...array_map(static fn (string $token): array => self::tokens($token), $keywordTokens))));

		if ($keywordTokens === []) {
			return 0.0;
		}

		$path = array_flip(self::pathTokens($url));
		$hits = count(array_filter($keywordTokens, static fn (string $token): bool => isset($path[$token])));

		return $hits / count($keywordTokens);
	}
}
