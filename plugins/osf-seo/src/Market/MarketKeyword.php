<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use Normalizer;

/**
 * Normalizacja frazy na potrzeby danych rynkowych (osobna od tożsamości frazy GSC).
 *
 * Fraza GSC jest identyfikowana dokładnymi bajtami (`keywords.keyword_hash`: `Buty` ≠ `buty`) i to się nie zmienia.
 * Dostawcy danych rynkowych traktują frazy bez rozróżniania wielkości liter (DataForSEO zamienia je na małe litery),
 * więc dane rynkowe mają własny klucz:
 *
 * 1. Unicode NFC (gdy dostępne `intl`) — `z` + łączący znak kropki = `ż`,
 * 2. małe litery (UTF-8, także polskie znaki: `Ł` → `ł`),
 * 3. białe znaki (spacje, tabulatory, twarda spacja, znaki zerowej szerokości) → jedna spacja, bez spacji na końcach.
 *
 * Polskie znaki diakrytyczne i interpunkcja zostają: `żółw` ≠ `zolw` (to różne zapytania), a znaki niedozwolone
 * u dostawcy wyklucza jego reguła fraz — nie normalizacja. Klucz = MD5 (binarnie) postaci znormalizowanej.
 */
final class MarketKeyword
{
	public static function normalize(string $keyword): string
	{
		if (class_exists(Normalizer::class)) {
			$normalized = Normalizer::normalize($keyword, Normalizer::FORM_C);
			$keyword = is_string($normalized) ? $normalized : $keyword;
		}

		$keyword = mb_strtolower($keyword, 'UTF-8');
		$keyword = (string) preg_replace('/[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', ' ', $keyword);

		return trim($keyword);
	}

	/** Klucz rynkowy (16 bajtów) — `market_keywords.keyword_key` i `keywords.market_key`. */
	public static function key(string $keyword): string
	{
		return md5(self::normalize($keyword), true);
	}

	/**
	 * Luźna postać do dopasowania odpowiedzi dostawcy, który zwrócił frazę zmienioną (np. bez kropki lub myślnika):
	 * postać znormalizowana bez interpunkcji i symboli. Używana tylko pomocniczo, gdy dokładny klucz nie pasuje.
	 */
	public static function loose(string $keyword): string
	{
		$keyword = (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', self::normalize($keyword));

		return trim((string) preg_replace('/ {2,}/', ' ', $keyword));
	}
}
