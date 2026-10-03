<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Ulid;

/**
 * Tematy Strategii (`strategy_topics`) i przynależność fraz (`strategy_keywords.topic_id`, przypięcia, stan strony docelowej frazy).
 * Przeliczenie zapisuje wyłącznie kolumny wyliczane — status pracy, notatka, podstawa decyzji i punkt odniesienia zmienia tylko
 * użytkownik (D61). Wszystkie odczyty zawężone do projektu (`project_id` z `ProjectContext`).
 */
final class TopicRepository
{
	private const CHUNK = 500;

	private const SELECT = 't.id, t.public_id, t.active, t.inactive_reason, mt.public_id AS merged_into, t.label, t.keywords_count, t.demand, t.action,
		t.action_reason, t.confidence, t.confidence_level, t.priority, t.target_state, tu.url AS target_url, mu.url AS manual_target_url, t.manual_no_page,
		t.serp_band, t.status, t.note, t.status_changed_at, t.status_changed_by, t.completed_on, t.decision_changed, LOWER(HEX(t.evidence_hash)) AS evidence_hash,
		t.first_seen_at, t.refreshed_at';

	/** Kolumny wyliczane przez przeliczenie (aktualizowane przy zmianie). */
	private const COMPUTED = [
		'active', 'inactive_reason', 'merged_into_id', 'leader_market_keyword_id', 'label', 'keywords_count', 'demand', 'action', 'action_reason',
		'confidence', 'confidence_level', 'priority', 'target_state', 'target_url_id', 'serp_band', 'decision_changed', 'analysis', 'evidence_hash', 'refreshed_at', 'updated_at',
	];

	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Stan tematów projektu przed przeliczeniem: wyliczane kolumny, decyzje użytkownika i poprzedni członkowie (frazy rynkowe — także
	 * nieaktywni kandydaci, do stabilnych ID).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function previous(int $projectId): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT t.id, t.public_id, t.active, t.inactive_reason, t.leader_market_keyword_id, t.label, t.action, t.action_reason, t.confidence_level, t.priority,
				t.target_state, t.target_url_id, t.manual_target_url_id, mu.url AS manual_target_url, t.manual_no_page, t.serp_band, t.status, t.status_basis,
				t.decision_changed, LOWER(HEX(t.evidence_hash)) AS evidence_hash, t.keywords_count, tu.url AS target_url
			FROM `{$this->table()}` t
			LEFT JOIN `{$this->db->table('serp_urls')}` tu ON tu.id = t.target_url_id
			LEFT JOIN `{$this->db->table('serp_urls')}` mu ON mu.id = t.manual_target_url_id
			WHERE t.project_id = %d ORDER BY t.id",
			[$projectId],
		) as $row) {
			$basis = is_string($row['status_basis']) ? json_decode($row['status_basis'], true) : null;
			$result[(int) $row['id']] = [
				'id' => (int) $row['id'],
				'public_id' => (string) $row['public_id'],
				'active' => (int) $row['active'] === 1,
				'inactive_reason' => $row['inactive_reason'],
				'leader' => $row['leader_market_keyword_id'] === null ? null : (int) $row['leader_market_keyword_id'],
				'label' => $row['label'],
				'action' => $row['action'],
				'action_reason' => $row['action_reason'],
				'confidence_level' => $row['confidence_level'],
				'priority' => $row['priority'] === null ? null : (int) $row['priority'],
				'target_state' => $row['target_state'],
				'target_url' => $row['target_url'],
				'manual_target_url' => $row['manual_target_url'],
				'manual_no_page' => (int) $row['manual_no_page'] === 1,
				'serp_band' => $row['serp_band'],
				'status' => (string) $row['status'],
				'status_basis' => is_array($basis) ? $basis : null,
				'decision_changed' => (int) $row['decision_changed'] === 1,
				'evidence_hash' => $row['evidence_hash'],
				'keywords_count' => (int) $row['keywords_count'],
				'members' => [],
			];
		}

		foreach ($this->db->fetchAll(
			"SELECT topic_id, market_keyword_id FROM `{$this->db->table('strategy_keywords')}` WHERE project_id = %d AND topic_id IS NOT NULL ORDER BY market_keyword_id",
			[$projectId],
		) as $row) {
			if (isset($result[(int) $row['topic_id']])) {
				$result[(int) $row['topic_id']]['members'][] = (int) $row['market_keyword_id'];
			}
		}

		return $result;
	}

	/**
	 * Identyfikatory adresów ze słownika `serp_urls` (bez zapisu — adresy GSC, SERP i Labs są już w słowniku).
	 *
	 * @param list<string> $urls
	 * @return array<string, int>
	 */
	public function urlIds(array $urls): array
	{
		$hashes = [];

		foreach (array_unique($urls) as $url) {
			$hashes[md5((string) $url)] = (string) $url;
		}

		$result = [];

		foreach (array_chunk(array_keys($hashes), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, LOWER(HEX(url_hash)) AS h FROM `{$this->db->table('serp_urls')}` WHERE url_hash IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				$chunk,
			) as $row) {
				$result[$hashes[(string) $row['h']]] = (int) $row['id'];
			}
		}

		return $result;
	}

	/**
	 * Zapis przeliczenia: tematy (nowe i zmienione — tylko kolumny wyliczane), wycofane tematy, przynależność i strona docelowa fraz.
	 *
	 * @param list<array<string, mixed>> $topics wiersze z kolumnami wyliczanymi, `public_id` i `id` (null — nowy)
	 * @param array<int, array{reason: string, into: ?int}> $retired
	 * @param array<int, array{topic: ?string, state: ?string, url_id: ?int}> $keywords id wiersza `strategy_keywords` → przynależność
	 *   (`topic` = public_id tematu)
	 * @param array<int, array{topic_id: ?int, state: ?string, url_id: ?int}> $current przynależność przed przeliczeniem (zapis tylko zmian)
	 * @return array{inserted: int, updated: int, unchanged: int, deactivated: int, ids: array<string, int>}
	 */
	public function save(int $projectId, array $topics, array $retired, array $keywords, array $current, string $now): array
	{
		$report = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0, 'ids' => []];
		$upsert = new BulkInsert(
			$this->db,
			$this->table(),
			[
				'public_id', 'project_id', 'active', 'inactive_reason', 'merged_into_id', 'leader_market_keyword_id', 'label', 'keywords_count', 'demand',
				'action', 'action_reason', 'confidence', 'confidence_level', 'priority', 'target_state', 'target_url_id', 'serp_band', 'decision_changed',
				'analysis', 'evidence_hash', 'first_seen_at', 'refreshed_at', 'created_at', 'updated_at',
			],
			[
				'%s', '%d', '%d', "NULLIF(%s, '')", 'NULLIF(%d, 0)', 'NULLIF(%d, 0)', "NULLIF(%s, '')", '%d', 'NULLIF(%d, -1)',
				"NULLIF(%s, '')", "NULLIF(%s, '')", 'NULLIF(%d, -1)', "NULLIF(%s, '')", 'NULLIF(%d, -1)', "NULLIF(%s, '')", 'NULLIF(%d, 0)', "NULLIF(%s, '')", '%d',
				'%s', 'UNHEX(%s)', '%s', '%s', '%s', '%s',
			],
			'ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $column): string => "{$column} = VALUES({$column})", self::COMPUTED)),
			200,
		);

		foreach ($topics as $topic) {
			if (($topic['unchanged'] ?? false) === true) {
				$report['unchanged']++;

				continue;
			}

			$report[$topic['id'] === null ? 'inserted' : 'updated']++;
			$upsert->add([
				(string) $topic['public_id'],
				$projectId,
				1,
				'',
				0,
				(int) $topic['leader_market_keyword_id'],
				mb_substr((string) $topic['label'], 0, 255),
				min(65535, (int) $topic['keywords_count']),
				$topic['demand'] ?? -1,
				(string) $topic['action'],
				(string) $topic['action_reason'],
				$topic['confidence'] ?? -1,
				(string) $topic['confidence_level'],
				$topic['priority'] ?? -1,
				(string) $topic['target_state'],
				(int) ($topic['target_url_id'] ?? 0),
				(string) ($topic['serp_band'] ?? ''),
				(int) ($topic['decision_changed'] ?? 0),
				(string) $topic['analysis'],
				(string) $topic['evidence_hash'],
				$now,
				$now,
				$now,
				$now,
			]);
		}

		$upsert->flush();

		foreach ($retired as $topicId => $retire) {
			$report['deactivated'] += $this->db->execute(
				"UPDATE `{$this->table()}` SET active = 0, inactive_reason = %s, merged_into_id = NULLIF(%d, 0), refreshed_at = %s, updated_at = %s
				WHERE project_id = %d AND id = %d AND active = 1",
				[$retire['reason'], (int) $retire['into'], $now, $now, $projectId, (int) $topicId],
			);
		}

		$publicIds = array_values(array_unique(array_map(static fn (array $topic): string => (string) $topic['public_id'], $topics)));

		foreach (array_chunk($publicIds, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, public_id FROM `{$this->table()}` WHERE project_id = %d AND public_id IN (" . Connection::placeholders($chunk) . ')',
				[$projectId, ...$chunk],
			) as $row) {
				$report['ids'][(string) $row['public_id']] = (int) $row['id'];
			}
		}

		$changed = [];

		foreach ($keywords as $id => $keyword) {
			$topicId = $keyword['topic'] === null ? null : ($report['ids'][$keyword['topic']] ?? null);
			$was = $current[$id] ?? null;

			if ($was === null || $was['topic_id'] !== $topicId || $was['state'] !== $keyword['state'] || $was['url_id'] !== $keyword['url_id']) {
				$changed[$id] = $keyword;
			}
		}

		$this->assignKeywords($projectId, $changed, $report['ids']);

		return $report;
	}

	/**
	 * @param array<int, array{topic: ?string, state: ?string, url_id: ?int}> $keywords
	 * @param array<string, int> $ids
	 */
	private function assignKeywords(int $projectId, array $keywords, array $ids): void
	{
		foreach (array_chunk($keywords, self::CHUNK, true) as $chunk) {
			$topicCase = '';
			$stateCase = '';
			$urlCase = '';
			$params = [[], [], []];

			foreach ($chunk as $id => $keyword) {
				$topicCase .= ' WHEN %d THEN NULLIF(%d, 0)';
				$params[0][] = (int) $id;
				$params[0][] = $keyword['topic'] === null ? 0 : ($ids[$keyword['topic']] ?? 0);
				$stateCase .= " WHEN %d THEN NULLIF(%s, '')";
				$params[1][] = (int) $id;
				$params[1][] = (string) $keyword['state'];
				$urlCase .= ' WHEN %d THEN NULLIF(%d, 0)';
				$params[2][] = (int) $id;
				$params[2][] = (int) $keyword['url_id'];
			}

			$this->db->execute(
				"UPDATE `{$this->db->table('strategy_keywords')}` SET topic_id = CASE id{$topicCase} END, target_state = CASE id{$stateCase} END,
					target_url_id = CASE id{$urlCase} END
				WHERE project_id = %d AND id IN (" . Connection::placeholders(array_keys($chunk), '%d') . ')',
				[...$params[0], ...$params[1], ...$params[2], $projectId, ...array_keys($chunk)],
			);
		}
	}

	/**
	 * Przynależność fraz przed przeliczeniem: id wiersza `strategy_keywords` → temat, stan i adres strony docelowej.
	 *
	 * @return array<int, array{topic_id: ?int, state: ?string, url_id: ?int}>
	 */
	public function keywordAssignments(int $projectId): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT id, topic_id, target_state, target_url_id FROM `{$this->db->table('strategy_keywords')}` WHERE project_id = %d",
			[$projectId],
		) as $row) {
			$result[(int) $row['id']] = [
				'topic_id' => $row['topic_id'] === null ? null : (int) $row['topic_id'],
				'state' => $row['target_state'],
				'url_id' => $row['target_url_id'] === null ? null : (int) $row['target_url_id'],
			];
		}

		return $result;
	}

	/**
	 * @return array{rows: list<TopicRow>, total: int}
	 */
	public function list(int $projectId, TopicFilters $filters, bool $restricted = false): array
	{
		$where = ['t.project_id = %d'];
		$params = [$projectId];

		if ($filters->state !== 'all') {
			$where[] = 't.active = %d';
			$params[] = $filters->state === 'active' ? 1 : 0;
			// Temat utworzony przypięciem — do najbliższego przeliczenia bez analizy.
			$where[] = "(t.inactive_reason IS NULL OR t.inactive_reason <> 'pending')";
		}

		if ($filters->status === 'open') {
			$where[] = 't.status IN (' . Connection::placeholders(TopicStatus::OPEN) . ')';
			$params = [...$params, ...TopicStatus::OPEN];
		} elseif ($filters->status !== 'all') {
			$where[] = 't.status = %s';
			$params[] = $filters->status;
		}

		if ($restricted) {
			$where[] = "t.status <> 'dismissed'";
		}

		if ($filters->action !== null) {
			$where[] = 't.action = %s';
			$params[] = $filters->action->value;
		} elseif (! $filters->includeMonitor) {
			$where[] = "(t.action IS NULL OR t.action <> 'monitor')";
		}

		if ($filters->confidence !== null) {
			$where[] = 't.confidence_level = %s';
			$params[] = $filters->confidence;
		}

		if ($filters->q !== '') {
			$where[] = 't.label LIKE %s';
			$params[] = '%' . $this->db->escapeLike(mb_strtolower($filters->q)) . '%';
		}

		$dir = $filters->direction === 'asc' ? 'ASC' : 'DESC';
		$order = match ($filters->sort) {
			'confidence' => "t.confidence IS NULL, t.confidence {$dir}, t.priority DESC",
			'demand' => "t.demand IS NULL, t.demand {$dir}",
			'keywords' => "t.keywords_count {$dir}",
			'label' => "t.label {$dir}",
			'updated' => "t.updated_at {$dir}",
			default => "t.priority IS NULL, t.priority {$dir}, t.confidence DESC",
		};
		$params[] = $filters->perPage;
		$params[] = $filters->offset();
		$rows = $this->db->fetchAll(
			'SELECT ' . self::SELECT . ', COUNT(*) OVER () AS total_rows ' . $this->from() . ' WHERE ' . implode(' AND ', $where) . " ORDER BY {$order}, t.id LIMIT %d OFFSET %d",
			$params,
		);

		return [
			'rows' => array_map(static fn (array $row): TopicRow => TopicRow::fromRow($row, ! $restricted), $rows),
			'total' => (int) ($rows[0]['total_rows'] ?? 0),
		];
	}

	public function find(int $projectId, string $publicId, bool $restricted = false): ?TopicRow
	{
		$publicId = Ulid::normalize($publicId);

		if ($publicId === null) {
			return null;
		}

		$row = $this->db->fetchRow(
			'SELECT ' . self::SELECT . ', t.analysis, t.status_basis, t.baseline ' . $this->from() . ' WHERE t.project_id = %d AND t.public_id = %s',
			[$projectId, $publicId],
		);

		return $row === null || ($restricted && $row['status'] === 'dismissed') ? null : TopicRow::fromRow($row, ! $restricted);
	}

	/** Temat frazy (wiersz `strategy_keywords` projektu). */
	public function findByKeyword(int $projectId, int $keywordRowId, bool $restricted = false): ?TopicRow
	{
		$publicId = $this->db->fetchValue(
			"SELECT t.public_id FROM `{$this->db->table('strategy_keywords')}` s JOIN `{$this->table()}` t ON t.id = COALESCE(s.topic_id, s.pinned_topic_id) AND t.project_id = s.project_id
			WHERE s.project_id = %d AND s.id = %d",
			[$projectId, $keywordRowId],
		);

		return $publicId === null ? null : $this->find($projectId, (string) $publicId, $restricted);
	}

	/**
	 * Frazy tematu (aktywni kandydaci) z metrykami i stroną docelową frazy.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function members(int $projectId, int $topicId): array
	{
		return array_map(static fn (array $row): array => [
			'id' => (string) $row['public_id'],
			'market_keyword_id' => (int) $row['market_keyword_id'],
			'keyword' => (string) $row['keyword'],
			'volume' => $row['search_volume'] === null ? null : (int) $row['search_volume'],
			'difficulty' => $row['keyword_difficulty'] === null ? null : (int) $row['keyword_difficulty'],
			'intent' => $row['search_intent'],
			'gsc_impressions' => $row['gsc_impressions'] === null ? null : (int) $row['gsc_impressions'],
			'gsc_position' => $row['gsc_position'] === null ? null : (float) $row['gsc_position'],
			'serp_rank' => $row['serp_rank'] === null ? null : (int) $row['serp_rank'],
			'serp_checked_at' => $row['serp_checked_at'],
			'target_state' => $row['target_state'],
			'target_url' => $row['target_url'],
			'pinned' => (int) $row['pinned'] === 1,
		], $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN s.public_id, s.market_keyword_id, m.keyword, m.search_volume, m.keyword_difficulty, m.search_intent, s.gsc_impressions, s.gsc_position,
				s.serp_rank, s.serp_checked_at, s.target_state, u.url AS target_url, (s.pinned_topic_id <=> s.topic_id) AS pinned
			FROM `{$this->db->table('strategy_keywords')}` s
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = s.target_url_id
			WHERE s.project_id = %d AND s.topic_id = %d AND s.active = 1
			ORDER BY m.search_volume IS NULL, m.search_volume DESC, s.gsc_impressions IS NULL, s.gsc_impressions DESC, s.market_keyword_id",
			[$projectId, $topicId],
		));
	}

	/**
	 * Zmiana statusu pracy (wyłącznie decyzja użytkownika): notatka, kto i kiedy, data realizacji, podstawa decyzji, punkt odniesienia
	 * (zapisywany raz — przy pierwszym przejściu do realizacji albo zrealizowania).
	 *
	 * @param array<string, mixed> $basis
	 * @param array<string, mixed>|null $baseline
	 */
	public function setStatus(int $projectId, int $topicId, TopicStatus $status, ?string $note, ?int $userId, string $now, array $basis, ?array $baseline): void
	{
		$sets = ['status = %s', 'status_changed_at = %s', 'status_changed_by = NULLIF(%d, 0)', 'status_basis = %s', 'decision_changed = 0', 'updated_at = %s'];
		$params = [$status->value, $now, max(0, (int) $userId), (string) json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now];
		$sets[] = $status === TopicStatus::Completed ? 'completed_on = COALESCE(completed_on, %s)' : 'completed_on = NULL';

		if ($status === TopicStatus::Completed) {
			$params[] = substr($now, 0, 10);
		}

		if ($note !== null) {
			$sets[] = "note = NULLIF(%s, '')";
			$params[] = mb_substr($note, 0, 5000);
		}

		if ($baseline !== null) {
			$sets[] = 'baseline = COALESCE(baseline, %s)';
			$params[] = (string) json_encode($baseline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		$this->db->execute(
			"UPDATE `{$this->table()}` SET " . implode(', ', $sets) . ' WHERE project_id = %d AND id = %d',
			[...$params, $projectId, $topicId],
		);
	}

	public function setNote(int $projectId, int $topicId, string $note, string $now): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET note = NULLIF(%s, ''), updated_at = %s WHERE project_id = %d AND id = %d",
			[mb_substr($note, 0, 5000), $now, $projectId, $topicId],
		);
	}

	/** Ręczna strona docelowa tematu (adres ze słownika), ręczne potwierdzenie braku strony albo usunięcie wskazania. */
	public function setManualTarget(int $projectId, int $topicId, ?int $urlId, bool $noPage, ?int $userId, string $now): void
	{
		$manual = $urlId !== null || $noPage;
		$this->db->execute(
			"UPDATE `{$this->table()}` SET manual_target_url_id = NULLIF(%d, 0), manual_no_page = %d, manual_target_by = NULLIF(%d, 0),
				manual_target_at = NULLIF(%s, ''), updated_at = %s WHERE project_id = %d AND id = %d",
			[(int) $urlId, $noPage ? 1 : 0, $manual ? max(0, (int) $userId) : 0, $manual ? $now : '', $now, $projectId, $topicId],
		);
	}

	/**
	 * Ręczne przypięcie fraz (wiersze `strategy_keywords` projektu) do tematu albo odpięcie (`$topicId` null).
	 *
	 * @param list<int> $keywordRowIds
	 */
	public function pin(int $projectId, array $keywordRowIds, ?int $topicId, ?int $userId, string $now): int
	{
		$changed = 0;

		foreach (array_chunk(array_values(array_unique($keywordRowIds)), self::CHUNK) as $chunk) {
			$changed += $this->db->execute(
				"UPDATE `{$this->db->table('strategy_keywords')}` SET pinned_topic_id = NULLIF(%d, 0), pinned_by = NULLIF(%d, 0), pinned_at = NULLIF(%s, ''), updated_at = %s
				WHERE project_id = %d AND id IN (" . Connection::placeholders($chunk, '%d') . ') AND NOT (pinned_topic_id <=> NULLIF(%d, 0))',
				[(int) $topicId, $topicId === null ? 0 : max(0, (int) $userId), $topicId === null ? '' : $now, $now, $projectId, ...$chunk, (int) $topicId],
			);
		}

		return $changed;
	}

	/**
	 * Nowy temat z ręcznego przypięcia — nieaktywny („pending”) do najbliższego przeliczenia.
	 *
	 * @return array{id: int, public_id: string}
	 */
	public function createPending(int $projectId, string $label, string $now): array
	{
		$publicId = Ulid::generate();
		$id = $this->db->insert($this->table(), [
			'public_id' => $publicId,
			'project_id' => $projectId,
			'active' => 0,
			'inactive_reason' => 'pending',
			'label' => mb_substr($label, 0, 255),
			'keywords_count' => 0,
			'first_seen_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		]);

		return ['id' => $id, 'public_id' => $publicId];
	}

	/**
	 * Liczby tematów projektu: aktywne według działania i statusu.
	 *
	 * @return array{active: int, inactive: int, actions: array<string, int>, statuses: array<string, int>}
	 */
	public function counts(int $projectId): array
	{
		$result = ['active' => 0, 'inactive' => 0, 'actions' => [], 'statuses' => []];

		foreach ($this->db->fetchAll(
			"SELECT active, action, status, COUNT(*) AS n FROM `{$this->table()}` WHERE project_id = %d AND (inactive_reason IS NULL OR inactive_reason <> 'pending') GROUP BY active, action, status",
			[$projectId],
		) as $row) {
			if ((int) $row['active'] !== 1) {
				$result['inactive'] += (int) $row['n'];

				continue;
			}

			$result['active'] += (int) $row['n'];
			$action = (string) ($row['action'] ?? 'none');
			$result['actions'][$action] = ($result['actions'][$action] ?? 0) + (int) $row['n'];
			$result['statuses'][(string) $row['status']] = ($result['statuses'][(string) $row['status']] ?? 0) + (int) $row['n'];
		}

		ksort($result['actions']);
		ksort($result['statuses']);

		return $result;
	}

	private function from(): string
	{
		return "FROM `{$this->table()}` t
			LEFT JOIN `{$this->table()}` mt ON mt.id = t.merged_into_id
			LEFT JOIN `{$this->db->table('serp_urls')}` tu ON tu.id = t.target_url_id
			LEFT JOIN `{$this->db->table('serp_urls')}` mu ON mu.id = t.manual_target_url_id";
	}

	private function table(): string
	{
		return $this->db->table('strategy_topics');
	}
}
