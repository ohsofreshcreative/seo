<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Stabilny odcisk szansy: property GSC + typ + naturalny klucz encji (adres podstrony, fraza albo para adresów).
 *
 * Oparty na tekstach z GSC, nie na wewnętrznych ID słowników — przetrwa ponowny import i przeliczenie.
 * Property jest częścią odcisku: szanse różnych properties nigdy się nie łączą (D18).
 */
final class Fingerprint
{
	private const PREFIX = 'osf-opp';

	public static function page(string $property, OpportunityType $type, string $url): string
	{
		return self::hash($property, $type, 'page:' . UrlKey::normalize($url));
	}

	public static function keyword(string $property, OpportunityType $type, string $keyword): string
	{
		return self::hash($property, $type, 'kw:' . $keyword);
	}

	/**
	 * Para adresów (kolejność bez znaczenia).
	 */
	public static function pair(string $property, OpportunityType $type, string $first, string $second): string
	{
		$urls = [UrlKey::normalize($first), UrlKey::normalize($second)];
		sort($urls, SORT_STRING);

		return self::hash($property, $type, 'pair:' . $urls[0] . "\n" . $urls[1]);
	}

	/** Postać szesnastkowa (32 znaki) — logi, testy, CLI. */
	public static function hex(string $binary): string
	{
		return bin2hex($binary);
	}

	private static function hash(string $property, OpportunityType $type, string $entity): string
	{
		return md5(self::PREFIX . "\n" . $property . "\n" . $type->value . "\n" . $entity, true);
	}
}
