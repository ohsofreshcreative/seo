<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use InvalidArgumentException;
use OsfSeo\Projects\DomainNormalizer;

/**
 * Rodzina domeny: domena i jej subdomeny (`example.pl`, `www.example.pl`, `blog.example.pl`).
 *
 * Hosty z SERP i domeny projektu/konkurentów normalizujemy tak samo jak domenę projektu (`DomainNormalizer`: małe litery,
 * bez schematu, portu, `www.` i kropki na końcu, IDN → punycode). Dopasowanie wyłącznie po granicy etykiet:
 * host = domena albo host kończy się na `.` + domena — nigdy podciągiem (`notexample.pl` i `example.pl.evil.com`
 * nie należą do `example.pl`). Bez listy sufiksów publicznych: domena projektu wyznacza rodzinę wprost.
 */
final class DomainFamily
{
	/** Host z wyniku SERP albo wpisana domena → postać znormalizowana; null = nieprawidłowa. */
	public static function normalize(string $value): ?string
	{
		if (trim($value) === '') {
			return null;
		}

		try {
			return DomainNormalizer::normalize($value);
		} catch (InvalidArgumentException) {
			return null;
		}
	}

	/** Host z adresu URL wyniku (gdy dostawca nie podał domeny). */
	public static function fromUrl(string $url): ?string
	{
		$host = parse_url($url, PHP_URL_HOST);

		return is_string($host) ? self::normalize($host) : null;
	}

	public static function matches(string $host, string $family): bool
	{
		return $host === $family || str_ends_with($host, '.' . $family);
	}

	/** Dwie rodziny się pokrywają (jedna jest subdomeną drugiej albo są równe). */
	public static function overlaps(string $first, string $second): bool
	{
		return self::matches($first, $second) || self::matches($second, $first);
	}

	/**
	 * Etykiety w odwrotnej kolejności (`blog.example.pl` → `pl.example.blog`) — rodzina domeny to wtedy prefiks
	 * w indeksie (`= 'pl.example'` albo `LIKE 'pl.example.%'`), bez skanowania słownika.
	 */
	public static function reverse(string $host): string
	{
		return implode('.', array_reverse(explode('.', $host)));
	}
}
