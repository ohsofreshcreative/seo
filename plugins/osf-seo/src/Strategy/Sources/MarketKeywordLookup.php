<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Sources;

use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;

/**
 * Odczyt fraz rynkowych (`market_keywords`) po kluczach — tylko rynek projektu. Bez zapisu.
 */
final class MarketKeywordLookup
{
	private const CHUNK = 500;

	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * @param list<string> $hexKeys
	 * @return array<string, array{id: int, keyword: string, intent: ?string}> klucz hex → fraza rynkowa
	 */
	public function byKeys(Market $market, array $hexKeys): array
	{
		$result = [];

		foreach (array_chunk(array_values(array_unique($hexKeys)), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, LOWER(HEX(keyword_key)) AS h, keyword, search_intent FROM `{$this->db->table('market_keywords')}`
				WHERE provider = %s AND location_code = %d AND language_code = %s AND keyword_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				[$market->provider, $market->locationCode, $market->languageCode, ...$chunk],
			) as $row) {
				$result[(string) $row['h']] = ['id' => (int) $row['id'], 'keyword' => (string) $row['keyword'], 'intent' => $row['search_intent']];
			}
		}

		return $result;
	}

	/** Warunek SQL „fraza rynkowa `m` należy do rynku projektu” (parametry: `params()`). */
	public static function marketCondition(string $alias = 'm'): string
	{
		return "{$alias}.provider = %s AND {$alias}.location_code = %d AND {$alias}.language_code = %s";
	}

	/**
	 * @return list<int|string>
	 */
	public static function params(Market $market): array
	{
		return [$market->provider, $market->locationCode, $market->languageCode];
	}
}
