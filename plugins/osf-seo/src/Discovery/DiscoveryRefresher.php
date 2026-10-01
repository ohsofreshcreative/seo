<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Database\Connection;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Support\DateRange;

/**
 * Przeliczenie kandydatów projektu bez żadnego płatnego żądania: widoczność w GSC, strona docelowa, wykluczenia,
 * liczba seedów i priorytet. Uruchamiane po wynikach przebiegu, po zmianie wykluczeń i w tle, gdy zmieni się klucz
 * danych (nowy import GSC, reset property, odświeżone metryki rynkowe, nowe wyniki) — inaczej nic nie robi.
 *
 * Dopasowanie do GSC po kluczu rynkowym (`keywords.market_key`, indeks `project_market_key`): wszystkie warianty frazy
 * GSC (wielkość liter, spacje) sumowane razem; pozycja = Σ position_sum / Σ impressions. Bez N+1: paczki po 500 kluczy.
 */
final class DiscoveryRefresher
{
	/** Wersja reguł przeliczenia — zmiana wymusza ponowne przeliczenie wszystkich projektów. */
	private const VERSION = 1;

	private const CHUNK = 500;

	public function __construct(
		private readonly Connection $db,
		private readonly DiscoveryCandidateRepository $candidates,
		private readonly DiscoverySettingsRepository $settings,
		private readonly KeywordDiscoveryProvider $provider,
		private readonly DiscoveryConfig $config,
		private readonly MarketKeyBackfill $backfill,
	) {
	}

	/** @return int liczba zaktualizowanych kandydatów (0 — nic się nie zmieniło albo klucz danych bez zmian) */
	public function refresh(int $projectId, bool $force = false): int
	{
		$project = $this->db->fetchRow(
			"SELECT country, language, last_synced_at, gsc_data_property FROM `{$this->db->table('projects')}` WHERE id = %d",
			[$projectId],
		);
		$market = $project === null ? null : $this->provider->resolveMarket((string) $project['country'], (string) $project['language']);

		if ($market === null) {
			return 0;
		}

		$latest = $this->db->fetchValue("SELECT MAX(date) FROM `{$this->db->table('gsc_query_daily')}` WHERE project_id = %d", [$projectId]);
		$exclusions = $this->settings->exclusions($projectId);
		$key = md5((string) json_encode([
			self::VERSION,
			$latest,
			$project['last_synced_at'],
			$project['gsc_data_property'],
			$market->id(),
			$this->candidates->fingerprint($projectId, $market),
			$exclusions->hash(),
			$this->config->effective(),
		]));

		if (! $force && $this->settings->refreshKey($projectId) === $key) {
			return 0;
		}

		$this->backfill->fillProject($projectId);
		$rows = $this->candidates->scoringRows($projectId, $market);
		$stats = $this->candidates->sourceStats($projectId);
		$window = $latest === null ? null : [DateRange::shift($latest, -($this->config->windowDays() - 1)), $latest];
		$hexes = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['keyword_hex'], $rows)));
		$gsc = $window === null ? [] : $this->gsc($projectId, $window, $hexes);
		$pages = $window === null ? [] : $this->targetPages($projectId, $window, array_keys($gsc));
		$classifier = VisibilityClassifier::fromConfig($this->config);
		$scorer = new DiscoveryScorer($this->config->visiblePosition());
		$updates = [];

		foreach ($rows as $row) {
			$id = (int) $row['id'];
			$hex = (string) $row['keyword_hex'];
			$volume = $row['search_volume'] === null ? null : (int) $row['search_volume'];
			$metrics = $gsc[$hex] ?? ['impressions' => 0, 'clicks' => 0, 'position_sum' => 0.0];
			$visibility = $classifier->classify($latest !== null, $metrics['impressions'], $metrics['clicks'], $metrics['position_sum'], $volume);
			$seeds = $stats[$id]['seeds'] ?? max(1, (int) $row['seeds_count']);
			$best = $stats[$id]['best'] ?? (int) $row['best_relation'];
			$score = $scorer->score($volume, $row['keyword_difficulty'] === null ? null : (int) $row['keyword_difficulty'], $row['cpc'] === null ? null : (float) $row['cpc'], $best, $seeds, $visibility);
			$values = [
				'visibility' => $visibility->visibility->value,
				'gsc_impressions' => $visibility->impressions,
				'gsc_clicks' => $visibility->clicks,
				'gsc_position' => $visibility->position,
				'target_url' => $visibility->impressions !== null && $visibility->impressions > 0 ? ($pages[$hex] ?? null) : null,
				'priority' => $score->priority,
				'score' => (string) json_encode($score->toArray()),
				'excluded' => $exclusions->match((string) $row['keyword']) === null ? 0 : 1,
				'seeds_count' => min(65535, $seeds),
				'best_relation' => $best,
			];

			if (self::changed($row, $values)) {
				$updates[$id] = $values;
			}
		}

		$saved = $this->candidates->saveScores($projectId, $updates);
		$this->settings->saveRefresh($projectId, $key);

		return $saved;
	}

	/**
	 * Sumy GSC okna dla kluczy rynkowych (wszystkie warianty frazy projektu).
	 *
	 * @param array{0: string, 1: string} $window
	 * @param list<string> $hexes
	 * @return array<string, array{impressions: int, clicks: int, position_sum: float}>
	 */
	private function gsc(int $projectId, array $window, array $hexes): array
	{
		$result = [];

		foreach (array_chunk($hexes, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN LOWER(HEX(k.market_key)) AS h, SUM(q.impressions) AS impressions, SUM(q.clicks) AS clicks, SUM(q.position_sum) AS position_sum
				FROM `{$this->db->table('keywords')}` k
				JOIN `{$this->db->table('gsc_query_daily')}` q ON q.project_id = k.project_id AND q.keyword_id = k.id AND q.date BETWEEN %s AND %s
				WHERE k.project_id = %d AND k.market_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')
				GROUP BY k.market_key',
				[$window[0], $window[1], $projectId, ...$chunk],
			) as $row) {
				$result[(string) $row['h']] = ['impressions' => (int) $row['impressions'], 'clicks' => (int) $row['clicks'], 'position_sum' => (float) $row['position_sum']];
			}
		}

		return $result;
	}

	/**
	 * Strona docelowa frazy: adres z największą liczbą kliknięć, potem wyświetleń w oknie (GSC query × page) — tylko gdy
	 * GSC ją zna; bez danych nie zgadujemy („Brak przypisanej strony”).
	 *
	 * @param array{0: string, 1: string} $window
	 * @param list<string> $hexes
	 * @return array<string, string>
	 */
	private function targetPages(int $projectId, array $window, array $hexes): array
	{
		$best = [];

		foreach (array_chunk($hexes, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN LOWER(HEX(k.market_key)) AS h, qp.page_id, SUM(qp.clicks) AS clicks, SUM(qp.impressions) AS impressions
				FROM `{$this->db->table('keywords')}` k
				JOIN `{$this->db->table('gsc_query_page_daily')}` qp ON qp.project_id = k.project_id AND qp.keyword_id = k.id AND qp.date BETWEEN %s AND %s
				WHERE k.project_id = %d AND k.market_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')
				GROUP BY k.market_key, qp.page_id',
				[$window[0], $window[1], $projectId, ...$chunk],
			) as $row) {
				$hex = (string) $row['h'];
				$candidate = [(int) $row['clicks'], (int) $row['impressions'], -(int) $row['page_id']];

				if (! isset($best[$hex]) || $candidate > $best[$hex]) {
					$best[$hex] = $candidate;
				}
			}
		}

		$pageIds = array_values(array_unique(array_map(static fn (array $value): int => -$value[2], $best)));
		$urls = [];

		foreach (array_chunk($pageIds, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, url FROM `{$this->db->table('pages')}` WHERE project_id = %d AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$projectId, ...$chunk],
			) as $row) {
				$urls[(int) $row['id']] = (string) preg_replace('/#.*$/', '', (string) $row['url']);
			}
		}

		$result = [];

		foreach ($best as $hex => $value) {
			if (isset($urls[-$value[2]])) {
				$result[(string) $hex] = mb_substr($urls[-$value[2]], 0, 2048, 'UTF-8');
			}
		}

		return $result;
	}

	/**
	 * @param array<string, string|null> $row
	 * @param array<string, int|float|string|null> $values
	 */
	private static function changed(array $row, array $values): bool
	{
		foreach ($values as $column => $value) {
			$current = $row[$column] ?? null;

			if ($column === 'gsc_position') {
				if (($current === null) !== ($value === null) || ($current !== null && abs((float) $current - (float) $value) > 0.004)) {
					return true;
				}
			} elseif ($column === 'score') {
				if (json_decode((string) $current, true) != json_decode((string) $value, true)) {
					return true;
				}
			} elseif (($current === null ? null : (string) $current) !== ($value === null ? null : (string) $value)) {
				return true;
			}
		}

		return false;
	}
}
