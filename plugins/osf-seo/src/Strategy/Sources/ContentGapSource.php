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
 * Luki treści (STEP 15): frazy (z listy, aktualne, nieodrzucone) aktywnych, nieodrzuconych grup z heurystyką „Potencjalna luka
 * treści” albo „Istniejąca strona — do wzmocnienia” i pewnością co najmniej średnią. Luka treści to heurystyka do sprawdzenia —
 * nie dowód braku strony (D57).
 */
final class ContentGapSource implements CandidateSource
{
	public const CONTENT_GAPS = ['new_page', 'improve'];

	public const CONFIDENCES = ['medium', 'high'];

	private const CHUNK = 500;

	public function __construct(private readonly Connection $db)
	{
	}

	public function source(): StrategySource
	{
		return StrategySource::ContentGap;
	}

	public function fingerprint(SourceScope $scope): string
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS(':', id, status, active, COALESCE(content_gap, ''), COALESCE(confidence, ''), COALESCE(priority, '')))), 0) AS c
			FROM `{$this->db->table('gap_clusters')}` WHERE project_id = %d",
			[$scope->projectId],
		) ?? [];

		return implode(':', [$row['n'] ?? 0, $row['c'] ?? 0]);
	}

	public function signals(SourceScope $scope): SignalBatch
	{
		$signals = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN g.market_keyword_id, cl.priority, LOWER(HEX(m.keyword_key)) AS h, m.keyword, m.search_intent
			FROM `{$this->db->table('gap_clusters')}` cl
			JOIN `{$this->db->table('gap_keywords')}` g ON g.project_id = cl.project_id AND g.cluster_id = cl.id
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = g.market_keyword_id
			WHERE cl.project_id = %d AND cl.active = 1 AND cl.status <> 'dismissed'
				AND cl.content_gap IN (" . Connection::placeholders(self::CONTENT_GAPS) . ') AND cl.confidence IN (' . Connection::placeholders(self::CONFIDENCES) . ")
				AND g.listed = 1 AND g.active = 1 AND g.status <> 'dismissed' AND " . MarketKeywordLookup::marketCondition() . ' ORDER BY g.id',
			[$scope->projectId, ...self::CONTENT_GAPS, ...self::CONFIDENCES, ...MarketKeywordLookup::params($scope->market)],
		) as $row) {
			$signals[] = new SourceSignal((string) $row['h'], (int) $row['market_keyword_id'], (string) $row['keyword'], $row['search_intent'], StrategySource::ContentGap, SourceSignal::TIER_CONTENT_GAP, (float) ($row['priority'] ?? 0));
		}

		return new SignalBatch($signals);
	}

	public function evidence(SourceScope $scope, array $keys, array $collected): array
	{
		$result = [];

		foreach (array_chunk(array_keys($keys), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN g.market_keyword_id, cl.id, cl.public_id, cl.label, cl.active, cl.content_gap, cl.content_reason, cl.confidence, cl.status,
					cl.priority, cl.keywords_count
				FROM `{$this->db->table('gap_keywords')}` g JOIN `{$this->db->table('gap_clusters')}` cl ON cl.id = g.cluster_id AND cl.project_id = g.project_id
				WHERE g.project_id = %d AND g.market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$scope->projectId, ...$chunk],
			) as $row) {
				$result[(int) $row['market_keyword_id']] = [
					'_facts' => ['cluster_id' => (int) $row['id']],
					'id' => (string) $row['public_id'],
					'label' => (string) $row['label'],
					'active' => (int) $row['active'] === 1,
					'content_gap' => $row['content_gap'],
					'reason' => $row['content_reason'],
					'confidence' => $row['confidence'],
					'status' => (string) $row['status'],
					'priority' => $row['priority'] === null ? null : (int) $row['priority'],
					'keywords' => (int) $row['keywords_count'],
				];
			}
		}

		return $result;
	}
}
