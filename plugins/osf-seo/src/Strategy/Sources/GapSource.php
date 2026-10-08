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
 * Luki fraz (STEP 15): luki z listy (`listed = 1`), aktualne, nieodrzucone, typu brak / słaba / nieznana widoczność, z priorytetem
 * luki od progu. Strategia czyta wyniki przeliczenia Luk SEO (widoczność według hierarchii D46 ze źródłem, najlepszy konkurent —
 * pozycja z bazy Labs, strona docelowa, grupa, luka treści) zamiast ponownie liczyć dane Labs.
 */
final class GapSource implements CandidateSource
{
	public const GAP_TYPES = ['missing', 'weak', 'unknown'];

	private const CHUNK = 500;

	public function __construct(private readonly Connection $db)
	{
	}

	public function source(): StrategySource
	{
		return StrategySource::Gap;
	}

	public function fingerprint(SourceScope $scope): string
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS(':', id, status, active, listed))), 0) AS c
			FROM `{$this->db->table('gap_keywords')}` WHERE project_id = %d",
			[$scope->projectId],
		) ?? [];
		$settings = $this->db->fetchRow(
			"SELECT LOWER(HEX(data_key)) AS k, recalculated_at FROM `{$this->db->table('gap_settings')}` WHERE project_id = %d",
			[$scope->projectId],
		) ?? [];

		return implode(':', [$row['n'] ?? 0, $row['c'] ?? 0, $settings['k'] ?? '', $settings['recalculated_at'] ?? '']);
	}

	public function signals(SourceScope $scope): SignalBatch
	{
		$signals = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN g.market_keyword_id, g.priority, LOWER(HEX(m.keyword_key)) AS h, m.keyword, m.search_intent
			FROM `{$this->db->table('gap_keywords')}` g JOIN `{$this->db->table('market_keywords')}` m ON m.id = g.market_keyword_id
			WHERE g.project_id = %d AND g.listed = 1 AND g.active = 1 AND g.status <> 'dismissed'
				AND g.gap_type IN (" . Connection::placeholders(self::GAP_TYPES) . ') AND g.priority >= %d
				AND ' . MarketKeywordLookup::marketCondition() . ' ORDER BY g.id',
			[$scope->projectId, ...self::GAP_TYPES, $scope->config->gapMinPriority(), ...MarketKeywordLookup::params($scope->market)],
		) as $row) {
			$signals[] = new SourceSignal((string) $row['h'], (int) $row['market_keyword_id'], (string) $row['keyword'], $row['search_intent'], StrategySource::Gap, SourceSignal::TIER_GAP, (float) $row['priority']);
		}

		return new SignalBatch($signals);
	}

	public function evidence(SourceScope $scope, array $keys, array $collected): array
	{
		$result = [];

		foreach (array_chunk(array_keys($keys), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN g.id, g.market_keyword_id, g.public_id, g.status, g.active, g.listed, g.filter_reason, g.gap_type, g.visibility,
					g.visibility_source, g.sporadic, g.project_position, g.project_labs_rank, g.serp_rank, g.competitors_count, g.competitors_top10,
					g.best_competitor_rank, g.cluster_id, g.content_gap, g.target_source, g.priority, g.evidence_on,
					c.public_id AS competitor_id, c.name AS competitor_name, c.domain AS competitor_domain, bu.url AS best_url, tu.url AS target_url
				FROM `{$this->db->table('gap_keywords')}` g
				LEFT JOIN `{$this->db->table('serp_competitors')}` c ON c.id = g.best_competitor_id
				LEFT JOIN `{$this->db->table('serp_urls')}` bu ON bu.id = g.best_url_id
				LEFT JOIN `{$this->db->table('serp_urls')}` tu ON tu.id = g.target_url_id
				WHERE g.project_id = %d AND g.market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$scope->projectId, ...$chunk],
			) as $row) {
				$result[(int) $row['market_keyword_id']] = [
					'_facts' => ['gap_keyword_id' => (int) $row['id'], 'cluster_id' => $row['cluster_id'] === null ? null : (int) $row['cluster_id']],
					'id' => (string) $row['public_id'],
					'status' => (string) $row['status'],
					'active' => (int) $row['active'] === 1,
					'listed' => (int) $row['listed'] === 1,
					'filter_reason' => $row['filter_reason'],
					'gap_type' => $row['gap_type'],
					'visibility' => $row['visibility'],
					'visibility_source' => $row['visibility_source'],
					'sporadic' => (int) $row['sporadic'] === 1,
					'project_position' => $row['project_position'] === null ? null : (float) $row['project_position'],
					'project_labs_rank' => $row['project_labs_rank'] === null ? null : (int) $row['project_labs_rank'],
					'serp_rank' => $row['serp_rank'] === null ? null : (int) $row['serp_rank'],
					'competitors' => (int) $row['competitors_count'],
					'competitors_top10' => (int) $row['competitors_top10'],
					'best_competitor' => $row['competitor_id'] === null ? null : [
						'id' => (string) $row['competitor_id'],
						'name' => (string) $row['competitor_name'],
						'domain' => (string) $row['competitor_domain'],
						'rank_labs' => $row['best_competitor_rank'] === null ? null : (int) $row['best_competitor_rank'],
						'url' => $row['best_url'],
					],
					'target' => $row['target_url'] === null ? null : ['url' => $row['target_url'], 'source' => $row['target_source']],
					'content_gap' => $row['content_gap'],
					'priority' => $row['priority'] === null ? null : (int) $row['priority'],
					'evidence_on' => $row['evidence_on'],
				];
			}
		}

		return $result;
	}
}
