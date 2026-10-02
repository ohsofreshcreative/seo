<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Reguły fraz monitorowanych pozycji (postać znormalizowana). Odrzucamy frazy z operatorami wyszukiwania
 * (`site:`, `intitle:` itd.): to nie są prawdziwe zapytania użytkowników, a DataForSEO nalicza za nie 5× wyższą cenę.
 */
final class SerpKeywordRules
{
	public const MAX_LENGTH = 200;

	private const OPERATORS = '/(?:^|\s)(?:allinanchor|allintext|allintitle|allinurl|cache|define|filetype|id|inanchor|info|intext|intitle|inurl|link|site|related|source|ext|before|after):/u';

	/** Powód odrzucenia albo null, gdy fraza jest poprawna. */
	public static function rejection(string $keyword): ?string
	{
		if ($keyword === '' || mb_strlen($keyword) < 2) {
			return 'too_short';
		}

		if (mb_strlen($keyword) > self::MAX_LENGTH) {
			return 'too_long';
		}

		if (preg_match('/[\x00-\x1F\x7F]/u', $keyword) === 1 || preg_match('//u', $keyword) !== 1) {
			return 'invalid_characters';
		}

		if (preg_match(self::OPERATORS, $keyword) === 1) {
			return 'search_operator';
		}

		return null;
	}

	public static function accepts(string $keyword): bool
	{
		return self::rejection($keyword) === null;
	}

	public static function label(string $reason): string
	{
		return match ($reason) {
			'too_short' => 'za krótka',
			'too_long' => 'za długa (maks. ' . self::MAX_LENGTH . ' znaków)',
			'invalid_characters' => 'niedozwolone znaki',
			'search_operator' => 'zawiera operator wyszukiwania (np. site:)',
			'limit' => 'ponad limit monitorowanych fraz',
			'duplicate' => 'już monitorowana',
			'not_found' => 'nie znaleziono w projekcie',
			default => $reason,
		};
	}
}
