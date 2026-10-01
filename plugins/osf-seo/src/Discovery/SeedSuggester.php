<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Opportunities\OpportunityConfig;
use OsfSeo\Opportunities\OpportunityStatus;
use OsfSeo\Support\DateRange;

/**
 * Podpowiedzi seedów z danych, które Wibble już ma (bez API, bez AI) — pokazywane przed wysłaniem czegokolwiek:
 *
 * - GSC: frazy z największą liczbą kliknięć w ostatnich 90 dniach (≥ 50 wyświetleń, średnia pozycja ≤ 20 — tematy,
 *   w których strona już ma trafność), 1–4 słowa, bez fraz z nazwą marki z domeny projektu (heurystyka),
 * - szanse SEO: główna fraza otwartych szans (blisko TOP, słaba pozycja, niski CTR) z analizy 28 dni.
 */
final class SeedSuggester
{
	private const GSC_MIN_IMPRESSIONS = 50;

	private const GSC_MAX_POSITION = 20;

	private const MAX_WORDS = 4;

	private const OPPORTUNITY_TYPES = ['near_top', 'weak_position', 'low_ctr'];

	public function __construct(
		private readonly Connection $db,
		private readonly KeywordDiscoveryProvider $provider,
	) {
	}

	/**
	 * @return array{gsc: list<array{seed: string, clicks: int, impressions: int, position: float}>, opportunity: list<array{seed: string, type: string, priority: int}>}
	 */
	public function suggest(ProjectContext $context, int $limit = 10): array
	{
		$brand = self::brand($context->project()->domain);

		return [
			'gsc' => $this->fromGsc($context->projectId(), $brand, $limit),
			'opportunity' => $this->fromOpportunities($context->projectId(), $brand, (int) ceil($limit / 2)),
		];
	}

	/**
	 * @return list<array{seed: string, clicks: int, impressions: int, position: float}>
	 */
	private function fromGsc(int $projectId, ?string $brand, int $limit): array
	{
		$latest = $this->db->fetchValue("SELECT MAX(date) FROM `{$this->db->table('gsc_query_daily')}` WHERE project_id = %d", [$projectId]);

		if ($latest === null) {
			return [];
		}

		$rows = $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN k.keyword, a.clicks, a.impressions, a.position_sum
			FROM (
				SELECT q.keyword_id, SUM(q.clicks) AS clicks, SUM(q.impressions) AS impressions, SUM(q.position_sum) AS position_sum
				FROM `{$this->db->table('gsc_query_daily')}` q
				WHERE q.project_id = %d AND q.date BETWEEN %s AND %s
				GROUP BY q.keyword_id
				HAVING impressions >= %d AND position_sum <= %d * impressions
			) a
			JOIN `{$this->db->table('keywords')}` k ON k.id = a.keyword_id AND k.project_id = %d
			ORDER BY a.clicks DESC, a.impressions DESC, k.id
			LIMIT %d",
			[$projectId, DateRange::shift($latest, -89), $latest, self::GSC_MIN_IMPRESSIONS, self::GSC_MAX_POSITION, $projectId, $limit * 6],
		);
		$result = [];

		foreach ($rows as $row) {
			$seed = MarketKeyword::normalize((string) $row['keyword']);

			if (isset($result[$seed]) || ! $this->usable($seed, $brand)) {
				continue;
			}

			$impressions = (int) $row['impressions'];
			$result[$seed] = ['seed' => $seed, 'clicks' => (int) $row['clicks'], 'impressions' => $impressions, 'position' => round((float) $row['position_sum'] / max(1, $impressions), 1)];

			if (count($result) >= $limit) {
				break;
			}
		}

		return array_values($result);
	}

	/**
	 * @return list<array{seed: string, type: string, priority: int}>
	 */
	private function fromOpportunities(int $projectId, ?string $brand, int $limit): array
	{
		$rows = $this->db->fetchAll(
			"SELECT o.type, d.priority, d.evidence
			FROM `{$this->db->table('opportunity_detections')}` d
			JOIN `{$this->db->table('opportunities')}` o ON o.id = d.opportunity_id
			WHERE d.project_id = %d AND d.period_days = %d AND o.state = 'active'
				AND o.status IN (" . Connection::placeholders(OpportunityStatus::openValues()) . ')
				AND o.type IN (' . Connection::placeholders(self::OPPORTUNITY_TYPES) . ')
			ORDER BY d.priority DESC, o.id
			LIMIT %d',
			[$projectId, OpportunityConfig::CANONICAL_DAYS, ...OpportunityStatus::openValues(), ...self::OPPORTUNITY_TYPES, $limit * 4],
		);
		$result = [];

		foreach ($rows as $row) {
			$evidence = json_decode((string) $row['evidence'], true);
			$keyword = is_array($evidence) ? ($evidence['keywords'][0]['keyword'] ?? null) : null;
			$seed = is_string($keyword) ? MarketKeyword::normalize($keyword) : '';

			if ($seed === '' || isset($result[$seed]) || ! $this->usable($seed, $brand)) {
				continue;
			}

			$result[$seed] = ['seed' => $seed, 'type' => (string) $row['type'], 'priority' => (int) $row['priority']];

			if (count($result) >= $limit) {
				break;
			}
		}

		return array_values($result);
	}

	private function usable(string $seed, ?string $brand): bool
	{
		$words = count(explode(' ', $seed));

		return $seed !== ''
			&& $words <= self::MAX_WORDS
			&& ($brand === null || ! str_contains(str_replace([' ', '-'], '', $seed), $brand))
			&& $this->provider->acceptsKeyword($seed);
	}

	/** Nazwa marki z domeny (np. `ohsofresh.pl` → `ohsofresh`) — frazy brandowe nie są dobrymi seedami. */
	public static function brand(string $domain): ?string
	{
		$host = strtolower(trim($domain));
		$host = (string) preg_replace('#^https?://#', '', $host);
		$host = (string) preg_replace('#^www\.#', '', $host);
		$label = str_replace('-', '', explode('.', $host)[0] ?? '');

		return mb_strlen($label) >= 4 ? $label : null;
	}
}
