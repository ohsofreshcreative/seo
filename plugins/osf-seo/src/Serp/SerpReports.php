<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;

/**
 * Zapytania odczytu modułu pozycji (bez N+1 i bez skanowania historii przy wejściu na stronę):
 *
 * - lista i liczniki — wyłącznie bieżący stan fraz (`serp_tracked_keywords`, indeks `project_rank`),
 * - historia frazy — `serp_snapshots` po (`tracked_keyword_id`, `context_id`, `checked_at`),
 * - pełne TOP N pomiaru — zakres klucza głównego `serp_results` + słowniki po kluczu głównym,
 * - pozycje domen (konkurenci, projekt) — indeks `domain_snapshot` (domena → pomiary),
 * - konkurenci organiczni — agregacja najnowszych pomiarów projektu (zakres PK wyników per pomiar).
 */
final class SerpReports
{
	private const LIST_COLUMNS = 't.id, t.public_id, t.market_keyword_id, t.source, t.status, t.added_at, t.last_snapshot_id, t.last_context_id,
		t.last_checked_at, t.last_found, t.last_rank, t.last_rank_absolute, t.last_depth, t.last_featured, t.prev_rank, t.change_type,
		t.change_value, t.top10_change, m.keyword, LOWER(HEX(m.keyword_key)) AS keyword_hex, m.search_volume, m.keyword_difficulty, u.url';

	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Liczniki modułu z bieżącego stanu fraz (ostatni pomiar względem poprzedniego porównywalnego).
	 *
	 * @return array<string, int|string|null>
	 */
	public function summary(int $projectId): array
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS tracked, MAX(last_checked_at) AS last_checked, SUM(last_found IS NULL) AS unchecked,
				SUM(last_found = 1) AS found, SUM(last_found = 0) AS outside, SUM(last_rank <= 3) AS top3, SUM(last_rank <= 10) AS top10,
				SUM(change_type = 'up') AS improved, SUM(change_type = 'down') AS declined, SUM(change_type = 'entered') AS entered,
				SUM(change_type = 'left') AS left_depth, SUM(top10_change = 'entered') AS top10_entered, SUM(top10_change = 'left') AS top10_left,
				SUM(last_featured = 1) AS featured
			FROM `{$this->db->table('serp_tracked_keywords')}` WHERE project_id = %d AND status = 'active'",
			[$projectId],
		) ?? [];
		$result = [];

		foreach ($row as $key => $value) {
			$result[$key] = $key === 'last_checked' ? $value : (int) $value;
		}

		return $result;
	}

	/**
	 * @return array{rows: list<TrackedKeywordRow>, total: int}
	 */
	public function positions(int $projectId, PositionsFilters $filters): array
	{
		$where = ["t.project_id = %d", "t.status = 'active'"];
		$params = [$projectId];

		if ($filters->q !== '') {
			$where[] = 'm.keyword LIKE %s';
			$params[] = '%' . $this->db->escapeLike(mb_strtolower($filters->q)) . '%';
		}

		$where[] = match ($filters->change) {
			'up', 'down', 'entered', 'left', 'new', 'out' => "t.change_type = '{$filters->change}'",
			'top10_entered' => "t.top10_change = 'entered'",
			'top10_left' => "t.top10_change = 'left'",
			default => '1 = 1',
		};
		$where[] = match ($filters->band) {
			'top3' => 't.last_rank <= 3',
			'top10' => 't.last_rank <= 10',
			'top20' => 't.last_rank <= 20',
			'top50' => 't.last_rank <= 50',
			'found' => 't.last_found = 1',
			'out' => 't.last_found = 0',
			'unchecked' => 't.last_found IS NULL',
			default => '1 = 1',
		};
		$dir = $filters->direction === 'desc' ? 'DESC' : 'ASC';
		$order = match ($filters->sort) {
			'keyword' => "m.keyword {$dir}",
			'volume' => "m.search_volume IS NULL, m.search_volume {$dir}",
			'difficulty' => "m.keyword_difficulty IS NULL, m.keyword_difficulty {$dir}",
			'change' => "t.change_value IS NULL, t.change_value {$dir}",
			'checked' => "t.last_checked_at IS NULL, t.last_checked_at {$dir}",
			'added' => "t.added_at {$dir}",
			default => "t.last_found IS NULL, t.last_found = 0, t.last_rank {$dir}",
		};
		$params[] = $filters->perPage;
		$params[] = $filters->offset();

		$rows = $this->db->fetchAll(
			'SELECT STRAIGHT_JOIN ' . self::LIST_COLUMNS . ", COUNT(*) OVER () AS total_rows
			FROM `{$this->db->table('serp_tracked_keywords')}` t
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = t.market_keyword_id
			LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = t.last_url_id
			WHERE " . implode(' AND ', $where) . " ORDER BY {$order}, t.id LIMIT %d OFFSET %d",
			$params,
		);

		return [
			'rows' => array_map(static fn (array $row): TrackedKeywordRow => TrackedKeywordRow::fromRow($row), $rows),
			'total' => (int) ($rows[0]['total_rows'] ?? 0),
		];
	}

	public function tracked(int $projectId, string $publicId): ?TrackedKeywordRow
	{
		$row = $this->db->fetchRow(
			'SELECT STRAIGHT_JOIN ' . self::LIST_COLUMNS . "
			FROM `{$this->db->table('serp_tracked_keywords')}` t
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = t.market_keyword_id
			LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = t.last_url_id
			WHERE t.project_id = %d AND t.public_id = %s",
			[$projectId, $publicId],
		);

		return $row === null ? null : TrackedKeywordRow::fromRow($row);
	}

	/**
	 * Historia pomiarów frazy w kontekście (zakończone pomiary, od najnowszych).
	 *
	 * @return list<array<string, string|null>>
	 */
	public function history(int $trackedId, int $contextId, int $limit = 400): array
	{
		return $this->db->fetchAll(
			"SELECT s.id, s.public_id, s.checked_at, s.requested_depth, s.project_rank, s.project_rank_absolute, s.project_results, s.project_featured,
				s.organic_count, s.item_types, s.spell_type, s.spell_keyword, u.url
			FROM `{$this->db->table('serp_snapshots')}` s
			LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = s.project_url_id
			WHERE s.tracked_keyword_id = %d AND s.context_id = %d AND s.status = 'completed'
			ORDER BY s.checked_at DESC, s.id DESC LIMIT %d",
			[$trackedId, $contextId, max(1, $limit)],
		);
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public function snapshot(int $trackedId, ?string $publicId): ?array
	{
		$columns = 's.id, s.public_id, s.context_id, s.status, s.checked_at, s.requested_depth, s.se_domain, s.se_results_count, s.pages_count,
			s.items_count, s.organic_count, s.item_types, s.spell_type, s.spell_keyword, s.project_rank, s.project_rank_absolute, s.project_featured';

		return $publicId === null
			? $this->db->fetchRow(
				"SELECT {$columns} FROM `{$this->db->table('serp_tracked_keywords')}` t
				JOIN `{$this->db->table('serp_snapshots')}` s ON s.id = t.last_snapshot_id WHERE t.id = %d",
				[$trackedId],
			)
			: $this->db->fetchRow(
				"SELECT {$columns} FROM `{$this->db->table('serp_snapshots')}` s WHERE s.tracked_keyword_id = %d AND s.public_id = %s AND s.status = 'completed'",
				[$trackedId, $publicId],
			);
	}

	/**
	 * Pełne wyniki pomiaru (wszystkie domeny) w kolejności SERP.
	 *
	 * @return list<array<string, string|null>>
	 */
	public function results(int $snapshotId, int $limit = 1000, int $offset = 0): array
	{
		return $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN r.item_index, r.result_type, r.rank_group, r.rank_absolute, r.page, r.flags, d.host, u.url, sn.title, sn.description, sn.breadcrumb, sn.website_name, sn.extra
			FROM `{$this->db->table('serp_results')}` r
			JOIN `{$this->db->table('serp_domains')}` d ON d.id = r.domain_id
			JOIN `{$this->db->table('serp_urls')}` u ON u.id = r.url_id
			LEFT JOIN `{$this->db->table('serp_snippets')}` sn ON sn.id = r.snippet_id
			WHERE r.snapshot_id = %d ORDER BY r.item_index LIMIT %d OFFSET %d",
			[$snapshotId, max(1, $limit), max(0, $offset)],
		);
	}

	/**
	 * Identyfikatory hostów rodziny domeny (domena + subdomeny) — prefiks odwróconego hosta w indeksie.
	 *
	 * @return list<int>
	 */
	public function familyDomainIds(string $domain): array
	{
		$reversed = DomainFamily::reverse($domain);

		return array_map(static fn (array $row): int => (int) $row['id'], $this->db->fetchAll(
			"SELECT id FROM `{$this->db->table('serp_domains')}` WHERE host_rev = %s OR host_rev LIKE %s",
			[$reversed, $this->db->escapeLike($reversed) . '.%'],
		));
	}

	/**
	 * Wyniki rodzin domen (konkurentów) w wybranych pomiarach: pomiar → rodzina → wyniki (pozycja, adres).
	 *
	 * @param list<int> $snapshotIds
	 * @param array<string, list<int>> $families klucz rodziny → identyfikatory hostów
	 * @return array<int, array<string, list<array{rank: int, rank_absolute: int, url: string}>>>
	 */
	public function familyResults(array $snapshotIds, array $families): array
	{
		$byDomain = [];

		foreach ($families as $key => $ids) {
			foreach ($ids as $id) {
				$byDomain[$id] = $key;
			}
		}

		if ($snapshotIds === [] || $byDomain === []) {
			return [];
		}

		$result = [];

		foreach (array_chunk(array_values(array_unique($snapshotIds)), 500) as $chunk) {
			foreach (array_chunk(array_keys($byDomain), 500) as $domains) {
				$rows = $this->db->fetchAll(
					"SELECT STRAIGHT_JOIN r.snapshot_id, r.domain_id, r.rank_group, r.rank_absolute, u.url
					FROM `{$this->db->table('serp_results')}` r JOIN `{$this->db->table('serp_urls')}` u ON u.id = r.url_id
					WHERE r.domain_id IN (" . Connection::placeholders($domains, '%d') . ') AND r.snapshot_id IN (' . Connection::placeholders($chunk, '%d') . ') AND r.result_type = 1
					ORDER BY r.snapshot_id, r.rank_group',
					[...$domains, ...$chunk],
				);

				foreach ($rows as $row) {
					$result[(int) $row['snapshot_id']][$byDomain[(int) $row['domain_id']]][] = [
						'rank' => (int) $row['rank_group'],
						'rank_absolute' => (int) $row['rank_absolute'],
						'url' => (string) $row['url'],
					];
				}
			}
		}

		return $result;
	}

	/**
	 * Najnowsze pomiary aktywnych fraz projektu: pomiar → fraza (do pozycji konkurentów).
	 *
	 * @return array<int, array{public_id: string, keyword: string, search_volume: ?int, rank: ?int, found: bool, depth: int, prev_snapshot_id: ?int, checked_at: string}>
	 */
	public function latestSnapshots(int $projectId): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN t.last_snapshot_id, t.prev_snapshot_id, t.public_id, t.last_rank, t.last_found, t.last_depth, t.last_checked_at, m.keyword, m.search_volume
			FROM `{$this->db->table('serp_tracked_keywords')}` t JOIN `{$this->db->table('market_keywords')}` m ON m.id = t.market_keyword_id
			WHERE t.project_id = %d AND t.status = 'active' AND t.last_snapshot_id IS NOT NULL",
			[$projectId],
		) as $row) {
			$result[(int) $row['last_snapshot_id']] = [
				'public_id' => (string) $row['public_id'],
				'keyword' => (string) $row['keyword'],
				'search_volume' => $row['search_volume'] === null ? null : (int) $row['search_volume'],
				'rank' => $row['last_rank'] === null ? null : (int) $row['last_rank'],
				'found' => (int) $row['last_found'] === 1,
				'depth' => (int) $row['last_depth'],
				'prev_snapshot_id' => $row['prev_snapshot_id'] === null ? null : (int) $row['prev_snapshot_id'],
				'checked_at' => (string) $row['last_checked_at'],
			];
		}

		return $result;
	}

	/**
	 * Stan pomiarów monitorowanych fraz projektu (klucz pamięci podręcznej zestawień): liczba fraz, sprawdzonych i sumy
	 * identyfikatorów ostatnich pomiarów — zmienia się przy każdym nowym pomiarze, dodaniu i usunięciu frazy.
	 *
	 * @return array{checked: int, key: string}
	 */
	public function measurementsVersion(int $projectId): array
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS tracked, COALESCE(SUM(last_snapshot_id IS NOT NULL), 0) AS checked, COALESCE(MAX(last_snapshot_id), 0) AS max_id,
				COALESCE(SUM(last_snapshot_id), 0) AS sum_id
			FROM `{$this->db->table('serp_tracked_keywords')}` WHERE project_id = %d AND status = 'active'",
			[$projectId],
		) ?? [];

		return [
			'checked' => (int) ($row['checked'] ?? 0),
			'key' => implode(':', [(int) ($row['tracked'] ?? 0), (int) ($row['checked'] ?? 0), (string) ($row['max_id'] ?? 0), (string) ($row['sum_id'] ?? 0)]),
		];
	}

	/**
	 * Konkurenci organiczni: domeny z najnowszych pełnych SERP-ów monitorowanych fraz (fakty, bez ocen).
	 * Dla każdej domeny — najlepsza pozycja na frazę, potem: liczba fraz, TOP3/10/20, średnia pozycja (tylko frazy z domeną),
	 * frazy wspólne z projektem (oba w wynikach).
	 *
	 * @param list<int> $excludeDomainIds hosty projektu
	 * @return array{rows: list<array<string, int|float|string>>, total: int}
	 */
	public function organicCompetitors(int $projectId, array $excludeDomainIds, int $limit, int $offset, string $sort = 'keywords'): array
	{
		$exclude = $excludeDomainIds === [] ? '' : ' AND x.domain_id NOT IN (' . Connection::placeholders($excludeDomainIds, '%d') . ')';
		$order = match ($sort) {
			'top10' => 'top10 DESC, keywords DESC',
			'top3' => 'top3 DESC, keywords DESC',
			'overlap' => 'overlap DESC, keywords DESC',
			'avg_rank' => 'avg_rank ASC, keywords DESC',
			default => 'keywords DESC, top10 DESC',
		};
		$rows = $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN x.domain_id, d.host, COUNT(*) AS keywords, SUM(x.best <= 3) AS top3, SUM(x.best <= 10) AS top10, SUM(x.best <= 20) AS top20,
				ROUND(AVG(x.best), 1) AS avg_rank, SUM(x.project_found) AS overlap, COUNT(*) OVER () AS total_rows
			FROM (
				SELECT STRAIGHT_JOIN r.snapshot_id, r.domain_id, MIN(r.rank_group) AS best, MAX(t.last_found) AS project_found
				FROM `{$this->db->table('serp_tracked_keywords')}` t
				JOIN `{$this->db->table('serp_results')}` r ON r.snapshot_id = t.last_snapshot_id AND r.result_type = 1
				WHERE t.project_id = %d AND t.status = 'active'
				GROUP BY r.snapshot_id, r.domain_id
			) x JOIN `{$this->db->table('serp_domains')}` d ON d.id = x.domain_id
			WHERE 1 = 1{$exclude}
			GROUP BY x.domain_id, d.host ORDER BY {$order}, d.host LIMIT %d OFFSET %d",
			[$projectId, ...$excludeDomainIds, max(1, $limit), max(0, $offset)],
		);

		if ($rows === []) {
			return ['rows' => [], 'total' => 0];
		}

		$domainIds = array_map(static fn (array $row): int => (int) $row['domain_id'], $rows);
		$urls = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN r.domain_id, COUNT(DISTINCT r.url_id) AS urls
			FROM `{$this->db->table('serp_tracked_keywords')}` t
			JOIN `{$this->db->table('serp_results')}` r ON r.snapshot_id = t.last_snapshot_id AND r.result_type = 1
			WHERE t.project_id = %d AND t.status = 'active' AND r.domain_id IN (" . Connection::placeholders($domainIds, '%d') . ')
			GROUP BY r.domain_id',
			[$projectId, ...$domainIds],
		) as $row) {
			$urls[(int) $row['domain_id']] = (int) $row['urls'];
		}

		return [
			'rows' => array_map(static fn (array $row): array => [
				'host' => (string) $row['host'],
				'keywords' => (int) $row['keywords'],
				'top3' => (int) $row['top3'],
				'top10' => (int) $row['top10'],
				'top20' => (int) $row['top20'],
				'avg_rank' => (float) $row['avg_rank'],
				'overlap' => (int) $row['overlap'],
				'urls' => $urls[(int) $row['domain_id']] ?? 0,
			], $rows),
			'total' => (int) $rows[0]['total_rows'],
		];
	}

	/**
	 * Średnia pozycja GSC (Σ position_sum / Σ wyświetleń, wszystkie warianty frazy GSC o tym kluczu rynkowym)
	 * w ostatnich $days dniach danych — kontekst obok Pozycji SERP, nigdy jej nie zastępuje.
	 *
	 * @param list<string> $keys klucze rynkowe (hex)
	 * @return array<string, array{position: float, impressions: int}>
	 */
	public function gscAverages(int $projectId, array $keys, int $days): array
	{
		if ($keys === []) {
			return [];
		}

		$latest = $this->db->fetchValue("SELECT MAX(date) FROM `{$this->db->table('gsc_site_daily')}` WHERE project_id = %d", [$projectId]);

		if ($latest === null) {
			return [];
		}

		$start = (new \DateTimeImmutable($latest))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
		$result = [];

		foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN LOWER(HEX(k.market_key)) AS h, SUM(q.impressions) AS impressions, SUM(q.position_sum) AS position_sum
				FROM `{$this->db->table('keywords')}` k
				JOIN `{$this->db->table('gsc_query_daily')}` q ON q.project_id = k.project_id AND q.keyword_id = k.id AND q.date BETWEEN %s AND %s
				WHERE k.project_id = %d AND k.market_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')
				GROUP BY k.market_key',
				[$start, $latest, $projectId, ...$chunk],
			) as $row) {
				if ((int) $row['impressions'] > 0) {
					$result[(string) $row['h']] = [
						'position' => round((float) $row['position_sum'] / (int) $row['impressions'], 1),
						'impressions' => (int) $row['impressions'],
					];
				}
			}
		}

		return $result;
	}

	/**
	 * Bieżąca Pozycja SERP monitorowanych fraz projektu po kluczu rynkowym (kolumna w liście Frazy): od fraz rynku
	 * (UNIQUE provider × lokalizacja × język × klucz), potem fraza projektu (UNIQUE projekt × fraza rynkowa).
	 *
	 * @param list<string> $keys klucze rynkowe (hex)
	 * @return array<string, array{public_id: string, rank: ?int, found: ?bool, depth: ?int, checked_at: ?string}>
	 */
	public function ranksForKeys(int $projectId, Market $market, array $keys): array
	{
		$result = [];

		foreach (array_chunk($keys, 500) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN LOWER(HEX(m.keyword_key)) AS h, t.public_id, t.last_rank, t.last_found, t.last_depth, t.last_checked_at
				FROM `{$this->db->table('market_keywords')}` m
				JOIN `{$this->db->table('serp_tracked_keywords')}` t ON t.project_id = %d AND t.market_keyword_id = m.id AND t.status = 'active'
				WHERE m.provider = %s AND m.location_code = %d AND m.language_code = %s AND m.keyword_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				[$projectId, $market->provider, $market->locationCode, $market->languageCode, ...$chunk],
			) as $row) {
				$result[(string) $row['h']] = [
					'public_id' => (string) $row['public_id'],
					'rank' => $row['last_rank'] === null ? null : (int) $row['last_rank'],
					'found' => $row['last_found'] === null ? null : (int) $row['last_found'] === 1,
					'depth' => $row['last_depth'] === null ? null : (int) $row['last_depth'],
					'checked_at' => $row['last_checked_at'],
				];
			}
		}

		return $result;
	}
}
