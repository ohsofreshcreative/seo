<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Context;

/**
 * Oczyszczanie tekstów w kontekście i odpowiedzi AI: poprawny UTF-8, bez znaków sterujących, sterowania kierunkiem tekstu
 * i znaków zerowej szerokości (ukrywanie treści przed człowiekiem), zwinięte białe znaki, limit długości z jawnym „…”.
 * Nie zmienia znaczenia danych i nie „wykrywa” prompt injection — dane zewnętrzne pozostają niezaufane (D90).
 */
final class TextSanitizer
{
	private const INVISIBLE = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFB}]/u';

	/** Jedna linia (frazy, tytuły, adresy, etykiety). */
	public static function line(?string $value, int $max, ?bool &$truncated = null): ?string
	{
		$truncated = false;

		if ($value === null) {
			return null;
		}

		$value = self::scrub($value);
		$value = trim((string) preg_replace('/\s+/u', ' ', $value));

		return self::cut($value, $max, $truncated);
	}

	/** Tekst wielowierszowy (odpowiedź modelu): akapity zachowane, pozostałe białe znaki zwinięte. */
	public static function text(string $value, int $max, ?bool &$truncated = null): string
	{
		$value = str_replace(["\r\n", "\r"], "\n", self::scrub($value));
		$value = (string) preg_replace('/[^\S\n]+/u', ' ', $value);
		$value = trim((string) preg_replace('/\n{3,}/', "\n\n", $value));

		return (string) self::cut($value, $max, $truncated);
	}

	private static function scrub(string $value): string
	{
		$value = mb_scrub($value, 'UTF-8');
		$value = str_replace(["\u{2028}", "\u{2029}", "\t"], ' ', $value);

		return (string) preg_replace(self::INVISIBLE, '', $value);
	}

	private static function cut(string $value, int $max, ?bool &$truncated): string
	{
		$truncated = mb_strlen($value, 'UTF-8') > $max;

		return $truncated ? rtrim(mb_substr($value, 0, max(1, $max - 1), 'UTF-8')) . '…' : $value;
	}
}
