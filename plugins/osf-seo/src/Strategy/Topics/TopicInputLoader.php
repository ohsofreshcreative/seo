<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use DateTimeImmutable;
use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;

/**
 * Wejście rdzenia Strategii: aktywni kandydaci projektu na rynku projektu z metrykami rynkowymi, `core_key`, przynależnością do tematu,
 * przypięciem i zmaterializowanymi dowodami (bez ponownej agregacji danych modułów).
 */
final class TopicInputLoader
{
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * @return list<KeywordSignals>
	 */
	public function load(int $projectId, Market $market, DateTimeImmutable $now): array
	{
		return array_map(static fn (array $row): KeywordSignals => KeywordSignals::fromRow($row, $now), $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN s.id, s.public_id, s.market_keyword_id, s.manual, s.sources, s.tier, s.evidence, s.topic_id, s.pinned_topic_id,
				LOWER(HEX(s.facts_hash)) AS facts_hash, m.keyword, m.search_volume, m.keyword_difficulty, m.cpc, m.search_intent, LOWER(HEX(m.core_key)) AS core_key
			FROM `{$this->db->table('strategy_keywords')}` s JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			WHERE s.project_id = %d AND s.active = 1 AND m.provider = %s AND m.location_code = %d AND m.language_code = %s
			ORDER BY s.id",
			[$projectId, $market->provider, $market->locationCode, $market->languageCode],
		));
	}
}
