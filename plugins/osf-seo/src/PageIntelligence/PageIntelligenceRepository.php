<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;
use RuntimeException;

/**
 * Dane Page Intelligence (`page_targets`, `page_snapshots`, `page_fetches`, `page_serp_links`). Każdy odczyt i zmiana po identyfikatorze
 * publicznym są zawężone do projektu (brak IDOR przez identyfikator strony albo snapshotu). Wyjątek: limity uprzejmości wobec hosta
 * (`hostActivity`) liczą próby wszystkich projektów — wyłącznie czasy i liczniki, bez treści.
 */
final class PageIntelligenceRepository
{
	private const BINARY = ['url_key', 'body_hash', 'content_hash', 'request_key'];

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	// Strony

	public static function urlKey(string $url): string
	{
		return hash('sha256', $url);
	}

	/**
	 * Strona projektu dla adresu (tworzona przy pierwszym zleceniu). Rodzaj wynika z adresu — istniejąca strona go nie zmienia.
	 */
	public function ensureTarget(int $projectId, string $url, string $host, string $kind, string $source, ?int $topicId, ?int $userId): PageTarget
	{
		$existing = $this->targetByUrl($projectId, $url);

		if ($existing !== null) {
			if ($topicId !== null && $existing->topicId === null) {
				$this->db->update($this->table('page_targets'), ['topic_id' => $topicId, 'updated_at' => $this->now()], ['id' => $existing->id]);

				return $this->targetByUrl($projectId, $url) ?? $existing;
			}

			return $existing;
		}

		$now = $this->now();
		$this->insertRow($this->table('page_targets'), [
			'public_id' => Ulid::generate($this->clock->now()),
			'project_id' => $projectId,
			'url_key' => self::urlKey($url),
			'url' => $url,
			'host' => $host,
			'kind' => $kind,
			'source' => $source,
			'topic_id' => $topicId,
			'status' => PageTarget::STATUS_NEW,
			'created_by' => $userId,
			'created_at' => $now,
			'updated_at' => $now,
		], true);

		return $this->targetByUrl($projectId, $url) ?? throw new RuntimeException('Page target was not stored.');
	}

	public function targetByUrl(int $projectId, string $url): ?PageTarget
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table('page_targets')}` WHERE project_id = %d AND url_key = UNHEX(%s)", [$projectId, self::urlKey($url)]);

		return $row === null ? null : PageTarget::fromRow($row);
	}

	public function target(int $projectId, string $publicId): ?PageTarget
	{
		$publicId = Ulid::normalize($publicId);

		if ($publicId === null) {
			return null;
		}

		$row = $this->db->fetchRow("SELECT * FROM `{$this->table('page_targets')}` WHERE project_id = %d AND public_id = %s", [$projectId, $publicId]);

		return $row === null ? null : PageTarget::fromRow($row);
	}

	/**
	 * @return list<PageTarget>
	 */
	public function targets(int $projectId, ?string $kind = null, int $limit = 50, int $offset = 0): array
	{
		$sql = "SELECT * FROM `{$this->table('page_targets')}` WHERE project_id = %d";
		$params = [$projectId];

		if ($kind !== null) {
			$sql .= ' AND kind = %s';
			$params[] = $kind;
		}

		$params[] = max(1, min(500, $limit));
		$params[] = max(0, $offset);

		return array_map(PageTarget::fromRow(...), $this->db->fetchAll($sql . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d', $params));
	}

	/**
	 * Wynik próby na stronie (bez dotykania snapshotów; poprawny snapshot tylko przy sukcesie).
	 */
	public function recordAttempt(PageTarget $target, string $status, ?string $error, ?int $httpStatus, ?string $finalUrl, ?int $snapshotId): void
	{
		$now = $this->now();
		$data = [
			'status' => $status,
			'last_attempt_at' => $now,
			'last_error' => $error,
			'last_http_status' => $httpStatus,
			'updated_at' => $now,
		];

		if ($snapshotId !== null) {
			$data += ['last_success_at' => $now, 'last_snapshot_id' => $snapshotId, 'final_url' => $finalUrl];
		}

		$this->db->update($this->table('page_targets'), $data, ['id' => $target->id, 'project_id' => $target->projectId]);
	}

	/**
	 * Usunięcie strony z jej snapshotami, próbami i powiązaniami SERP (wyłącznie w obrębie projektu).
	 */
	public function deleteTarget(PageTarget $target): void
	{
		$this->db->transaction(function () use ($target): void {
			foreach (['page_snapshots', 'page_fetches', 'page_serp_links'] as $table) {
				$this->db->execute("DELETE FROM `{$this->table($table)}` WHERE project_id = %d AND target_id = %d", [$target->projectId, $target->id]);
			}

			$this->db->execute("DELETE FROM `{$this->table('page_targets')}` WHERE project_id = %d AND id = %d", [$target->projectId, $target->id]);
		});
	}

	// Powiązania z wynikami SERP

	public function linkSerp(PageTarget $target, int $serpSnapshotId, int $marketKeywordId, int $rank, string $checkedAt): void
	{
		$this->db->execute(
			"INSERT INTO `{$this->table('page_serp_links')}` (target_id, serp_snapshot_id, project_id, market_keyword_id, rank_group, serp_checked_at, created_at)
			VALUES (%d, %d, %d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE rank_group = VALUES(rank_group)",
			[$target->id, $serpSnapshotId, $target->projectId, $marketKeywordId, $rank, $checkedAt, $this->now()],
		);
	}

	/**
	 * Powiązania stron z wynikami SERP (najnowszy pomiar na stronę i frazę).
	 *
	 * @param list<int> $targetIds
	 * @return array<int, list<array{keyword: ?string, rank_group: int, serp_checked_at: string, serp_snapshot: ?string}>>
	 */
	public function serpLinks(int $projectId, array $targetIds): array
	{
		if ($targetIds === []) {
			return [];
		}

		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT l.target_id, l.market_keyword_id, m.keyword, l.rank_group, l.serp_checked_at, s.public_id AS serp_snapshot
			FROM `{$this->table('page_serp_links')}` l
			LEFT JOIN `{$this->db->table('market_keywords')}` m ON m.id = l.market_keyword_id
			LEFT JOIN `{$this->db->table('serp_snapshots')}` s ON s.id = l.serp_snapshot_id AND s.project_id = l.project_id
			WHERE l.project_id = %d AND l.target_id IN (" . Connection::placeholders($targetIds, '%d') . ') ORDER BY l.serp_checked_at DESC, l.rank_group',
			[$projectId, ...$targetIds],
		) as $row) {
			$result[(int) $row['target_id']][] = [
				'keyword' => $row['keyword'],
				'rank_group' => (int) $row['rank_group'],
				'serp_checked_at' => (string) $row['serp_checked_at'],
				'serp_snapshot' => $row['serp_snapshot'],
			];
		}

		return $result;
	}

	/**
	 * Strony konkurencji powiązane z wynikami SERP fraz (kontekst AI tematu) — najlepsza pozycja, najnowszy pomiar.
	 *
	 * @param list<int> $marketKeywordIds
	 * @return list<array{target: PageTarget, market_keyword_id: int, rank_group: int, serp_checked_at: string}>
	 */
	public function competitorPagesForKeywords(int $projectId, array $marketKeywordIds, int $limit): array
	{
		if ($marketKeywordIds === []) {
			return [];
		}

		$rows = $this->db->fetchAll(
			"SELECT t.*, l.market_keyword_id AS link_keyword, l.rank_group AS link_rank, l.serp_checked_at AS link_checked
			FROM `{$this->table('page_serp_links')}` l
			JOIN `{$this->table('page_targets')}` t ON t.id = l.target_id AND t.project_id = l.project_id
			WHERE l.project_id = %d AND t.kind = %s AND l.market_keyword_id IN (" . Connection::placeholders($marketKeywordIds, '%d') . ')
			ORDER BY l.serp_checked_at DESC, l.rank_group, t.id',
			[$projectId, PageTarget::KIND_COMPETITOR, ...$marketKeywordIds],
		);
		$result = [];

		foreach ($rows as $row) {
			$id = (int) $row['id'];

			if (isset($result[$id])) {
				continue;
			}

			$result[$id] = ['target' => PageTarget::fromRow($row), 'market_keyword_id' => (int) $row['link_keyword'], 'rank_group' => (int) $row['link_rank'], 'serp_checked_at' => (string) $row['link_checked']];
		}

		$result = array_values($result);
		usort($result, static fn (array $a, array $b): int => [$a['rank_group'], $a['target']->id] <=> [$b['rank_group'], $b['target']->id]);

		return array_slice($result, 0, $limit);
	}

	/** Wewnętrzny identyfikator pomiaru SERP projektu po identyfikatorze publicznym. */
	public function serpSnapshotId(int $projectId, string $publicId): ?int
	{
		$value = $this->db->fetchValue("SELECT id FROM `{$this->db->table('serp_snapshots')}` WHERE project_id = %d AND public_id = %s", [$projectId, $publicId]);

		return $value === null ? null : (int) $value;
	}

	/**
	 * Organiczny wynik zapisanego pomiaru SERP projektu o tym adresie (ręcznie podany adres konkurenta) — najnowszy pomiar.
	 *
	 * @param list<string> $urls warianty adresu (dokładny skrót słownika `serp_urls`)
	 * @return array{serp_snapshot_id: int, market_keyword_id: int, rank_group: int, checked_at: string}|null
	 */
	public function serpResultForUrl(int $projectId, array $urls): ?array
	{
		$hashes = array_values(array_unique(array_map('md5', $urls)));
		$row = $this->db->fetchRow(
			"SELECT s.id, s.market_keyword_id, r.rank_group, s.checked_at FROM `{$this->db->table('serp_urls')}` u
			JOIN `{$this->db->table('serp_results')}` r ON r.url_id = u.id AND r.result_type = 1
			JOIN `{$this->db->table('serp_snapshots')}` s ON s.id = r.snapshot_id
			WHERE u.url_hash IN (" . Connection::placeholders($hashes, 'UNHEX(%s)') . ") AND s.project_id = %d AND s.status = 'completed'
			ORDER BY s.checked_at DESC, r.rank_group LIMIT 1",
			[...$hashes, $projectId],
		);

		return $row === null ? null : ['serp_snapshot_id' => (int) $row['id'], 'market_keyword_id' => (int) $row['market_keyword_id'], 'rank_group' => (int) $row['rank_group'], 'checked_at' => (string) $row['checked_at']];
	}

	// Snapshoty

	public function snapshotById(int $projectId, int $id): ?PageSnapshotRecord
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table('page_snapshots')}` WHERE project_id = %d AND id = %d", [$projectId, $id]);

		return $row === null ? null : PageSnapshotRecord::fromRow($row);
	}

	public function snapshot(int $projectId, string $publicId): ?PageSnapshotRecord
	{
		$publicId = Ulid::normalize($publicId);

		if ($publicId === null) {
			return null;
		}

		$row = $this->db->fetchRow("SELECT * FROM `{$this->table('page_snapshots')}` WHERE project_id = %d AND public_id = %s", [$projectId, $publicId]);

		return $row === null ? null : PageSnapshotRecord::fromRow($row);
	}

	public function latestSnapshot(PageTarget $target): ?PageSnapshotRecord
	{
		$row = $this->db->fetchRow(
			"SELECT * FROM `{$this->table('page_snapshots')}` WHERE project_id = %d AND target_id = %d ORDER BY last_seen_at DESC, id DESC LIMIT 1",
			[$target->projectId, $target->id],
		);

		return $row === null ? null : PageSnapshotRecord::fromRow($row);
	}

	/**
	 * Snapshoty strony bez danych treści (lista).
	 *
	 * @return list<PageSnapshotRecord>
	 */
	public function snapshots(PageTarget $target, int $limit = 20): array
	{
		return array_map(PageSnapshotRecord::fromRow(...), $this->db->fetchAll(
			"SELECT id, public_id, project_id, target_id, extractor_version, fetched_at, last_seen_at, http_status, content_type, charset, final_url, bytes, fetch_ms,
				body_hash, content_hash, etag, last_modified, title, word_count, content_quality, indexability, canonical_status, '{}' AS data
			FROM `{$this->table('page_snapshots')}` WHERE project_id = %d AND target_id = %d ORDER BY fetched_at DESC, id DESC LIMIT %d",
			[$target->projectId, $target->id, max(1, min(100, $limit))],
		));
	}

	/**
	 * @param array<string, int|float|string|null> $columns kolumny snapshotu (odciski jako hex)
	 */
	public function createSnapshot(PageTarget $target, array $columns): PageSnapshotRecord
	{
		$publicId = Ulid::generate($this->clock->now());
		$this->insertRow($this->table('page_snapshots'), ['public_id' => $publicId, 'project_id' => $target->projectId, 'target_id' => $target->id] + $columns);

		return $this->snapshot($target->projectId, $publicId) ?? throw new RuntimeException('Page snapshot was not stored.');
	}

	/** Ta sama treść pobrana ponownie — tylko data ostatniego potwierdzenia (bez kopii treści). */
	public function touchSnapshot(PageSnapshotRecord $snapshot, ?string $etag, ?string $lastModified): void
	{
		$this->db->update($this->table('page_snapshots'), array_filter([
			'last_seen_at' => $this->now(),
			'etag' => $etag,
			'last_modified' => $lastModified,
		], static fn (mixed $value): bool => $value !== null), ['id' => $snapshot->id, 'project_id' => $snapshot->projectId]);
	}

	/** Najwięcej `$keep` snapshotów strony (najstarsze usuwane; ostatni poprawny zostaje). */
	public function pruneSnapshots(PageTarget $target, int $keep): int
	{
		$ids = array_map('intval', array_column($this->db->fetchAll(
			"SELECT id FROM `{$this->table('page_snapshots')}` WHERE project_id = %d AND target_id = %d ORDER BY last_seen_at DESC, id DESC LIMIT 1000 OFFSET %d",
			[$target->projectId, $target->id, max(1, $keep)],
		), 'id'));

		if ($ids === []) {
			return 0;
		}

		return $this->db->execute("DELETE FROM `{$this->table('page_snapshots')}` WHERE project_id = %d AND id IN (" . Connection::placeholders($ids, '%d') . ')', [$target->projectId, ...$ids]);
	}

	// Próby pobrania

	/**
	 * @param array<string, mixed> $diagnostics
	 */
	public function recordFetch(PageTarget $target, string $trigger, ?int $userId, string $startedAt, int $durationMs, bool $network, string $outcome, ?string $error, ?int $httpStatus, ?int $bytes, ?int $snapshotId, ?string $retryAfterAt, array $diagnostics): void
	{
		$this->insertRow($this->table('page_fetches'), [
			'project_id' => $target->projectId,
			'target_id' => $target->id,
			'snapshot_id' => $snapshotId,
			'host' => $target->host,
			'trigger_type' => $trigger,
			'requested_by' => $userId,
			'started_at' => $startedAt,
			'finished_at' => $this->now(),
			'duration_ms' => max(0, $durationMs),
			'network' => $network ? 1 : 0,
			'outcome' => $outcome,
			'error_code' => $error,
			'http_status' => $httpStatus,
			'bytes' => $bytes,
			'retry_after_at' => $retryAfterAt,
			'diagnostics' => (string) json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'request_key' => md5($target->url),
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetches(PageTarget $target, int $limit = 20): array
	{
		return array_map(static function (array $row): array {
			$diagnostics = json_decode((string) $row['diagnostics'], true);

			return [
				'started_at' => $row['started_at'],
				'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
				'trigger' => $row['trigger_type'],
				'network' => (int) $row['network'] === 1,
				'outcome' => $row['outcome'],
				'error' => $row['error_code'],
				'http_status' => $row['http_status'] === null ? null : (int) $row['http_status'],
				'bytes' => $row['bytes'] === null ? null : (int) $row['bytes'],
				'retry_after_at' => $row['retry_after_at'],
				'diagnostics' => is_array($diagnostics) ? $diagnostics : [],
			];
		}, $this->db->fetchAll(
			"SELECT * FROM `{$this->table('page_fetches')}` WHERE project_id = %d AND target_id = %d ORDER BY started_at DESC, id DESC LIMIT %d",
			[$target->projectId, $target->id, max(1, min(200, $limit))],
		));
	}

	/**
	 * Aktywność wobec hosta we wszystkich projektach (uprzejmość wobec witryny): ostatnie żądanie, liczba żądań od chwili, `Retry-After`.
	 *
	 * @return array{last: ?string, count: int, retry_after: ?string}
	 */
	public function hostActivity(string $host, string $since): array
	{
		$row = $this->db->fetchRow(
			"SELECT MAX(started_at) AS last, SUM(started_at >= %s) AS recent, MAX(retry_after_at) AS retry FROM `{$this->table('page_fetches')}` WHERE host = %s AND network = 1",
			[$since, $host],
		) ?? [];

		return ['last' => $row['last'] ?? null, 'count' => (int) ($row['recent'] ?? 0), 'retry_after' => $row['retry'] ?? null];
	}

	/**
	 * Liczniki projektu (status, bez HTTP).
	 *
	 * @return array<string, mixed>
	 */
	public function counts(int $projectId, string $since): array
	{
		$targets = [];

		foreach ($this->db->fetchAll("SELECT kind, status, COUNT(*) AS total FROM `{$this->table('page_targets')}` WHERE project_id = %d GROUP BY kind, status", [$projectId]) as $row) {
			$targets[(string) $row['kind']][(string) $row['status']] = (int) $row['total'];
		}

		return [
			'targets' => $targets,
			'snapshots' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table('page_snapshots')}` WHERE project_id = %d", [$projectId]),
			'fetches_24h' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table('page_fetches')}` WHERE project_id = %d AND network = 1 AND started_at >= %s", [$projectId, $since]),
		];
	}

	// Retencja

	/**
	 * Snapshoty i próby starsze niż chwila (wszystkie projekty); stronom bez snapshotu zostaje stan bez treści.
	 *
	 * @return array{snapshots: int, fetches: int}
	 */
	public function purgeBefore(string $before): array
	{
		$snapshots = $this->db->execute("DELETE FROM `{$this->table('page_snapshots')}` WHERE last_seen_at < %s", [$before]);
		$fetches = $this->db->execute("DELETE FROM `{$this->table('page_fetches')}` WHERE started_at < %s", [$before]);
		$this->db->execute(
			"UPDATE `{$this->table('page_targets')}` t LEFT JOIN `{$this->table('page_snapshots')}` s ON s.id = t.last_snapshot_id
			SET t.last_snapshot_id = NULL WHERE t.last_snapshot_id IS NOT NULL AND s.id IS NULL",
		);

		return ['snapshots' => $snapshots, 'fetches' => $fetches];
	}

	/**
	 * @param array<string, int|float|string|null> $row
	 */
	private function insertRow(string $table, array $row, bool $ignoreDuplicate = false): void
	{
		$columns = [];
		$values = [];
		$params = [];

		foreach ($row as $column => $value) {
			$columns[] = '`' . $column . '`';

			if ($value === null) {
				$values[] = 'NULL';

				continue;
			}

			$values[] = in_array($column, self::BINARY, true) ? 'UNHEX(%s)' : (is_int($value) ? '%d' : (is_float($value) ? '%f' : '%s'));
			$params[] = $value;
		}

		$this->db->execute(
			'INSERT ' . ($ignoreDuplicate ? 'IGNORE ' : '') . 'INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')',
			$params,
		);
	}

	private function table(string $name): string
	{
		return $this->db->table($name);
	}
}
