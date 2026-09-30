<?php

declare(strict_types=1);

namespace OsfSeo\Support;

use DateTimeImmutable;

/**
 * ULID (26 znaków Crockford base32): 48 bitów czasu w ms + 80 bitów losowych (random_bytes).
 * Używany jako publiczny identyfikator w URL-ach — utrudnia enumerację, ale NIE zastępuje autoryzacji.
 */
final class Ulid
{
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	private const PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

	public static function generate(?DateTimeImmutable $time = null): string
	{
		$milliseconds = (int) ($time ?? new DateTimeImmutable())->format('Uv');

		$timeBits = str_pad(decbin($milliseconds), 50, '0', STR_PAD_LEFT);
		$randomBits = '';

		foreach (str_split(random_bytes(10)) as $byte) {
			$randomBits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
		}

		return self::encode($timeBits) . self::encode($randomBits);
	}

	public static function isValid(string $value): bool
	{
		return preg_match(self::PATTERN, $value) === 1;
	}

	/**
	 * Wielkie litery + walidacja; null, gdy wartość nie jest poprawnym ULID-em.
	 */
	public static function normalize(string $value): ?string
	{
		$value = strtoupper(trim($value));

		return self::isValid($value) ? $value : null;
	}

	private static function encode(string $bits): string
	{
		$result = '';

		foreach (str_split($bits, 5) as $chunk) {
			$result .= self::ALPHABET[bindec($chunk)];
		}

		return $result;
	}
}
