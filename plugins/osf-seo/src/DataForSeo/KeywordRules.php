<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

/**
 * Reguły pola `keywords` DataForSEO (Google Ads): maks. 80 znaków i 10 słów; niedozwolone symbole
 * `, ! @ % ^ ( ) = { } ; ~ ` < > ? \ | ―`, znaki 4-bajtowe UTF-8 (np. emoji) i operatory wyszukiwania.
 * Jedna niedozwolona fraza może odrzucić całe zadanie, więc filtrujemy przed wysłaniem — także dla Labs
 * (te same frazy, ostrożniej). Dodatkowo odrzucamy `*`, `"` i nawiasy kwadratowe (składnia dopasowań Google Ads).
 */
final class KeywordRules
{
	public const MAX_LENGTH = 80;

	public const MAX_WORDS = 10;

	private const INVALID_SYMBOLS = '/[,!@%^()={};~`<>?\\\\|\x{2015}*"\[\]]/u';

	private const FOUR_BYTE = '/[\x{10000}-\x{10FFFF}]/u';

	private const CONTROL = '/\p{C}/u';

	private const OPERATORS = '/(?:^| )(?:site|inurl|intitle|intext|inanchor|allinurl|allintitle|allintext|allinanchor|filetype|link|related|cache|info|define|id):/u';

	public static function accepts(string $normalizedKeyword): bool
	{
		if ($normalizedKeyword === '' || ! mb_check_encoding($normalizedKeyword, 'UTF-8')) {
			return false;
		}

		if (mb_strlen($normalizedKeyword, 'UTF-8') > self::MAX_LENGTH || count(explode(' ', $normalizedKeyword)) > self::MAX_WORDS) {
			return false;
		}

		foreach ([self::INVALID_SYMBOLS, self::FOUR_BYTE, self::CONTROL, self::OPERATORS] as $pattern) {
			if (preg_match($pattern, $normalizedKeyword) !== 0) {
				return false;
			}
		}

		return true;
	}
}
