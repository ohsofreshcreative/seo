<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Zapis i odczyt szans. Każde zapytanie jest ograniczone do projektu (project_id z ProjectContext);
 * szansę z URL-a szukamy zawsze po (project_id, public_id) — identyfikator z innego projektu daje „nie znaleziono”.
 *
 * Analiza zastępuje wyłącznie dane pochodne (wykrycia okresu) i pola wykrycia (stan, snapshot, czasy);
 * pól pracy (status, notatka, data wdrożenia, baseline) nie dotyka.
 */
final class OpportunityRepository
{
	/** Kolumny listy (bez dużych pól last_evidence/baseline). */
	private const LIST_COLUMNS = 'o.id, o.public_id, o.type, o.property, o.page_url, o.keyword, o.state, o.status, o.note, o.completed_on,
		o.first_detected_at, o.last_detected_at, o.inactive_since, o.status_changed_at, o.status_changed_by';

	private const DETECTION_COLUMNS = 'd.period_days AS d_period_days, d.priority AS d_priority, d.confidence AS d_confidence,
		d.latest_date AS d_latest_date, d.evidence AS d_evidence';

	private const SNAPSHOT_COLUMNS = 'o.last_period_days AS d_period_days, o.last_priority AS d_priority, o.last_confidence AS d_confidence,
		o.last_latest_date AS d_latest_date, o.last_evidence AS d_evidence';

	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

	public function __construct(
		private readonly Connection $db,
		private readonly ProjectRepository $projects,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Zastępuje wykrycia okresu wynikiem analizy — atomowo, pod blokadą wiersza projektu i z kontrolą property
	 * (ta sama blokada co przy resecie danych i imporcie GSC). Szansa: nowa → wstawiona ze statusem „Nowa”,
	 * istniejąca → aktywna (także po archiwum tej samej property), niewykryta w żadnym okresie → nieaktywna.
	 *
	 * @param list<Candidate> $candidates
	 * @return array{detected: int, inserted: int, deactivated: int}
	 * @throws AnalysisAborted
	 */
	public function replaceDetections(int $projectId, int $days, string $property, string $latestDate, array $candidates): array
	{
		$now = $this->now();

		return $this->db->transaction(function () use ($projectId, $days, $property, $latestDate, $candidates, $now): array {
			$project = $this->projects->lockForUpdate($projectId);

			if ($project === null) {
				throw new AnalysisAborted('project_missing');
			}

			if ($project->gscProperty !== $property || $project->gscDataProperty !== $property) {
				throw new AnalysisAborted('property_changed');
			}

			$opportunities = $this->table();
			$detections = $this->db->table('opportunity_detections');
			$this->db->execute("DELETE FROM `{$detections}` WHERE project_id = %d AND period_days = %d", [$projectId, $days]);

			$ids = $this->idsByFingerprint($projectId, array_map(static fn (Candidate $candidate): string => bin2hex($candidate->fingerprint), $candidates));
			$inserted = 0;

			foreach ($candidates as $candidate) {
				$hex = bin2hex($candidate->fingerprint);

				if (! isset($ids[$hex])) {
					$ids[$hex] = $this->insert($projectId, $candidate, $property, $days, $latestDate, $now);
					$inserted++;
				}
			}

			$bulk = new BulkInsert(
				$this->db,
				$detections,
				['opportunity_id', 'period_days', 'project_id', 'priority', 'confidence', 'impressions', 'clicks', 'latest_date', 'search_text', 'evidence', 'analyzed_at'],
				['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s'],
				'',
				200,
			);

			foreach ($candidates as $candidate) {
				$bulk->add([
					$ids[bin2hex($candidate->fingerprint)],
					$days,
					$projectId,
					$candidate->priority,
					$candidate->confidence->value,
					min($candidate->impressions, 4294967295),
					min($candidate->clicks, 4294967295),
					$latestDate,
					$candidate->searchText,
					self::json($candidate->evidence),
					$now,
				]);
			}

			$bulk->flush();

			// Wykryte w tym okresie: aktywne (także powrót z „nieaktywna” i z archiwum tej samej property).
			$this->db->execute(
				"UPDATE `{$opportunities}` o JOIN `{$detections}` d ON d.opportunity_id = o.id AND d.period_days = %d
				SET o.state = 'active', o.inactive_since = NULL, o.last_detected_at = %s, o.updated_at = %s
				WHERE o.project_id = %d",
				[$days, $now, $now, $projectId],
			);

			// Snapshot dowodów: okres kanoniczny (28 dni), a gdy szansy w nim nie ma — wykrycie z tego okresu.
			$this->db->execute(
				"UPDATE `{$opportunities}` o JOIN `{$detections}` d ON d.opportunity_id = o.id AND d.period_days = %d
				SET o.last_priority = d.priority, o.last_confidence = d.confidence, o.last_period_days = d.period_days,
					o.last_latest_date = d.latest_date, o.last_evidence = d.evidence
				WHERE o.project_id = %d AND (%d = %d OR NOT EXISTS (
					SELECT 1 FROM `{$detections}` c WHERE c.opportunity_id = o.id AND c.period_days = %d
				))",
				[$days, $projectId, $days, OpportunityConfig::CANONICAL_DAYS, OpportunityConfig::CANONICAL_DAYS],
			);

			// Sygnał nie spełnia kryteriów w żadnym okresie — nieaktywna (historia i stan pracy zostają).
			$deactivated = $this->db->execute(
				"UPDATE `{$opportunities}` o SET o.state = 'inactive', o.inactive_since = %s, o.updated_at = %s
				WHERE o.project_id = %d AND o.state = 'active'
					AND NOT EXISTS (SELECT 1 FROM `{$detections}` d WHERE d.opportunity_id = o.id)",
				[$now, $now, $projectId],
			);

			return ['detected' => count($candidates), 'inserted' => $inserted, 'deactivated' => $deactivated];
		});
	}

	/**
	 * Reset danych GSC przy zmianie property: wykrycia i stan analiz są usuwane (dane pochodne starej property),
	 * szanse zostają jako archiwalne — z historią i stanem pracy, poza listą aktywnych rekomendacji.
	 * Wywoływane w transakcji resetu (blokada wiersza projektu).
	 */
	public function archiveProject(int $projectId): int
	{
		$now = $this->now();
		$this->db->execute("DELETE FROM `{$this->db->table('opportunity_detections')}` WHERE project_id = %d", [$projectId]);
		$this->db->execute("DELETE FROM `{$this->db->table('opportunity_analyses')}` WHERE project_id = %d", [$projectId]);

		return $this->db->execute(
			"UPDATE `{$this->table()}` SET state = 'archived', inactive_since = COALESCE(inactive_since, %s), updated_at = %s
			WHERE project_id = %d AND state <> 'archived'",
			[$now, $now, $projectId],
		);
	}

	public function recordAnalysis(int $projectId, int $days, string $status, string $trigger, ?string $property, ?string $dataKey, ?string $latestDate, int $count, int $durationMs, ?string $message): void
	{
		$values = [
			'property' => $property,
			'data_key' => $dataKey,
			'latest_date' => $latestDate,
			'message' => $message === null ? null : mb_substr($message, 0, 250),
		];
		$columns = ['project_id', 'period_days', 'status', 'trigger_type', 'opportunities', 'duration_ms', 'analyzed_at'];
		$sql = ['%d', '%d', '%s', '%s', '%d', '%d', '%s'];
		$params = [$projectId, $days, $status, $trigger, max(0, $count), max(0, $durationMs), $this->now()];

		foreach ($values as $column => $value) {
			$columns[] = $column;
			$sql[] = $value === null ? 'NULL' : '%s';

			if ($value !== null) {
				$params[] = $value;
			}
		}

		$updates = implode(', ', array_map(static fn (string $column): string => "`{$column}` = VALUES(`{$column}`)", array_slice($columns, 2)));

		$this->db->execute(
			"INSERT INTO `{$this->db->table('opportunity_analyses')}` (`" . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $sql) . ")
			ON DUPLICATE KEY UPDATE {$updates}",
			$params,
		);
	}

	/**
	 * @return array<int, array<string, string|null>> okres → ostatnia analiza
	 */
	public function analyses(int $projectId): array
	{
		$result = [];

		foreach ($this->db->fetchAll("SELECT * FROM `{$this->db->table('opportunity_analyses')}` WHERE project_id = %d", [$projectId]) as $row) {
			$result[(int) $row['period_days']] = $row;
		}

		return $result;
	}

	/**
	 * Projekty, których dane można analizować (aktywne, wybrana property zgodna ze źródłem danych).
	 *
	 * @return list<string>
	 */
	public function eligibleProjectIds(): array
	{
		return array_map(
			static fn (array $row): string => (string) $row['public_id'],
			$this->db->fetchAll(
				"SELECT public_id FROM `{$this->db->table('projects')}`
				WHERE status = 'active' AND gsc_property IS NOT NULL AND gsc_property = gsc_data_property ORDER BY id",
			),
		);
	}

	/**
	 * Lista szans projektu z filtrami (paginacja w SQL).
	 *
	 * @return array{rows: list<Opportunity>, total: int}
	 */
	public function list(int $projectId, OpportunityFilters $filters): array
	{
		[$from, $where, $params, $order] = $this->query($projectId, $filters);
		$columns = $filters->state === 'active' ? self::DETECTION_COLUMNS : self::SNAPSHOT_COLUMNS;
		$params[] = $filters->perPage;
		$params[] = ($filters->page - 1) * $filters->perPage;

		$records = $this->db->fetchAll(
			'SELECT ' . self::LIST_COLUMNS . ", {$columns}, COUNT(*) OVER () AS total_rows
			FROM {$from} WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d",
			$params,
		);

		return [
			'rows' => array_map(static fn (array $row): Opportunity => Opportunity::fromRow($row, $filters->state === 'active'), $records),
			'total' => $records === [] ? ($filters->page > 1 ? $this->count($projectId, $filters) : 0) : (int) $records[0]['total_rows'],
		];
	}

	/**
	 * Widok „wg podstron”: grupy (podstrona → liczba szans, najwyższy priorytet, typy) z paginacją po grupach,
	 * a dla grup bieżącej strony — ich szanse (jedno zapytanie).
	 *
	 * @return array{groups: list<array{page_url: ?string, count: int, max_priority: int, types: list<OpportunityType>, opportunities: list<Opportunity>}>, total: int}
	 */
	public function pageGroups(int $projectId, OpportunityFilters $filters): array
	{
		[$from, $where, $params] = $this->query($projectId, $filters->with(['state' => 'active']));
		$groupParams = [...$params, $filters->perPage, ($filters->page - 1) * $filters->perPage];

		$groups = $this->db->fetchAll(
			"SELECT LOWER(HEX(o.page_hash)) AS ph, MAX(o.page_url) AS page_url, COUNT(*) AS n, MAX(d.priority) AS max_priority,
				GROUP_CONCAT(DISTINCT o.type ORDER BY o.type) AS types, COUNT(*) OVER () AS total_rows
			FROM {$from} WHERE {$where}
			GROUP BY o.page_hash
			ORDER BY max_priority DESC, n DESC, page_url ASC
			LIMIT %d OFFSET %d",
			$groupParams,
		);

		if ($groups === []) {
			return ['groups' => [], 'total' => 0];
		}

		$hashes = array_values(array_filter(array_column($groups, 'ph'), static fn (?string $hash): bool => $hash !== null));
		$conditions = [];
		$memberParams = $params;

		if ($hashes !== []) {
			$conditions[] = 'o.page_hash IN (' . Connection::placeholders($hashes, 'UNHEX(%s)') . ')';
			array_push($memberParams, ...$hashes);
		}

		if (count($hashes) < count($groups)) {
			$conditions[] = 'o.page_hash IS NULL';
		}

		$members = $this->db->fetchAll(
			'SELECT ' . self::LIST_COLUMNS . ', ' . self::DETECTION_COLUMNS . ", LOWER(HEX(o.page_hash)) AS ph
			FROM {$from} WHERE {$where} AND (" . implode(' OR ', $conditions) . ')
			ORDER BY d.priority DESC, d.impressions DESC, o.id ASC',
			$memberParams,
		);

		$byGroup = [];

		foreach ($members as $member) {
			$byGroup[$member['ph'] ?? ''][] = Opportunity::fromRow($member, true);
		}

		return [
			'groups' => array_map(static fn (array $group): array => [
				'page_url' => $group['page_url'],
				'count' => (int) $group['n'],
				'max_priority' => (int) $group['max_priority'],
				'types' => array_map(static fn (string $type): OpportunityType => OpportunityType::from($type), explode(',', (string) $group['types'])),
				'opportunities' => $byGroup[$group['ph'] ?? ''] ?? [],
			], $groups),
			'total' => (int) $groups[0]['total_rows'],
		];
	}

	/**
	 * Liczby aktywnych szans okresu: per typ (wszystkie i otwarte) i per status.
	 *
	 * @return array{types: array<string, array{total: int, open: int}>, statuses: array<string, int>, states: array<string, int>}
	 */
	public function summary(int $projectId, int $days): array
	{
		$open = OpportunityStatus::openValues();
		$types = [];
		$statuses = [];
		$states = [];

		foreach ($this->db->fetchAll(
			"SELECT o.type, o.status, COUNT(*) AS n
			FROM `{$this->db->table('opportunity_detections')}` d JOIN `{$this->table()}` o ON o.id = d.opportunity_id
			WHERE d.project_id = %d AND d.period_days = %d
			GROUP BY o.type, o.status",
			[$projectId, $days],
		) as $row) {
			$type = (string) $row['type'];
			$types[$type] ??= ['total' => 0, 'open' => 0];
			$types[$type]['total'] += (int) $row['n'];
			$types[$type]['open'] += in_array($row['status'], $open, true) ? (int) $row['n'] : 0;
			$statuses[(string) $row['status']] = ($statuses[(string) $row['status']] ?? 0) + (int) $row['n'];
		}

		foreach ($this->db->fetchAll("SELECT state, COUNT(*) AS n FROM `{$this->table()}` WHERE project_id = %d GROUP BY state", [$projectId]) as $row) {
			$states[(string) $row['state']] = (int) $row['n'];
		}

		return ['types' => $types, 'statuses' => $statuses, 'states' => $states];
	}

	/**
	 * Szansa projektu po publicznym ID z dowodami wybranego okresu (albo okresu kanonicznego, innego okresu lub snapshotu).
	 */
	public function find(int $projectId, string $publicId, int $days): ?Opportunity
	{
		$normalized = Ulid::normalize($publicId);

		if ($normalized === null) {
			return null;
		}

		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE project_id = %d AND public_id = %s", [$projectId, $normalized]);

		if ($row === null) {
			return null;
		}

		$detections = [];

		foreach ($this->db->fetchAll(
			"SELECT * FROM `{$this->db->table('opportunity_detections')}` WHERE opportunity_id = %d AND project_id = %d",
			[(int) $row['id'], $projectId],
		) as $detection) {
			$detections[(int) $detection['period_days']] = $detection;
		}

		$detection = $detections[$days] ?? $detections[OpportunityConfig::CANONICAL_DAYS] ?? (reset($detections) ?: null);

		if ($detection !== null) {
			foreach (['period_days', 'priority', 'confidence', 'latest_date', 'evidence'] as $column) {
				$row['d_' . $column] = $detection[$column];
			}
		} else {
			foreach (['period_days', 'priority', 'confidence', 'latest_date', 'evidence'] as $column) {
				$row['d_' . $column] = $row['last_' . $column];
			}
		}

		return Opportunity::fromRow($row, isset($detections[$days]));
	}

	/**
	 * Okresy, w których szansa jest obecnie wykryta.
	 *
	 * @return list<int>
	 */
	public function detectedPeriods(int $projectId, int $opportunityId): array
	{
		return array_map('intval', array_column($this->db->fetchAll(
			"SELECT period_days FROM `{$this->db->table('opportunity_detections')}` WHERE opportunity_id = %d AND project_id = %d ORDER BY period_days",
			[$opportunityId, $projectId],
		), 'period_days'));
	}

	/**
	 * @param array<string, int|string|null> $fields status, note, completed_on, baseline, status_changed_at, status_changed_by
	 */
	public function updateWorkflow(int $projectId, int $opportunityId, array $fields): void
	{
		$allowed = array_intersect_key($fields, array_flip(['status', 'note', 'completed_on', 'baseline', 'status_changed_at', 'status_changed_by']));
		$allowed['updated_at'] = $this->now();

		$this->db->update($this->table(), $allowed, ['id' => $opportunityId, 'project_id' => $projectId]);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function json(array $data): string
	{
		return (string) json_encode($data, self::JSON_FLAGS);
	}

	/**
	 * Wspólna część zapytań listy: FROM, WHERE, parametry i ORDER BY (wyłącznie wartości z białych list).
	 *
	 * @return array{0: string, 1: string, 2: list<int|string>, 3: string}
	 */
	private function query(int $projectId, OpportunityFilters $filters): array
	{
		$params = [];

		if ($filters->state === 'active') {
			$from = "`{$this->db->table('opportunity_detections')}` d JOIN `{$this->table()}` o ON o.id = d.opportunity_id";
			$where = ['d.project_id = %d', 'd.period_days = %d'];
			array_push($params, $projectId, $filters->days);
			$priority = 'd.priority';
			$confidence = 'd.confidence';
			$order = 'd.priority DESC, d.impressions DESC, o.id ASC';
		} else {
			$from = "`{$this->table()}` o";
			$where = ['o.project_id = %d', 'o.state = %s'];
			array_push($params, $projectId, $filters->state);
			$priority = 'o.last_priority';
			$confidence = 'o.last_confidence';
			$order = 'o.last_detected_at DESC, o.id DESC';
		}

		if ($filters->type !== null) {
			$where[] = 'o.type = %s';
			$params[] = $filters->type;
		}

		$statuses = $filters->statuses();

		if ($statuses !== null) {
			$where[] = 'o.status IN (' . Connection::placeholders($statuses) . ')';
			array_push($params, ...$statuses);
		}

		if ($filters->minPriority > 0) {
			$where[] = "{$priority} >= %d";
			$params[] = $filters->minPriority;
		}

		if ($filters->minConfidence > 0) {
			$where[] = "{$confidence} >= %d";
			$params[] = $filters->minConfidence;
		}

		if ($filters->search !== '') {
			$like = '%' . $this->db->escapeLike($filters->search) . '%';

			if ($filters->state === 'active') {
				$where[] = 'd.search_text LIKE %s';
				$params[] = $like;
			} else {
				$where[] = '(o.page_url LIKE %s OR o.keyword LIKE %s)';
				array_push($params, $like, $like);
			}
		}

		return [$from, implode(' AND ', $where), $params, $order];
	}

	private function count(int $projectId, OpportunityFilters $filters): int
	{
		[$from, $where, $params] = $this->query($projectId, $filters);

		return (int) $this->db->fetchValue("SELECT COUNT(*) FROM {$from} WHERE {$where}", $params);
	}

	/**
	 * @param list<string> $hexes
	 * @return array<string, int> odcisk (hex) → id
	 */
	private function idsByFingerprint(int $projectId, array $hexes): array
	{
		$ids = [];

		foreach (array_chunk(array_values(array_unique($hexes)), 1000) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, LOWER(HEX(fingerprint)) AS h FROM `{$this->table()}` WHERE project_id = %d AND fingerprint IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				[$projectId, ...$chunk],
			) as $row) {
				$ids[(string) $row['h']] = (int) $row['id'];
			}
		}

		return $ids;
	}

	private function insert(int $projectId, Candidate $candidate, string $property, int $days, string $latestDate, string $now): int
	{
		$columns = [
			'public_id' => ['%s', Ulid::generate($this->clock->now())],
			'project_id' => ['%d', $projectId],
			'fingerprint' => ['UNHEX(%s)', bin2hex($candidate->fingerprint)],
			'type' => ['%s', $candidate->type->value],
			'property' => ['%s', $property],
			'page_url' => $candidate->pageUrl === null ? null : ['%s', mb_substr($candidate->pageUrl, 0, 2048)],
			'page_hash' => $candidate->pageUrl === null ? null : ['UNHEX(%s)', bin2hex(UrlKey::hash($candidate->pageUrl))],
			'keyword' => $candidate->keyword === null ? null : ['%s', mb_substr($candidate->keyword, 0, 500)],
			'state' => ['%s', OpportunityState::Active->value],
			'status' => ['%s', OpportunityStatus::New->value],
			'last_priority' => ['%d', $candidate->priority],
			'last_confidence' => ['%d', $candidate->confidence->value],
			'last_period_days' => ['%d', $days],
			'last_latest_date' => ['%s', $latestDate],
			'last_evidence' => ['%s', self::json($candidate->evidence)],
			'first_detected_at' => ['%s', $now],
			'last_detected_at' => ['%s', $now],
			'created_at' => ['%s', $now],
			'updated_at' => ['%s', $now],
		];
		$placeholders = [];
		$params = [];

		foreach ($columns as $column => $value) {
			$placeholders[] = $value === null ? 'NULL' : $value[0];

			if ($value !== null) {
				$params[] = $value[1];
			}
		}

		$this->db->execute(
			"INSERT INTO `{$this->table()}` (`" . implode('`, `', array_keys($columns)) . '`) VALUES (' . implode(', ', $placeholders) . ')',
			$params,
		);

		return (int) $this->db->fetchValue('SELECT LAST_INSERT_ID()');
	}

	private function table(): string
	{
		return $this->db->table('opportunities');
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
