<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use OsfSeo\Analytics\KeywordReport;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Support\DateRange;

/**
 * Wybór fraz projektu do wzbogacenia danymi rynkowymi (reguła w docs/ARCHITECTURE.md, sekcja 11.5):
 *
 * - frazy z co najmniej `OSF_SEO_DATAFORSEO_MIN_IMPRESSIONS` (50) wyświetleniami GSC w ostatnich
 *   `OSF_SEO_DATAFORSEO_WINDOW_DAYS` (90) dniach danych (do ostatniej zaimportowanej daty),
 * - kolejność: wyświetlenia, kliknięcia (najważniejsze frazy najpierw — przy limicie zadań wzbogacamy je pierwsze),
 * - tylko frazy bez metryk albo z metrykami nieaktualnymi (TTL), bez wolumenu oczekującego na wynik zadania,
 * - frazy, których dostawca nie przyjmie (długość, liczba słów, niedozwolone znaki) są pomijane,
 * - warianty różniące się wielkością liter/spacjami = jedna fraza rynkowa (jeden płatny odczyt).
 *
 * Frazy szans SEO spełniają tę regułę (progi szans: ≥ 50–200 wyświetleń w 28 dni), więc nie są wybierane osobno.
 * Jedno zapytanie: zakres PK `gsc_query_daily` (project_id, date) → słownik po PRIMARY → metryki po UNIQUE rynku.
 */
final class MarketCandidateSelector
{
	/** Zapas wierszy SQL na frazy odrzucone przez reguły dostawcy i zduplikowane warianty. */
	private const OVERFETCH = 2;

	public function __construct(
		private readonly Connection $db,
		private readonly KeywordReport $keywords,
	) {
	}

	/**
	 * @return array{candidates: list<MarketCandidate>, window: ?DateRange, rows: int, rejected: int, duplicates: int}
	 */
	public function select(
		ProjectContext $context,
		Market $market,
		KeywordMetricsProvider $provider,
		int $limit,
		int $minImpressions,
		int $windowDays,
		string $now,
		bool $force = false,
	): array {
		$latest = $this->keywords->latestDate($context);

		if ($latest === null || $limit < 1) {
			return ['candidates' => [], 'window' => null, 'rows' => 0, 'rejected' => 0, 'duplicates' => 0];
		}

		$window = new DateRange(DateRange::shift($latest, -($windowDays - 1)), $latest);
		$projectId = $context->projectId();
		$staleSql = $force ? '' : 'AND (m.id IS NULL
				OR ((m.volume_stale_after IS NULL OR m.volume_stale_after <= %s) AND (m.volume_pending_until IS NULL OR m.volume_pending_until <= %s))
				OR m.difficulty_stale_after IS NULL OR m.difficulty_stale_after <= %s)';
		$params = [$projectId, $window->start, $window->end, max(1, $minImpressions), $projectId, $market->provider, $market->locationCode, $market->languageCode];

		if (! $force) {
			array_push($params, $now, $now, $now);
		}

		$params[] = min($limit * self::OVERFETCH + 100, 100000);

		$rows = $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN k.keyword, a.impressions, a.clicks, m.id AS market_id,
				m.volume_stale_after, m.volume_pending_until, m.difficulty_stale_after
			FROM (
				SELECT q.keyword_id, SUM(q.impressions) AS impressions, SUM(q.clicks) AS clicks
				FROM `{$this->db->table('gsc_query_daily')}` q
				WHERE q.project_id = %d AND q.date BETWEEN %s AND %s
				GROUP BY q.keyword_id
				HAVING impressions >= %d
			) a
			JOIN `{$this->db->table('keywords')}` k ON k.id = a.keyword_id AND k.project_id = %d
			LEFT JOIN `{$this->db->table('market_keywords')}` m
				ON m.provider = %s AND m.location_code = %d AND m.language_code = %s AND m.keyword_key = k.market_key
			WHERE k.market_key IS NOT NULL {$staleSql}
			ORDER BY a.impressions DESC, a.clicks DESC, k.id
			LIMIT %d",
			$params,
		);

		$candidates = [];
		$rejected = 0;
		$duplicates = 0;

		foreach ($rows as $row) {
			$normalized = MarketKeyword::normalize((string) $row['keyword']);

			if (isset($candidates[$normalized])) {
				$duplicates++;

				continue;
			}

			if (! $provider->acceptsKeyword($normalized)) {
				$rejected++;

				continue;
			}

			$pending = $row['volume_pending_until'] !== null && $row['volume_pending_until'] > $now;
			$needsVolume = ! $pending && ($force || $row['market_id'] === null || $row['volume_stale_after'] === null || $row['volume_stale_after'] <= $now);
			$needsDifficulty = $force || $row['market_id'] === null || $row['difficulty_stale_after'] === null || $row['difficulty_stale_after'] <= $now;

			if (! $needsVolume && ! $needsDifficulty) {
				continue;
			}

			$candidates[$normalized] = new MarketCandidate($normalized, (string) $row['keyword'], (int) $row['impressions'], (int) $row['clicks'], $needsVolume, $needsDifficulty);

			if (count($candidates) >= $limit) {
				break;
			}
		}

		return ['candidates' => array_values($candidates), 'window' => $window, 'rows' => count($rows), 'rejected' => $rejected, 'duplicates' => $duplicates];
	}
}
