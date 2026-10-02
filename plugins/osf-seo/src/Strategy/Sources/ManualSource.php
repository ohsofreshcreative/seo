<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Sources;

use OsfSeo\Database\Connection;
use OsfSeo\Strategy\CandidateSource;
use OsfSeo\Strategy\SignalBatch;
use OsfSeo\Strategy\SourceScope;
use OsfSeo\Strategy\SourceSignal;
use OsfSeo\Strategy\StrategySource;

/**
 * Wpisy ręczne Strategii (`strategy_keywords.manual = 1`) — jawna decyzja użytkownika: najwyższy poziom, bez filtrów marki
 * i wykluczeń. Odcisk = rewizja mutacji ręcznych projektu.
 */
final class ManualSource implements CandidateSource
{
	private const CHUNK = 500;

	public function __construct(private readonly Connection $db)
	{
	}

	public function source(): StrategySource
	{
		return StrategySource::Manual;
	}

	public function fingerprint(SourceScope $scope): string
	{
		return (string) ($this->db->fetchValue("SELECT revision FROM `{$this->db->table('strategy_settings')}` WHERE project_id = %d", [$scope->projectId]) ?? '0');
	}

	public function signals(SourceScope $scope): SignalBatch
	{
		$signals = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN s.market_keyword_id, LOWER(HEX(m.keyword_key)) AS h, m.keyword, m.search_intent
			FROM `{$this->db->table('strategy_keywords')}` s JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			WHERE s.project_id = %d AND s.manual = 1 AND " . MarketKeywordLookup::marketCondition() . ' ORDER BY s.id',
			[$scope->projectId, ...MarketKeywordLookup::params($scope->market)],
		) as $row) {
			$signals[] = new SourceSignal((string) $row['h'], (int) $row['market_keyword_id'], (string) $row['keyword'], $row['search_intent'], StrategySource::Manual, SourceSignal::TIER_MANUAL, 0.0);
		}

		return new SignalBatch($signals);
	}

	public function evidence(SourceScope $scope, array $keys, array $collected): array
	{
		$result = [];

		foreach (array_chunk(array_keys($keys), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT market_keyword_id, manual_added_by, manual_added_at FROM `{$this->db->table('strategy_keywords')}`
				WHERE project_id = %d AND manual = 1 AND market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$scope->projectId, ...$chunk],
			) as $row) {
				$result[(int) $row['market_keyword_id']] = [
					'added_at' => $row['manual_added_at'],
					'added_by' => $row['manual_added_by'] === null ? null : (int) $row['manual_added_by'],
				];
			}
		}

		return $result;
	}
}
