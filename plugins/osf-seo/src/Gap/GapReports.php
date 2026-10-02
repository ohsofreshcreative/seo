<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Odczyty Luk SEO z gotowych (przeliczonych w tle) kolumn — bez agregacji przy renderowaniu. Każde zapytanie jest
 * zawężone do projektu (`project_id` z ProjectContext); obiekty z URL-a szukamy po (project_id, public_id) albo
 * (project_id, klucz adresu) — identyfikator innego projektu daje „nie znaleziono”.
 */
final class GapReports
{
	private const SORT_COLUMNS = [
		'priority' => 'g.priority',
		'volume' => 'g.search_volume',
		'difficulty' => 'g.keyword_difficulty',
		'competitor_rank' => 'g.best_competitor_rank',
		'competitors' => 'g.competitors_count',
		'keyword' => 'm.keyword',
		'first_seen' => 'g.first_seen_at',
	];

	/** Kolumny sortowania, które mogą być NULL (brak danych zawsze na końcu). */
	private const NULLABLE_SORTS = ['volume', 'difficulty', 'competitor_rank'];

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Lista luk fraz (filtry, sortowanie i paginacja w SQL).
	 *
	 * @return array{rows: list<array<string, string|null>>, total: int}
	 */
	public function keywords(int $projectId, GapFilters $filters, ?int $competitorDatasetId, int $competitorMaxRank): array
	{
		[$where, $params] = $this->where($projectId, $filters, $competitorDatasetId, $competitorMaxRank);
		$column = self::SORT_COLUMNS[$filters->sort] ?? 'g.priority';
		$direction = $filters->direction === 'asc' ? 'ASC' : 'DESC';
		// NULL-e zawsze na końcu, potem stabilny porządek (priorytet, id).
		$order = (in_array($filters->sort, self::NULLABLE_SORTS, true) ? "{$column} IS NULL, " : '') . "{$column} {$direction}, g.priority DESC, g.id ASC";
		$keywordJoin = $filters->q !== '' || $filters->sort === 'keyword' ? "JOIN `{$this->db->table('market_keywords')}` m ON m.id = g.market_keyword_id " : '';
		// Najpierw identyfikatory strony (indeks project_list pokrywa filtry domyślnej listy), potem szczegóły tylko 50 wierszy.
		$rows = $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN g.*, m.keyword, c.name AS competitor_name, c.public_id AS competitor_public_id, u.url AS best_url,
				t.url AS target_url, cl.public_id AS cluster_public_id, cl.label AS cluster_label
			FROM (SELECT g.id FROM `{$this->table()}` g {$keywordJoin}WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d) page
			JOIN `{$this->table()}` g ON g.id = page.id
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = g.market_keyword_id
			LEFT JOIN `{$this->db->table('serp_competitors')}` c ON c.id = g.best_competitor_id
			LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = g.best_url_id
			LEFT JOIN `{$this->db->table('serp_urls')}` t ON t.id = g.target_url_id
			LEFT JOIN `{$this->db->table('gap_clusters')}` cl ON cl.id = g.cluster_id
			ORDER BY {$order}",
			[...$params, $filters->perPage, ($filters->page - 1) * $filters->perPage],
		);
		$total = (int) $this->db->fetchValue(
			"SELECT COUNT(*) FROM `{$this->table()}` g " . ($filters->q === '' ? '' : "JOIN `{$this->db->table('market_keywords')}` m ON m.id = g.market_keyword_id ") . "WHERE {$where}",
			$params,
		);

		return ['rows' => $rows, 'total' => $total];
	}

	/**
	 * Liczniki przeglądu: luki wg typu, wysoki priorytet, nowe od chwili, odfiltrowane, grupy wg luki treści, strony.
	 *
	 * @return array{types: array<string, int>, high: int, new: int, filtered: int, content: array<string, int>, pages: int}
	 */
	public function counts(int $projectId, ?string $newSince): array
	{
		$result = ['types' => [], 'high' => 0, 'new' => 0, 'filtered' => 0, 'content' => [], 'pages' => 0];

		foreach ($this->db->fetchAll(
			"SELECT gap_type, COUNT(*) AS n, SUM(priority >= 60) AS high, SUM(first_seen_at >= %s) AS fresh
			FROM `{$this->table()}` WHERE project_id = %d AND active = 1 AND listed = 1 GROUP BY gap_type",
			[$newSince ?? '9999-12-31 00:00:00', $projectId],
		) as $row) {
			$result['types'][(string) $row['gap_type']] = (int) $row['n'];

			if (GapType::from((string) $row['gap_type'])->isGap()) {
				$result['high'] += (int) $row['high'];
				$result['new'] += (int) $row['fresh'];
			}
		}

		$result['filtered'] = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table()}` WHERE project_id = %d AND active = 1 AND listed = 0", [$projectId]);

		foreach ($this->db->fetchAll(
			"SELECT content_gap, COUNT(*) AS n FROM `{$this->db->table('gap_clusters')}` WHERE project_id = %d AND active = 1 GROUP BY content_gap",
			[$projectId],
		) as $row) {
			$result['content'][(string) $row['content_gap']] = (int) $row['n'];
		}

		$result['pages'] = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$this->db->table('gap_competitor_pages')}` WHERE project_id = %d", [$projectId]);

		return $result;
	}

	/**
	 * Powody odfiltrowania (liczby) — przegląd „Odfiltrowane”.
	 *
	 * @return array<string, int>
	 */
	public function filterReasons(int $projectId): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT filter_reason, COUNT(*) AS n FROM `{$this->table()}` WHERE project_id = %d AND active = 1 AND listed = 0 GROUP BY filter_reason",
			[$projectId],
		) as $row) {
			$result[(string) $row['filter_reason']] = (int) $row['n'];
		}

		return $result;
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public function keyword(int $projectId, string $publicId): ?array
	{
		return $this->db->fetchRow(
			"SELECT g.*, m.keyword, LOWER(HEX(m.keyword_key)) AS keyword_hex, m.competition_index, m.competition_level, m.volume_fetched_at,
				m.difficulty_fetched_at, c.name AS competitor_name, c.public_id AS competitor_public_id, u.url AS best_url, t.url AS target_url,
				cl.public_id AS cluster_public_id, cl.label AS cluster_label, cl.content_reason, cl.confidence
			FROM `{$this->table()}` g
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = g.market_keyword_id
			LEFT JOIN `{$this->db->table('serp_competitors')}` c ON c.id = g.best_competitor_id
			LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = g.best_url_id
			LEFT JOIN `{$this->db->table('serp_urls')}` t ON t.id = g.target_url_id
			LEFT JOIN `{$this->db->table('gap_clusters')}` cl ON cl.id = g.cluster_id
			WHERE g.project_id = %d AND g.public_id = %s",
			[$projectId, $publicId],
		);
	}

	/** Luka frazy po kluczu rynkowym (CLI: `gap:keyword --keyword=`). */
	public function keywordByKey(int $projectId, string $keywordHex): ?string
	{
		return $this->db->fetchValue(
			"SELECT g.public_id FROM `{$this->table()}` g JOIN `{$this->db->table('market_keywords')}` m ON m.id = g.market_keyword_id
			WHERE g.project_id = %d AND m.keyword_key = UNHEX(%s) LIMIT 1",
			[$projectId, $keywordHex],
		);
	}

	/**
	 * Pozycje fraz w zbiorach domen (konkurenci i punkt odniesienia) — dowody w szczegółach luki.
	 *
	 * @param list<int> $domainIds
	 * @return array<int, array<string, string|null>> id zbioru → wiersz
	 */
	public function evidence(array $domainIds, int $marketKeywordId): array
	{
		$domainIds = array_values(array_unique(array_map('intval', $domainIds)));

		if ($domainIds === []) {
			return [];
		}

		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT dk.domain_id, dk.rank_group, dk.rank_absolute, dk.etv, dk.serp_on, dk.first_seen, dk.last_seen, dk.prev_rank, dk.changed_on, dk.present,
				u.url, p.title
			FROM `{$this->db->table('gap_domain_keywords')}` dk
			LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = dk.url_id
			LEFT JOIN `{$this->db->table('gap_domain_pages')}` p ON p.domain_id = dk.domain_id AND p.url_id = dk.url_id
			WHERE dk.domain_id IN (" . Connection::placeholders($domainIds, '%d') . ') AND dk.market_keyword_id = %d',
			[...$domainIds, $marketKeywordId],
		) as $row) {
			$result[(int) $row['domain_id']] = $row;
		}

		return $result;
	}

	/**
	 * Dowody z projektu: warianty frazy w GSC (okno), strony GSC, pomiar SERP (STEP 14), kandydat Nowych fraz, szanse SEO.
	 *
	 * @param array{0: string, 1: string}|null $window
	 * @return array{variants: list<array<string, string|null>>, pages: list<array<string, string|null>>, serp: ?array<string, string|null>, candidate: ?array<string, string|null>, opportunities: list<array<string, string|null>>}
	 */
	public function projectEvidence(int $projectId, int $marketKeywordId, string $keywordHex, ?array $window): array
	{
		$variants = $window === null ? [] : $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN k.keyword, SUM(q.impressions) AS impressions, SUM(q.clicks) AS clicks, SUM(q.position_sum) AS position_sum
			FROM `{$this->db->table('keywords')}` k
			JOIN `{$this->db->table('gsc_query_daily')}` q ON q.project_id = k.project_id AND q.keyword_id = k.id AND q.date BETWEEN %s AND %s
			WHERE k.project_id = %d AND k.market_key = UNHEX(%s) GROUP BY k.id, k.keyword ORDER BY impressions DESC LIMIT 20",
			[$window[0], $window[1], $projectId, $keywordHex],
		);
		$pages = $window === null ? [] : $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN p.url, SUM(qp.impressions) AS impressions, SUM(qp.clicks) AS clicks
			FROM `{$this->db->table('keywords')}` k
			JOIN `{$this->db->table('gsc_query_page_daily')}` qp ON qp.project_id = k.project_id AND qp.keyword_id = k.id AND qp.date BETWEEN %s AND %s
			JOIN `{$this->db->table('pages')}` p ON p.id = qp.page_id
			WHERE k.project_id = %d AND k.market_key = UNHEX(%s) GROUP BY qp.page_id, p.url ORDER BY impressions DESC LIMIT 10",
			[$window[0], $window[1], $projectId, $keywordHex],
		);
		$serp = $this->db->fetchRow(
			"SELECT t.public_id, t.status, t.last_checked_at, t.last_found, t.last_rank, t.last_depth, t.last_snapshot_id, u.url AS last_url
			FROM `{$this->db->table('serp_tracked_keywords')}` t LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = t.last_url_id
			WHERE t.project_id = %d AND t.market_keyword_id = %d",
			[$projectId, $marketKeywordId],
		);
		$candidate = $this->db->fetchRow(
			"SELECT public_id, status, priority FROM `{$this->db->table('discovery_candidates')}` WHERE project_id = %d AND market_keyword_id = %d",
			[$projectId, $marketKeywordId],
		);
		$texts = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['keyword'], $variants)));
		$opportunities = $texts === [] ? [] : $this->db->fetchAll(
			"SELECT public_id, type, status, last_priority, page_url FROM `{$this->db->table('opportunities')}`
			WHERE project_id = %d AND state = 'active' AND keyword IN (" . Connection::placeholders($texts) . ') ORDER BY last_priority DESC LIMIT 10',
			[$projectId, ...$texts],
		);

		return ['variants' => $variants, 'pages' => $pages, 'serp' => $serp, 'candidate' => $candidate, 'opportunities' => $opportunities];
	}

	/**
	 * Pozycje SERP (nasz pomiar) hostów w migawce — dokładna pozycja konkurentów w szczegółach monitorowanej frazy.
	 *
	 * @return list<array{host: string, rank_group: int, url: string}>
	 */
	public function snapshotHosts(int $snapshotId): array
	{
		return array_map(static fn (array $row): array => ['host' => (string) $row['host'], 'rank_group' => (int) $row['rank_group'], 'url' => (string) $row['url']], $this->db->fetchAll(
			"SELECT d.host, r.rank_group, u.url FROM `{$this->db->table('serp_results')}` r
			JOIN `{$this->db->table('serp_domains')}` d ON d.id = r.domain_id
			JOIN `{$this->db->table('serp_urls')}` u ON u.id = r.url_id
			WHERE r.snapshot_id = %d AND r.result_type = 1 ORDER BY r.rank_group",
			[$snapshotId],
		));
	}

	/**
	 * Grupy fraz (luki treści).
	 *
	 * @return array{rows: list<array<string, string|null>>, total: int}
	 */
	public function clusters(int $projectId, ?string $content, string $status, string $q, string $sort, int $page, int $perPage = 50): array
	{
		$where = 'cl.project_id = %d AND cl.active = 1';
		$params = [$projectId];

		if ($content !== null) {
			$where .= ' AND cl.content_gap = %s';
			$params[] = $content;
		} else {
			$where .= " AND cl.content_gap IN ('new_page', 'improve', 'unclear')";
		}

		if ($status === '') {
			$where .= " AND cl.status <> 'dismissed'";
		} elseif ($status !== 'all') {
			$where .= ' AND cl.status = %s';
			$params[] = $status;
		}

		if ($q !== '') {
			$where .= ' AND cl.label LIKE %s';
			$params[] = '%' . $this->db->escapeLike($q) . '%';
		}

		$order = match ($sort) {
			'volume' => 'cl.gap_volume DESC',
			'keywords' => 'cl.keywords_count DESC',
			'competitor_rank' => 'cl.best_competitor_rank IS NULL, cl.best_competitor_rank ASC',
			default => 'cl.priority DESC',
		};
		$rows = $this->db->fetchAll(
			"SELECT cl.*, c.name AS competitor_name, u.url AS target_url
			FROM `{$this->db->table('gap_clusters')}` cl
			LEFT JOIN `{$this->db->table('serp_competitors')}` c ON c.id = cl.best_competitor_id
			LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = cl.target_url_id
			WHERE {$where} ORDER BY {$order}, cl.id ASC LIMIT %d OFFSET %d",
			[...$params, $perPage, max(0, ($page - 1) * $perPage)],
		);
		$total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$this->db->table('gap_clusters')}` cl WHERE {$where}", $params);

		return ['rows' => $rows, 'total' => $total];
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public function cluster(int $projectId, string $publicId): ?array
	{
		return $this->db->fetchRow(
			"SELECT cl.*, c.name AS competitor_name, u.url AS target_url
			FROM `{$this->db->table('gap_clusters')}` cl
			LEFT JOIN `{$this->db->table('serp_competitors')}` c ON c.id = cl.best_competitor_id
			LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = cl.target_url_id
			WHERE cl.project_id = %d AND cl.public_id = %s",
			[$projectId, $publicId],
		);
	}

	/**
	 * @return list<array<string, string|null>>
	 */
	public function clusterKeywords(int $projectId, int $clusterId, int $limit = 500): array
	{
		return $this->db->fetchAll(
			"SELECT g.public_id, g.market_keyword_id, g.gap_type, g.visibility, g.visibility_source, g.project_position, g.best_competitor_rank,
				g.competitors_count, g.search_volume, g.keyword_difficulty, g.intent, g.priority, g.status, m.keyword, c.name AS competitor_name
			FROM `{$this->table()}` g
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = g.market_keyword_id
			LEFT JOIN `{$this->db->table('serp_competitors')}` c ON c.id = g.best_competitor_id
			WHERE g.project_id = %d AND g.cluster_id = %d ORDER BY g.search_volume IS NULL, g.search_volume DESC, g.id LIMIT %d",
			[$projectId, $clusterId, max(1, $limit)],
		);
	}

	/**
	 * Adresy konkurentów rankujących na frazy grupy (liczba fraz, najlepsza pozycja).
	 *
	 * @param list<int> $domainIds
	 * @return list<array<string, string|null>>
	 */
	public function clusterUrls(int $projectId, int $clusterId, array $domainIds, int $maxRank): array
	{
		if ($domainIds === []) {
			return [];
		}

		return $this->db->fetchAll(
			"SELECT dk.domain_id, u.url, p.title, COUNT(*) AS keywords, MIN(dk.rank_group) AS best_rank
			FROM `{$this->table()}` g
			JOIN `{$this->db->table('gap_domain_keywords')}` dk ON dk.market_keyword_id = g.market_keyword_id
			JOIN `{$this->db->table('serp_urls')}` u ON u.id = dk.url_id
			LEFT JOIN `{$this->db->table('gap_domain_pages')}` p ON p.domain_id = dk.domain_id AND p.url_id = dk.url_id
			WHERE g.project_id = %d AND g.cluster_id = %d AND dk.domain_id IN (" . Connection::placeholders($domainIds, '%d') . ') AND dk.present = 1 AND dk.rank_group <= %d
			GROUP BY dk.domain_id, dk.url_id, u.url, p.title ORDER BY keywords DESC, best_rank ASC LIMIT 100',
			[$projectId, $clusterId, ...$domainIds, $maxRank],
		);
	}

	/**
	 * Strony konkurencji.
	 *
	 * @return array{rows: list<array<string, string|null>>, total: int}
	 */
	public function pages(int $projectId, ?int $competitorId, string $q, string $sort, int $page, int $perPage = 50): array
	{
		$where = 'p.project_id = %d';
		$params = [$projectId];

		if ($competitorId !== null) {
			$where .= ' AND p.competitor_id = %d';
			$params[] = $competitorId;
		}

		if ($q !== '') {
			$where .= ' AND (u.url LIKE %s OR p.title LIKE %s)';
			$like = '%' . $this->db->escapeLike($q) . '%';
			array_push($params, $like, $like);
		}

		$order = match ($sort) {
			'keywords' => 'p.keywords DESC',
			'volume' => 'p.total_volume DESC',
			'top10' => 'p.top10 DESC',
			default => 'p.gap_keywords DESC, p.gap_volume DESC',
		};
		$rows = $this->db->fetchAll(
			"SELECT p.*, LOWER(HEX(p.url_key)) AS url_key_hex, u.url, c.name AS competitor_name, c.public_id AS competitor_public_id, cl.public_id AS cluster_public_id, cl.label AS cluster_label
			FROM `{$this->db->table('gap_competitor_pages')}` p
			JOIN `{$this->db->table('serp_urls')}` u ON u.id = p.url_id
			JOIN `{$this->db->table('serp_competitors')}` c ON c.id = p.competitor_id
			LEFT JOIN `{$this->db->table('gap_clusters')}` cl ON cl.id = p.cluster_id
			WHERE {$where} ORDER BY {$order}, p.url_id ASC LIMIT %d OFFSET %d",
			[...$params, $perPage, max(0, ($page - 1) * $perPage)],
		);
		$total = (int) $this->db->fetchValue(
			"SELECT COUNT(*) FROM `{$this->db->table('gap_competitor_pages')}` p " . ($q === '' ? '' : "JOIN `{$this->db->table('serp_urls')}` u ON u.id = p.url_id ") . "WHERE {$where}",
			$params,
		);

		return ['rows' => $rows, 'total' => $total];
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public function page(int $projectId, int $competitorId, string $urlKeyHex): ?array
	{
		return $this->db->fetchRow(
			"SELECT p.*, LOWER(HEX(p.url_key)) AS url_key_hex, u.url, c.name AS competitor_name, c.public_id AS competitor_public_id
			FROM `{$this->db->table('gap_competitor_pages')}` p
			JOIN `{$this->db->table('serp_urls')}` u ON u.id = p.url_id
			JOIN `{$this->db->table('serp_competitors')}` c ON c.id = p.competitor_id
			WHERE p.project_id = %d AND p.url_key = UNHEX(%s) AND p.competitor_id = %d",
			[$projectId, $urlKeyHex, $competitorId],
		);
	}

	/**
	 * Frazy strony konkurenta z widocznością projektu (luka frazy, jeśli istnieje).
	 *
	 * @return list<array<string, string|null>>
	 */
	public function pageKeywords(int $projectId, int $domainId, int $urlId, int $limit = 500): array
	{
		return $this->db->fetchAll(
			"SELECT dk.rank_group, dk.serp_on, m.keyword, m.search_volume, m.keyword_difficulty, m.search_intent, g.public_id, g.gap_type, g.visibility,
				g.visibility_source, g.project_position, g.listed, g.filter_reason, g.priority
			FROM `{$this->db->table('gap_domain_keywords')}` dk
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = dk.market_keyword_id
			LEFT JOIN `{$this->table()}` g ON g.project_id = %d AND g.market_keyword_id = dk.market_keyword_id
			WHERE dk.domain_id = %d AND dk.url_id = %d AND dk.present = 1
			ORDER BY dk.rank_group ASC, m.search_volume IS NULL, m.search_volume DESC LIMIT %d",
			[$projectId, $domainId, $urlId, max(1, $limit)],
		);
	}

	/**
	 * Zmiana statusu pracy (luki fraz albo grupy) — tylko w obrębie projektu.
	 *
	 * @param list<string> $publicIds
	 */
	public function setStatus(int $projectId, string $kind, array $publicIds, GapStatus $status, ?string $note, int $userId): int
	{
		$publicIds = array_values(array_unique(array_filter($publicIds, static fn (string $id): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1)));

		if ($publicIds === []) {
			return 0;
		}

		$table = $kind === 'cluster' ? $this->db->table('gap_clusters') : $this->table();
		$changed = 0;

		foreach ($publicIds as $publicId) {
			$values = ['status' => $status->value, 'status_changed_at' => $this->clock->now()->format('Y-m-d H:i:s'), 'status_changed_by' => $userId > 0 ? $userId : null];

			if ($note !== null) {
				$values['note'] = $note === '' ? null : mb_substr($note, 0, 2000);
			}

			$changed += $this->db->update($table, $values, ['project_id' => $projectId, 'public_id' => $publicId]);
		}

		return $changed;
	}

	/**
	 * @return array{0: string, 1: list<int|string>}
	 */
	private function where(int $projectId, GapFilters $filters, ?int $competitorDatasetId, int $competitorMaxRank): array
	{
		$where = 'g.project_id = %d AND g.active = 1 AND g.listed = %d';
		$params = [$projectId, $filters->filtered ? 0 : 1];
		$types = $filters->types();

		if ($types !== null) {
			$where .= ' AND g.gap_type IN (' . Connection::placeholders($types) . ')';
			array_push($params, ...$types);
		}

		if ($filters->status === '') {
			$where .= " AND g.status <> 'dismissed'";
		} elseif ($filters->status !== 'all') {
			$where .= ' AND g.status = %s';
			$params[] = $filters->status;
		}

		foreach (['content' => 'g.content_gap', 'visibility' => 'g.visibility', 'intent' => 'g.intent'] as $field => $column) {
			if ($filters->{$field} !== null) {
				$where .= " AND {$column} = %s";
				$params[] = $filters->{$field};
			}
		}

		if ($filters->minVolume !== null) {
			$where .= ' AND g.search_volume >= %d';
			$params[] = $filters->minVolume;
		}

		if ($filters->maxDifficulty !== null) {
			$where .= ' AND g.keyword_difficulty <= %d';
			$params[] = $filters->maxDifficulty;
		}

		if ($filters->minPriority !== null) {
			$where .= ' AND g.priority >= %d';
			$params[] = $filters->minPriority;
		}

		if ($filters->competitor !== null) {
			// Konkurent bez zaimportowanego zbioru (albo spoza projektu) — pusta lista, nie wszystkie frazy.
			$where .= $competitorDatasetId === null
				? ' AND 1 = 0'
				: " AND EXISTS (SELECT 1 FROM `{$this->db->table('gap_domain_keywords')}` dk WHERE dk.domain_id = %d AND dk.market_keyword_id = g.market_keyword_id AND dk.present = 1 AND dk.rank_group <= %d)";

			if ($competitorDatasetId !== null) {
				array_push($params, $competitorDatasetId, $competitorMaxRank);
			}
		}

		if ($filters->q !== '') {
			$where .= ' AND m.keyword LIKE %s';
			$params[] = '%' . $this->db->escapeLike($filters->q) . '%';
		}

		return [$where, $params];
	}

	private function table(): string
	{
		return $this->db->table('gap_keywords');
	}
}
