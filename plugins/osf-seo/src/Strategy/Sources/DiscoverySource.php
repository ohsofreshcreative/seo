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
 * Nowe frazy (STEP 13): zaakceptowane (decyzja użytkownika — poziom decyzji) oraz nowe / do analizy z priorytetem odkrycia
 * od progu. Odrzucone i wykluczone nie wchodzą tym źródłem; dowody pokazują decyzję także wtedy, gdy fraza weszła innym źródłem.
 * „Widoczność GSC” kandydata (D35) jest tylko informacją — nigdy dowodem braku widoczności.
 */
final class DiscoverySource implements CandidateSource
{
	private const CHUNK = 500;

	public function __construct(private readonly Connection $db)
	{
	}

	public function source(): StrategySource
	{
		return StrategySource::Discovery;
	}

	public function fingerprint(SourceScope $scope): string
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS n, MAX(updated_at) AS u, COALESCE(SUM(CRC32(CONCAT_WS(':', id, status, excluded, COALESCE(priority, '')))), 0) AS c
			FROM `{$this->db->table('discovery_candidates')}` WHERE project_id = %d",
			[$scope->projectId],
		) ?? [];
		$refresh = $this->db->fetchValue("SELECT refresh_key FROM `{$this->db->table('discovery_settings')}` WHERE project_id = %d", [$scope->projectId]);

		return implode(':', [$row['n'] ?? 0, $row['u'] ?? '', $row['c'] ?? 0, (string) $refresh]);
	}

	public function signals(SourceScope $scope): SignalBatch
	{
		$signals = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN c.market_keyword_id, c.status, c.priority, LOWER(HEX(m.keyword_key)) AS h, m.keyword, m.search_intent
			FROM `{$this->db->table('discovery_candidates')}` c JOIN `{$this->db->table('market_keywords')}` m ON m.id = c.market_keyword_id
			WHERE c.project_id = %d AND c.status <> 'dismissed' AND c.excluded = 0 AND (c.status = 'accepted' OR c.priority >= %d)
				AND " . MarketKeywordLookup::marketCondition() . ' ORDER BY c.id',
			[$scope->projectId, $scope->config->discoveryMinPriority(), ...MarketKeywordLookup::params($scope->market)],
		) as $row) {
			$accepted = $row['status'] === 'accepted';
			$signals[] = new SourceSignal(
				(string) $row['h'],
				(int) $row['market_keyword_id'],
				(string) $row['keyword'],
				$row['search_intent'],
				StrategySource::Discovery,
				$accepted ? SourceSignal::TIER_DECISION : SourceSignal::TIER_DISCOVERY,
				(float) ($row['priority'] ?? 0),
			);
		}

		return new SignalBatch($signals);
	}

	public function evidence(SourceScope $scope, array $keys, array $collected): array
	{
		$result = [];

		foreach (array_chunk(array_keys($keys), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, market_keyword_id, public_id, status, priority, visibility, gsc_impressions, gsc_position, target_url, excluded, seeds_count
				FROM `{$this->db->table('discovery_candidates')}` WHERE project_id = %d AND market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$scope->projectId, ...$chunk],
			) as $row) {
				$result[(int) $row['market_keyword_id']] = [
					'_facts' => ['candidate_id' => (int) $row['id']],
					'id' => (string) $row['public_id'],
					'status' => (string) $row['status'],
					'priority' => $row['priority'] === null ? null : (int) $row['priority'],
					'visibility_gsc' => (string) $row['visibility'],
					'excluded' => (int) $row['excluded'] === 1,
					'seeds' => (int) $row['seeds_count'],
					'target_url' => $row['target_url'],
				];
			}
		}

		return $result;
	}
}
