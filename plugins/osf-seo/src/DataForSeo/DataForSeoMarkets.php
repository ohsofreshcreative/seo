<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

use OsfSeo\Market\Market;

/**
 * Rynki obsługiwane przez integrację. `location_code` to identyfikatory lokalizacji Google Ads (geotargets)
 * używane przez DataForSEO dla krajów (Polska 2616, Niemcy 2276, Wielka Brytania 2826, USA 2840);
 * `language_code` — kody języków DataForSEO. Aktualną listę potwierdza bezpłatny endpoint
 * `dataforseo_labs/locations_and_languages` (`wp osf-seo dataforseo:locations`).
 *
 * Nowy rynek = nowy wiersz tutaj (bez zmian schematu): klucz danych to dostawca + lokalizacja + język.
 */
final class DataForSeoMarkets
{
	/** @var list<array{0: string, 1: string, 2: int, 3: string, 4: string, 5: string}> kraj, język, location_code, language_code, etykiety */
	private const MARKETS = [
		['pl', 'pl', 2616, 'pl', 'Polska', 'polski'],
		['de', 'de', 2276, 'de', 'Niemcy', 'niemiecki'],
		['gb', 'en', 2826, 'en', 'Wielka Brytania', 'angielski'],
		['us', 'en', 2840, 'en', 'Stany Zjednoczone', 'angielski'],
	];

	/** Kody krajów używane zamiennie z ISO 3166-1 (np. „uk” zamiast „gb”). */
	private const COUNTRY_ALIASES = ['uk' => 'gb'];

	public static function resolve(string $country, string $language): ?Market
	{
		$country = strtolower(trim($country));
		$country = self::COUNTRY_ALIASES[$country] ?? $country;
		// Język projektu bywa zapisany z regionem (pl-pl) — liczy się kod języka.
		$language = substr(strtolower(trim($language)), 0, 2);

		foreach (self::all() as $market) {
			if ($market->country === $country && $market->language === $language) {
				return $market;
			}
		}

		return null;
	}

	/**
	 * @return list<Market>
	 */
	public static function all(): array
	{
		return array_map(
			static fn (array $row): Market => new Market(DataForSeoProvider::NAME, $row[0], $row[1], $row[2], $row[3], $row[4], $row[5]),
			self::MARKETS,
		);
	}
}
