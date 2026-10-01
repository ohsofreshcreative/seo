<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketMetrics;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Kandydaci (`discovery_candidates`) i ich źródła (`discovery_candidate_sources`).
 *
 * Kandydat = projekt × fraza rynkowa (`UNIQUE(project_id, market_keyword_id)`): ta sama fraza znaleziona z kilku seedów
 * lub w kolejnych przebiegach to jeden kandydat z wieloma źródłami — stan pracy (status, notatka) nigdy nie jest
 * duplikowany ani nadpisywany przez wyszukiwanie. Metryki rynkowe czytamy z `market_keywords` (bez kopiowania).
 * Kandydata z URL-a szukamy zawsze po (project_id, public_id).
 */
final class DiscoveryCandidateRepository
{
	public const NOTE_MAX_LENGTH = 2000;

	private const CHUNK = 500;

	private const SELECT = 'c.id, c.public_id, c.status, c.note, c.seeds_count, c.best_relation, c.visibility, c.gsc_impressions, c.gsc_clicks,
		c.gsc_position, c.target_url, c.priority, c.score, c.excluded, c.provider_meta, c.discovered_at, c.last_seen_at, c.status_changed_at,
		c.status_changed_by, m.search_intent AS m_search_intent, m.intent_fetched_at AS m_intent_fetched_at';

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Zapis wyników jednego żądania: nowi kandydaci (najwyżej $allowNew — limit przebiegu) i odświeżenie istniejących,
	 * źródło fraza × seed × metoda (upsert). Kolejność wpisów = kolejność dostawcy (wolumen malejąco).
	 *
	 * @param list<array{market_id: int, relation: int, depth: ?int, position: ?int, meta: array<string, mixed>}> $entries
	 * @return array{new: int, seen: int, limited: int}
	 */
	public function upsertBatch(int $projectId, int $runId, DiscoveryMethod $method, string $seed, array $entries, int $allowNew): array
	{
		if ($entries === []) {
			return ['new' => 0, 'seen' => 0, 'limited' => 0];
		}

		$now = $this->now();
		$byMarket = [];

		foreach ($entries as $entry) {
			$byMarket[$entry['market_id']] ??= $entry;
		}

		$existing = $this->idsByMarket($projectId, array_keys($byMarket));
		$new = 0;
		$limited = 0;
		$insert = new BulkInsert(
			$this->db,
			$this->table(),
			['public_id', 'project_id', 'market_keyword_id', 'status', 'seeds_count', 'best_relation', 'visibility', 'provider_meta',
				'first_run_id', 'last_run_id', 'discovered_at', 'last_seen_at', 'created_at', 'updated_at'],
			['%s', '%d', '%d', '%s', '%d', '%d', '%s', "NULLIF(%s, '')", '%d', '%d', '%s', '%s', '%s', '%s'],
			'ON DUPLICATE KEY UPDATE last_run_id = VALUES(last_run_id), last_seen_at = VALUES(last_seen_at)',
		);

		foreach ($byMarket as $marketId => $entry) {
			if (isset($existing[$marketId])) {
				continue;
			}

			if ($new >= $allowNew) {
				$limited++;
				unset($byMarket[$marketId]);

				continue;
			}

			$insert->add([
				Ulid::generate($this->clock->now()),
				$projectId,
				$marketId,
				CandidateStatus::New->value,
				1,
				$entry['relation'],
				Visibility::Unknown->value,
				$entry['meta'] === [] ? '' : (string) json_encode($entry['meta'], JSON_UNESCAPED_UNICODE),
				$runId,
				$runId,
				$now,
				$now,
				$now,
				$now,
			]);
			$new++;
		}

		$insert->flush();
		$seenIds = array_values(array_intersect_key($existing, $byMarket));

		foreach (array_chunk($seenIds, self::CHUNK) as $chunk) {
			$this->db->execute(
				"UPDATE `{$this->table()}` SET last_run_id = %d, last_seen_at = %s WHERE project_id = %d AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$runId, $now, $projectId, ...$chunk],
			);
		}

		$ids = $this->idsByMarket($projectId, array_keys($byMarket));
		$sources = new BulkInsert(
			$this->db,
			$this->sourcesTable(),
			['candidate_id', 'seed_key', 'method', 'seed', 'run_id', 'relation', 'depth', 'result_position', 'first_seen_at', 'last_seen_at'],
			['%d', 'UNHEX(%s)', '%s', '%s', '%d', '%d', "NULLIF(%s, '')", "NULLIF(%s, '')", '%s', '%s'],
			'ON DUPLICATE KEY UPDATE run_id = VALUES(run_id), relation = VALUES(relation), depth = VALUES(depth),
				result_position = VALUES(result_position), last_seen_at = VALUES(last_seen_at)',
		);
		$seedHex = bin2hex(\OsfSeo\Market\MarketKeyword::key($seed));

		foreach ($byMarket as $marketId => $entry) {
			if (! isset($ids[$marketId])) {
				continue;
			}

			$sources->add([
				$ids[$marketId],
				$seedHex,
				$method->value,
				mb_substr($seed, 0, 255, 'UTF-8'),
				$runId,
				$entry['relation'],
				$entry['depth'] === null ? '' : (string) $entry['depth'],
				$entry['position'] === null ? '' : (string) min(65535, $entry['position']),
				$now,
				$now,
			]);
		}

		$sources->flush();

		return ['new' => $new, 'seen' => count($seenIds), 'limited' => $limited];
	}

	/**
	 * Lista kandydatów rynku projektu z filtrami (SQL), sortowaniem z białej listy i paginacją.
	 */
	public function list(int $projectId, Market $market, CandidateFilters $filters): CandidatePage
	{
		[$where, $params] = $this->where($projectId, $market, $filters);
		$order = self::orderBy($filters);
		$params[] = $filters->perPage;
		$params[] = ($filters->page - 1) * $filters->perPage;

		$rows = $this->db->fetchAll(
			'SELECT STRAIGHT_JOIN ' . self::SELECT . ', ' . MarketMetrics::columns('m', 'm_') . ", COUNT(*) OVER () AS total_rows
			FROM `{$this->table()}` c
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = c.market_keyword_id
			WHERE {$where}
			ORDER BY {$order}
			LIMIT %d OFFSET %d",
			$params,
		);

		$total = $rows === [] ? 0 : (int) $rows[0]['total_rows'];

		if ($rows === [] && $filters->page > 1) {
			$total = (int) $this->db->fetchValue(
				"SELECT COUNT(*) FROM `{$this->table()}` c JOIN `{$this->db->table('market_keywords')}` m ON m.id = c.market_keyword_id WHERE {$where}",
				array_slice($params, 0, -2),
			);
		}

		return new CandidatePage($filters, array_map(static fn (array $row): CandidateRow => CandidateRow::fromRow($row), $rows), $total);
	}

	/** Kandydat projektu po publicznym ID (z rynkiem dowolnym — także po zmianie rynku projektu) wraz ze źródłami. */
	public function find(int $projectId, string $publicId): ?CandidateRow
	{
		$normalized = Ulid::normalize($publicId);

		if ($normalized === null) {
			return null;
		}

		$row = $this->db->fetchRow(
			'SELECT ' . self::SELECT . ', ' . MarketMetrics::columns('m', 'm_') . "
			FROM `{$this->table()}` c
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = c.market_keyword_id
			WHERE c.project_id = %d AND c.public_id = %s",
			[$projectId, $normalized],
		);

		if ($row === null) {
			return null;
		}

		$candidate = CandidateRow::fromRow($row);
		$candidate->sources = $this->sources($candidate->id);

		return $candidate;
	}

	/**
	 * Źródła kandydata: seed, metoda, siła powiązania, głębokość, pozycja u dostawcy, przebieg.
	 *
	 * @return list<array{seed: string, method: string, relation: int, depth: ?int, position: ?int, run: string, first_seen_at: string, last_seen_at: string}>
	 */
	public function sources(int $candidateId): array
	{
		return array_map(static fn (array $row): array => [
			'seed' => (string) $row['seed'],
			'method' => (string) $row['method'],
			'relation' => (int) $row['relation'],
			'depth' => $row['depth'] === null ? null : (int) $row['depth'],
			'position' => $row['result_position'] === null ? null : (int) $row['result_position'],
			'run' => (string) ($row['run_public_id'] ?? ''),
			'first_seen_at' => (string) $row['first_seen_at'],
			'last_seen_at' => (string) $row['last_seen_at'],
		], $this->db->fetchAll(
			"SELECT s.seed, s.method, s.relation, s.depth, s.result_position, s.first_seen_at, s.last_seen_at, r.public_id AS run_public_id
			FROM `{$this->sourcesTable()}` s
			LEFT JOIN `{$this->db->table('discovery_runs')}` r ON r.id = s.run_id
			WHERE s.candidate_id = %d
			ORDER BY s.relation DESC, s.first_seen_at, s.seed",
			[$candidateId],
		));
	}

	/**
	 * @param array<string, int|string|null> $fields status, note, status_changed_at, status_changed_by
	 */
	public function updateWorkflow(int $projectId, int $candidateId, array $fields): void
	{
		$allowed = array_intersect_key($fields, array_flip(['status', 'note', 'status_changed_at', 'status_changed_by']));
		$allowed['updated_at'] = $this->now();

		$this->db->update($this->table(), $allowed, ['id' => $candidateId, 'project_id' => $projectId]);
	}

	/**
	 * Zmiana statusu wielu kandydatów projektu (akcja zbiorcza) — identyfikatory spoza projektu są pomijane.
	 *
	 * @param list<string> $publicIds
	 */
	public function bulkStatus(int $projectId, array $publicIds, CandidateStatus $status, ?int $userId): int
	{
		$ids = array_values(array_unique(array_filter(array_map(static fn (string $id): ?string => Ulid::normalize($id), $publicIds))));

		if ($ids === []) {
			return 0;
		}

		$now = $this->now();

		return $this->db->execute(
			"UPDATE `{$this->table()}` SET status = %s, status_changed_at = %s, status_changed_by = NULLIF(%d, 0), updated_at = %s
			WHERE project_id = %d AND status <> %s AND public_id IN (" . Connection::placeholders($ids) . ')',
			[$status->value, $now, max(0, (int) $userId), $now, $projectId, $status->value, ...$ids],
		);
	}

	/**
	 * Liczby kandydatów rynku projektu wg statusu i widoczności (zakładki listy; bez wykluczonych).
	 *
	 * @return array{statuses: array<string, int>, visibility: array<string, int>, total: int, excluded: int}
	 */
	public function summary(int $projectId, Market $market): array
	{
		$result = ['statuses' => array_fill_keys(CandidateStatus::values(), 0), 'visibility' => array_fill_keys(Visibility::values(), 0), 'total' => 0, 'excluded' => 0];

		foreach ($this->db->fetchAll(
			"SELECT c.status, c.visibility, c.excluded, COUNT(*) AS n
			FROM `{$this->table()}` c JOIN `{$this->db->table('market_keywords')}` m ON m.id = c.market_keyword_id
			WHERE c.project_id = %d AND m.provider = %s AND m.location_code = %d AND m.language_code = %s
			GROUP BY c.status, c.visibility, c.excluded",
			[$projectId, $market->provider, $market->locationCode, $market->languageCode],
		) as $row) {
			$count = (int) $row['n'];

			if ((int) $row['excluded'] === 1) {
				$result['excluded'] += $count;

				continue;
			}

			$result['statuses'][(string) $row['status']] = ($result['statuses'][(string) $row['status']] ?? 0) + $count;
			$result['visibility'][(string) $row['visibility']] = ($result['visibility'][(string) $row['visibility']] ?? 0) + $count;
			$result['total'] += $count;
		}

		return $result;
	}

	/**
	 * Dane do przeliczenia widoczności i priorytetu (kandydaci rynku projektu z metrykami rynkowymi).
	 *
	 * @return list<array<string, string|null>>
	 */
	public function scoringRows(int $projectId, Market $market): array
	{
		return $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN c.id, c.visibility, c.gsc_impressions, c.gsc_clicks, c.gsc_position, c.target_url, c.priority, c.score, c.excluded,
				c.seeds_count, c.best_relation, m.keyword, LOWER(HEX(m.keyword_key)) AS keyword_hex, m.search_volume, m.keyword_difficulty, m.cpc
			FROM `{$this->table()}` c
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = c.market_keyword_id
			WHERE c.project_id = %d AND m.provider = %s AND m.location_code = %d AND m.language_code = %s",
			[$projectId, $market->provider, $market->locationCode, $market->languageCode],
		);
	}

	/**
	 * Liczba seedów (różnych) i najsilniejsze powiązanie każdego kandydata projektu.
	 *
	 * @return array<int, array{seeds: int, best: int}>
	 */
	public function sourceStats(int $projectId): array
	{
		$stats = [];

		foreach ($this->db->fetchAll(
			"SELECT s.candidate_id, COUNT(DISTINCT s.seed_key) AS seeds, MAX(s.relation) AS best
			FROM `{$this->table()}` c JOIN `{$this->sourcesTable()}` s ON s.candidate_id = c.id
			WHERE c.project_id = %d GROUP BY s.candidate_id",
			[$projectId],
		) as $row) {
			$stats[(int) $row['candidate_id']] = ['seeds' => (int) $row['seeds'], 'best' => (int) $row['best']];
		}

		return $stats;
	}

	/**
	 * Zapis przeliczenia (tylko zmienione wiersze; paczki UPDATE … CASE — bez zapytania na kandydata).
	 *
	 * @param array<int, array{visibility: string, gsc_impressions: ?int, gsc_clicks: ?int, gsc_position: ?float, target_url: ?string, priority: int, score: string, excluded: int, seeds_count: int, best_relation: int}> $updates
	 */
	public function saveScores(int $projectId, array $updates): int
	{
		$now = $this->now();
		$saved = 0;
		$columns = ['visibility' => '%s', 'gsc_impressions' => '%s', 'gsc_clicks' => '%s', 'gsc_position' => '%s', 'target_url' => '%s', 'priority' => '%d',
			'score' => '%s', 'excluded' => '%d', 'seeds_count' => '%d', 'best_relation' => '%d'];

		foreach (array_chunk($updates, 200, true) as $chunk) {
			$sets = [];
			$params = [];

			foreach ($columns as $column => $placeholder) {
				$cases = [];
				$nullable = in_array($column, ['gsc_impressions', 'gsc_clicks', 'gsc_position', 'target_url'], true);

				foreach ($chunk as $id => $values) {
					$cases[] = 'WHEN %d THEN ' . ($nullable ? "NULLIF({$placeholder}, '')" : $placeholder);
					$value = $values[$column];
					array_push($params, $id, $nullable && $value === null ? '' : ($column === 'gsc_position' && $value !== null ? sprintf('%.2F', $value) : $value));
				}

				$sets[] = "`{$column}` = CASE id " . implode(' ', $cases) . ' END';
			}

			$ids = array_keys($chunk);
			$saved += $this->db->execute(
				"UPDATE `{$this->table()}` SET " . implode(', ', $sets) . ', scored_at = %s
				WHERE project_id = %d AND id IN (' . Connection::placeholders($ids, '%d') . ')',
				[...$params, $now, $projectId, ...$ids],
			);
		}

		return $saved;
	}

	/**
	 * Reset property (dane GSC projektu usunięte): kandydaci i stan pracy zostają, widoczność GSC wymaga ponownej oceny.
	 */
	public function markVisibilityUnknown(int $projectId): int
	{
		return $this->db->execute(
			"UPDATE `{$this->table()}` SET visibility = 'unknown', gsc_impressions = NULL, gsc_clicks = NULL, gsc_position = NULL, target_url = NULL, updated_at = %s
			WHERE project_id = %d",
			[$this->now(), $projectId],
		);
	}

	/** Odcisk zawartości kandydatów rynku (zmienia się po nowych wynikach lub odświeżeniu metryk) — do klucza przeliczenia. */
	public function fingerprint(int $projectId, Market $market): string
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS n, MAX(c.last_seen_at) AS seen, MAX(m.updated_at) AS metrics, (SELECT COUNT(*) FROM `{$this->sourcesTable()}` s JOIN `{$this->table()}` c2 ON c2.id = s.candidate_id WHERE c2.project_id = %d) AS sources
			FROM `{$this->table()}` c JOIN `{$this->db->table('market_keywords')}` m ON m.id = c.market_keyword_id
			WHERE c.project_id = %d AND m.provider = %s AND m.location_code = %d AND m.language_code = %s",
			[$projectId, $projectId, $market->provider, $market->locationCode, $market->languageCode],
		) ?? [];

		return implode('|', [(string) ($row['n'] ?? 0), (string) ($row['seen'] ?? ''), (string) ($row['metrics'] ?? ''), (string) ($row['sources'] ?? 0)]);
	}

	/**
	 * Projekty z kandydatami (przeliczanie w tle).
	 *
	 * @return list<int>
	 */
	public function projectIds(): array
	{
		return array_map('intval', array_column($this->db->fetchAll("SELECT DISTINCT project_id FROM `{$this->table()}`"), 'project_id'));
	}

	/**
	 * @return array{0: string, 1: list<int|string>}
	 */
	private function where(int $projectId, Market $market, CandidateFilters $filters): array
	{
		$where = ['c.project_id = %d', 'm.provider = %s', 'm.location_code = %d', 'm.language_code = %s'];
		$params = [$projectId, $market->provider, $market->locationCode, $market->languageCode];

		$where[] = 'c.excluded = %d';
		$params[] = $filters->excluded ? 1 : 0;

		if ($filters->status === 'open') {
			$where[] = "c.status IN ('new', 'review')";
		} elseif ($filters->status !== 'all') {
			$where[] = 'c.status = %s';
			$params[] = $filters->status;
		}

		if ($filters->visibility === 'gap') {
			$where[] = "c.visibility IN ('none', 'low', 'unknown')";
		} elseif ($filters->visibility !== 'all') {
			$where[] = 'c.visibility = %s';
			$params[] = $filters->visibility;
		}

		if ($filters->q !== '') {
			$where[] = 'm.keyword LIKE %s';
			$params[] = '%' . $this->db->escapeLike(mb_strtolower($filters->q, 'UTF-8')) . '%';
		}

		if ($filters->minVolume !== null) {
			$where[] = 'm.search_volume >= %d';
			$params[] = $filters->minVolume;
		}

		if ($filters->maxDifficulty !== null) {
			$where[] = 'm.keyword_difficulty <= %d';
			$params[] = $filters->maxDifficulty;
		}

		if ($filters->minPriority !== null) {
			$where[] = 'c.priority >= %d';
			$params[] = $filters->minPriority;
		}

		if ($filters->intent !== null) {
			$where[] = 'm.search_intent = %s';
			$params[] = $filters->intent;
		}

		return [implode(' AND ', $where), $params];
	}

	private static function orderBy(CandidateFilters $filters): string
	{
		$direction = $filters->direction === 'asc' ? 'ASC' : 'DESC';

		return match ($filters->sort) {
			'volume' => "m.search_volume IS NULL, m.search_volume {$direction}, c.id DESC",
			'difficulty' => "m.keyword_difficulty IS NULL, m.keyword_difficulty {$direction}, c.id DESC",
			'position' => "c.gsc_position IS NULL, c.gsc_position {$direction}, c.id DESC",
			'discovered' => "c.discovered_at {$direction}, c.id {$direction}",
			'keyword' => "m.keyword {$direction}, c.id",
			default => "c.priority IS NULL, c.priority {$direction}, c.id DESC",
		};
	}

	/**
	 * @param list<int> $marketIds
	 * @return array<int, int> id frazy rynkowej → id kandydata
	 */
	private function idsByMarket(int $projectId, array $marketIds): array
	{
		$ids = [];

		foreach (array_chunk($marketIds, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, market_keyword_id FROM `{$this->table()}` WHERE project_id = %d AND market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$projectId, ...$chunk],
			) as $row) {
				$ids[(int) $row['market_keyword_id']] = (int) $row['id'];
			}
		}

		return $ids;
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	private function table(): string
	{
		return $this->db->table('discovery_candidates');
	}

	private function sourcesTable(): string
	{
		return $this->db->table('discovery_candidate_sources');
	}
}
