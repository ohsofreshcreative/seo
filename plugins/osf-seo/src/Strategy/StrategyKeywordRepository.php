<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Support\Ulid;

/**
 * Kandydaci Strategii (`strategy_keywords`): zapis przyrostowy faktów i dowodów (tylko zmienione wiersze, po `facts_hash`),
 * dezaktywacja kandydatów, którzy wypadli (z powodem — identyfikator i wpis ręczny zostają), wpisy ręczne, lista i szczegóły.
 * Wszystkie odczyty są zawężone do projektu (`project_id` z `ProjectContext`).
 */
final class StrategyKeywordRepository
{
	public const REASON_NO_SOURCE = 'no_source';

	public const REASON_OVERFLOW = 'overflow';

	public const REASON_MARKET_CHANGED = 'market_changed';

	private const CHUNK = 500;

	private const LIST_COLUMNS = 's.id, s.public_id, s.market_keyword_id, s.active, s.inactive_reason, s.sources, s.tier, s.manual, s.gsc_impressions,
		s.gsc_clicks, s.gsc_position, s.gsc_pages, s.serp_checked_at, s.serp_found, s.serp_rank, s.opportunities, s.first_seen_at, s.refreshed_at,
		m.keyword, m.search_volume, m.keyword_difficulty, m.cpc, m.search_intent';

	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Zapis kandydatów przeliczenia. Wiersz bez zmian odcisku pozostaje nietknięty; kandydaci spoza zbioru dostają `active = 0`
	 * z aktualnym powodem (`$reasons`: id frazy rynkowej → powód, domyślnie `no_source`; fraza innego rynku — `market_changed`),
	 * bez źródeł i poziomu — fakty i dowody zostają jako stan z ostatniego przeliczenia, w którym fraza była kandydatem.
	 *
	 * @param list<KeywordFacts> $facts
	 * @param array<int, string> $reasons
	 * @return array{inserted: int, updated: int, unchanged: int, deactivated: int}
	 */
	public function sync(int $projectId, Market $market, array $facts, array $reasons, string $now): array
	{
		$report = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0];
		$existing = $this->existing($projectId, $market);
		$upsert = new BulkInsert(
			$this->db,
			$this->table(),
			[
				'public_id', 'project_id', 'market_keyword_id', 'active', 'inactive_reason', 'sources', 'tier', 'gsc_impressions', 'gsc_clicks',
				'gsc_position', 'gsc_pages', 'gsc_top_url_id', 'gsc_top_share', 'tracked_keyword_id', 'serp_checked_at', 'serp_found', 'serp_rank',
				'serp_url_id', 'gap_keyword_id', 'gap_cluster_id', 'discovery_candidate_id', 'opportunities', 'evidence', 'facts_hash',
				'first_seen_at', 'refreshed_at', 'created_at', 'updated_at', 'serp_intel_at',
			],
			[
				'%s', '%d', '%d', '%d', "NULLIF(%s, '')", '%d', '%d', 'NULLIF(%d, -1)', 'NULLIF(%d, -1)',
				"NULLIF(%s, '')", 'NULLIF(%d, -1)', 'NULLIF(%d, 0)', "NULLIF(%s, '')", 'NULLIF(%d, 0)', "NULLIF(%s, '')", 'NULLIF(%d, -1)', 'NULLIF(%d, 0)',
				'NULLIF(%d, 0)', 'NULLIF(%d, 0)', 'NULLIF(%d, 0)', 'NULLIF(%d, 0)', '%d', '%s', 'UNHEX(%s)',
				'%s', '%s', '%s', '%s', "NULLIF(%s, '')",
			],
			'ON DUPLICATE KEY UPDATE active = VALUES(active), inactive_reason = VALUES(inactive_reason), sources = VALUES(sources), tier = VALUES(tier),
				gsc_impressions = VALUES(gsc_impressions), gsc_clicks = VALUES(gsc_clicks), gsc_position = VALUES(gsc_position), gsc_pages = VALUES(gsc_pages),
				gsc_top_url_id = VALUES(gsc_top_url_id), gsc_top_share = VALUES(gsc_top_share), tracked_keyword_id = VALUES(tracked_keyword_id),
				serp_checked_at = VALUES(serp_checked_at), serp_found = VALUES(serp_found), serp_rank = VALUES(serp_rank), serp_url_id = VALUES(serp_url_id),
				gap_keyword_id = VALUES(gap_keyword_id), gap_cluster_id = VALUES(gap_cluster_id), discovery_candidate_id = VALUES(discovery_candidate_id),
				opportunities = VALUES(opportunities), evidence = VALUES(evidence), facts_hash = VALUES(facts_hash), refreshed_at = VALUES(refreshed_at),
				updated_at = VALUES(updated_at), serp_intel_at = VALUES(serp_intel_at)',
			200,
		);
		$seen = [];

		foreach ($facts as $fact) {
			$seen[$fact->marketKeywordId] = true;
			$hash = $fact->hash();
			$row = $existing[$fact->marketKeywordId] ?? null;

			if ($row !== null && $row['active'] && $row['hash'] === $hash) {
				$report['unchanged']++;

				continue;
			}

			$report[$row === null ? 'inserted' : 'updated']++;
			$upsert->add([
				$row === null ? Ulid::generate() : $row['public_id'],
				$projectId,
				$fact->marketKeywordId,
				1,
				'',
				$fact->sources,
				$fact->tier,
				$fact->gscImpressions ?? -1,
				$fact->gscClicks ?? -1,
				$fact->gscPosition === null ? '' : number_format($fact->gscPosition, 2, '.', ''),
				$fact->gscPages ?? -1,
				(int) $fact->gscTopUrlId,
				$fact->gscTopShare === null ? '' : number_format($fact->gscTopShare, 4, '.', ''),
				(int) $fact->trackedKeywordId,
				(string) $fact->serpCheckedAt,
				$fact->serpFound === null ? -1 : (int) $fact->serpFound,
				(int) $fact->serpRank,
				(int) $fact->serpUrlId,
				(int) $fact->gapKeywordId,
				(int) $fact->gapClusterId,
				(int) $fact->discoveryCandidateId,
				$fact->opportunities,
				$fact->evidenceJson(),
				$hash,
				$now,
				$now,
				$now,
				$now,
				(string) $fact->serpIntelAt,
			]);
		}

		$upsert->flush();
		$deactivate = [];
		$relabel = [];

		foreach ($existing as $marketKeywordId => $row) {
			if (isset($seen[$marketKeywordId])) {
				continue;
			}

			$reason = $row['in_market'] ? ($reasons[$marketKeywordId] ?? self::REASON_NO_SOURCE) : self::REASON_MARKET_CHANGED;

			if ($row['active']) {
				$deactivate[$reason][] = $row['id'];
			} elseif ($row['reason'] !== $reason) {
				// Nieaktywny kandydat z innego powodu niż poprzednio (np. ponad limit → odfiltrowany) — tylko aktualny powód.
				$relabel[$reason][] = $row['id'];
			}
		}

		foreach ([[$deactivate, true], [$relabel, false]] as [$groups, $count]) {
			foreach ($groups as $reason => $ids) {
				foreach (array_chunk($ids, self::CHUNK) as $chunk) {
					$affected = $this->db->execute(
						"UPDATE `{$this->table()}` SET active = 0, inactive_reason = %s, sources = 0, tier = NULL, updated_at = %s
						WHERE project_id = %d AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
						[(string) $reason, $now, $projectId, ...$chunk],
					);
					$report['deactivated'] += $count ? $affected : 0;
				}
			}
		}

		return $report;
	}

	/**
	 * Wpisy ręczne (frazy rynkowe projektu). Istniejący kandydat dostaje znacznik wpisu ręcznego; nowy jest aktywny od razu
	 * (fakty i dowody uzupełni najbliższe przeliczenie).
	 *
	 * @param list<int> $marketKeywordIds
	 * @return array{added: int, existing: int}
	 */
	public function addManual(int $projectId, array $marketKeywordIds, ?int $userId, string $now): array
	{
		$result = ['added' => 0, 'existing' => 0];
		$marketKeywordIds = array_values(array_unique(array_map('intval', $marketKeywordIds)));

		if ($marketKeywordIds === []) {
			return $result;
		}

		return $this->db->transaction(function () use ($projectId, $marketKeywordIds, $userId, $now, $result): array {
			$current = [];

			foreach (array_chunk($marketKeywordIds, self::CHUNK) as $chunk) {
				foreach ($this->db->fetchAll(
					"SELECT id, market_keyword_id, manual FROM `{$this->table()}` WHERE project_id = %d AND market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ') FOR UPDATE',
					[$projectId, ...$chunk],
				) as $row) {
					$current[(int) $row['market_keyword_id']] = $row;
				}
			}

			$insert = new BulkInsert(
				$this->db,
				$this->table(),
				['public_id', 'project_id', 'market_keyword_id', 'active', 'sources', 'tier', 'manual', 'manual_added_by', 'manual_added_at', 'first_seen_at', 'created_at', 'updated_at'],
				['%s', '%d', '%d', '%d', '%d', '%d', '%d', 'NULLIF(%d, 0)', '%s', '%s', '%s', '%s'],
			);
			$mark = [];

			foreach ($marketKeywordIds as $id) {
				$row = $current[$id] ?? null;

				if ($row === null) {
					$insert->add([Ulid::generate(), $projectId, $id, 1, StrategySource::Manual->bit(), SourceSignal::TIER_MANUAL, 1, max(0, (int) $userId), $now, $now, $now, $now]);
					$result['added']++;
				} elseif ((int) $row['manual'] === 0) {
					$mark[] = (int) $row['id'];
					$result['added']++;
				} else {
					$result['existing']++;
				}
			}

			$insert->flush();

			foreach (array_chunk($mark, self::CHUNK) as $chunk) {
				$this->db->execute(
					"UPDATE `{$this->table()}` SET manual = 1, manual_added_by = NULLIF(%d, 0), manual_added_at = %s, updated_at = %s
					WHERE project_id = %d AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
					[max(0, (int) $userId), $now, $now, $projectId, ...$chunk],
				);
			}

			return $result;
		});
	}

	/**
	 * Zdjęcie znacznika wpisu ręcznego (kandydat zostaje, jeśli wspierają go inne źródła — decyduje przeliczenie).
	 *
	 * @param list<string> $publicIds
	 */
	public function removeManual(int $projectId, array $publicIds, string $now): int
	{
		$publicIds = array_values(array_filter($publicIds, static fn (string $id): bool => Ulid::isValid($id)));

		if ($publicIds === []) {
			return 0;
		}

		return $this->db->execute(
			"UPDATE `{$this->table()}` SET manual = 0, manual_added_by = NULL, manual_added_at = NULL, updated_at = %s
			WHERE project_id = %d AND manual = 1 AND public_id IN (" . Connection::placeholders($publicIds) . ')',
			[$now, $projectId, ...$publicIds],
		);
	}

	/**
	 * @return array{rows: list<CandidateRow>, total: int}
	 */
	public function list(int $projectId, CandidateFilters $filters): array
	{
		$where = ['s.project_id = %d'];
		$params = [$projectId];

		if ($filters->status !== 'all') {
			$where[] = 's.active = %d';
			$params[] = $filters->status === 'active' ? 1 : 0;
		}

		if ($filters->source !== null) {
			$where[] = '(s.sources & %d) <> 0';
			$params[] = $filters->source->bit();
		}

		if ($filters->q !== '') {
			$where[] = 'm.keyword LIKE %s';
			$params[] = '%' . $this->db->escapeLike(mb_strtolower($filters->q)) . '%';
		}

		$dir = $filters->direction === 'desc' ? 'DESC' : 'ASC';
		$order = match ($filters->sort) {
			'impressions' => "s.gsc_impressions IS NULL, s.gsc_impressions {$dir}",
			'volume' => "m.search_volume IS NULL, m.search_volume {$dir}",
			'position' => "s.gsc_position IS NULL, s.gsc_position {$dir}",
			'serp_rank' => "s.serp_rank IS NULL, s.serp_rank {$dir}",
			'keyword' => "m.keyword {$dir}",
			'first_seen' => "s.first_seen_at {$dir}",
			default => "s.tier IS NULL, s.tier {$dir}, s.gsc_impressions IS NULL, s.gsc_impressions DESC",
		};
		$params[] = $filters->perPage;
		$params[] = $filters->offset();
		$rows = $this->db->fetchAll(
			'SELECT STRAIGHT_JOIN ' . self::LIST_COLUMNS . ", COUNT(*) OVER () AS total_rows
			FROM `{$this->table()}` s JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			WHERE " . implode(' AND ', $where) . " ORDER BY {$order}, s.id LIMIT %d OFFSET %d",
			$params,
		);

		return [
			'rows' => array_map(static fn (array $row): CandidateRow => CandidateRow::fromRow($row), $rows),
			'total' => (int) ($rows[0]['total_rows'] ?? 0),
		];
	}

	public function find(int $projectId, string $publicId): ?CandidateRow
	{
		$publicId = Ulid::normalize($publicId);

		if ($publicId === null) {
			return null;
		}

		$row = $this->db->fetchRow(
			'SELECT STRAIGHT_JOIN ' . self::LIST_COLUMNS . ", s.evidence
			FROM `{$this->table()}` s JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			WHERE s.project_id = %d AND s.public_id = %s",
			[$projectId, $publicId],
		);

		return $row === null ? null : CandidateRow::fromRow($row);
	}

	/** Kandydat po kluczu rynkowym frazy (hex) na rynku projektu. */
	public function findByKey(int $projectId, Market $market, string $keyHex): ?CandidateRow
	{
		$row = $this->db->fetchRow(
			'SELECT STRAIGHT_JOIN ' . self::LIST_COLUMNS . ", s.evidence
			FROM `{$this->db->table('market_keywords')}` m JOIN `{$this->table()}` s ON s.project_id = %d AND s.market_keyword_id = m.id
			WHERE m.provider = %s AND m.location_code = %d AND m.language_code = %s AND m.keyword_key = UNHEX(%s)",
			[$projectId, $market->provider, $market->locationCode, $market->languageCode, $keyHex],
		);

		return $row === null ? null : CandidateRow::fromRow($row);
	}

	/**
	 * Aktywni kandydaci wskazani ULID-em albo tekstem frazy (klucz rynkowy na rynku projektu): wartość → kandydat albo null.
	 *
	 * @param list<string> $values
	 * @return array<string, array{id: int, public_id: string, market_keyword_id: int, keyword: string, active: bool, tier: ?int}|null>
	 */
	public function resolve(int $projectId, Market $market, array $values): array
	{
		$result = [];
		$byId = [];
		$byKey = [];

		foreach ($values as $value) {
			$value = trim((string) $value);

			if ($value === '') {
				continue;
			}

			$result[$value] = null;
			$ulid = Ulid::normalize($value);

			if ($ulid !== null) {
				$byId[$ulid][] = $value;
			} else {
				$byKey[bin2hex(MarketKeyword::key($value))][] = $value;
			}
		}

		$select = 'SELECT STRAIGHT_JOIN s.id, s.public_id, s.market_keyword_id, s.active, s.tier, m.keyword, LOWER(HEX(m.keyword_key)) AS h';

		foreach (array_chunk(array_keys($byId), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"{$select} FROM `{$this->table()}` s JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
				WHERE s.project_id = %d AND s.public_id IN (" . Connection::placeholders($chunk) . ') AND m.provider = %s AND m.location_code = %d AND m.language_code = %s',
				[$projectId, ...$chunk, $market->provider, $market->locationCode, $market->languageCode],
			) as $row) {
				foreach ($byId[(string) $row['public_id']] ?? [] as $value) {
					$result[$value] = self::resolved($row);
				}
			}
		}

		foreach (array_chunk(array_keys($byKey), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"{$select} FROM `{$this->db->table('market_keywords')}` m JOIN `{$this->table()}` s ON s.project_id = %d AND s.market_keyword_id = m.id
				WHERE m.provider = %s AND m.location_code = %d AND m.language_code = %s AND m.keyword_key IN (" . Connection::placeholders(array_map('strval', $chunk), 'UNHEX(%s)') . ')',
				[$projectId, $market->provider, $market->locationCode, $market->languageCode, ...array_map('strval', $chunk)],
			) as $row) {
				foreach ($byKey[(string) $row['h']] ?? [] as $value) {
					$result[$value] = self::resolved($row);
				}
			}
		}

		return $result;
	}

	/**
	 * Aktywni kandydaci rynku projektu w kolejności Strategii (poziom źródła, wyświetlenia GSC, identyfikator) — stronicowanie po kluczu.
	 *
	 * @return list<array{id: int, public_id: string, market_keyword_id: int, keyword: string, active: bool, tier: ?int}>
	 */
	public function ordered(int $projectId, Market $market, int $offset, int $limit): array
	{
		return array_map(static fn (array $row): array => self::resolved($row), $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN s.id, s.public_id, s.market_keyword_id, s.active, s.tier, m.keyword
			FROM `{$this->table()}` s JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			WHERE s.project_id = %d AND s.active = 1 AND m.provider = %s AND m.location_code = %d AND m.language_code = %s
			ORDER BY s.tier IS NULL, s.tier, s.gsc_impressions IS NULL, s.gsc_impressions DESC, s.id LIMIT %d OFFSET %d",
			[$projectId, $market->provider, $market->locationCode, $market->languageCode, max(1, $limit), max(0, $offset)],
		));
	}

	/**
	 * Liderzy aktywnych, otwartych tematów (bez monitorowania) w kolejności Priorytetu Strategii — domyślna kwalifikacja analizy SERP
	 * od fazy C; pusta lista, gdy projekt nie ma jeszcze tematów.
	 *
	 * @return list<array{id: int, public_id: string, market_keyword_id: int, keyword: string, active: bool, tier: ?int}>
	 */
	public function topicLeaders(int $projectId, Market $market, int $offset, int $limit): array
	{
		return array_map(static fn (array $row): array => self::resolved($row), $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN s.id, s.public_id, s.market_keyword_id, s.active, s.tier, m.keyword
			FROM `{$this->db->table('strategy_topics')}` t
			JOIN `{$this->table()}` s ON s.project_id = t.project_id AND s.market_keyword_id = t.leader_market_keyword_id
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			WHERE t.project_id = %d AND t.active = 1 AND t.status IN ('new', 'review', 'planned', 'in_progress') AND (t.action IS NULL OR t.action <> 'monitor')
				AND s.active = 1 AND m.provider = %s AND m.location_code = %d AND m.language_code = %s
			ORDER BY t.priority IS NULL, t.priority DESC, t.confidence DESC, t.id LIMIT %d OFFSET %d",
			[$projectId, $market->provider, $market->locationCode, $market->languageCode, max(1, $limit), max(0, $offset)],
		));
	}

	/**
	 * Aktywni kandydaci dla listy SERP Intelligence (panel): stan zgodnego pomiaru według `serp_intel_at` (bez dekodowania dowodów
	 * w filtrze), temat i rola w temacie; kolejność — priorytet tematu, lider pierwszy, wolumen. Dowody tylko dla wierszy strony.
	 *
	 * @param string $filter `measured` (≤ 90 dni), `fresh` (≤ 30), `stale` (31–90), `missing` (brak albo > 90), `all`
	 * @return array{rows: list<array<string, mixed>>, total: int}
	 */
	public function serpCandidates(int $projectId, Market $market, string $filter, string $fresh, string $expired, int $offset, int $limit, bool $restricted = false): array
	{
		[$where, $params] = $this->serpFilter($filter, $fresh, $expired);
		$rows = $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN s.id, s.public_id, s.market_keyword_id, s.evidence, s.serp_intel_at, s.tracked_keyword_id, m.keyword, m.search_volume, m.search_intent,
				t.public_id AS topic_public_id, t.label AS topic_label, t.status AS topic_status, t.action AS topic_action,
				(t.leader_market_keyword_id = s.market_keyword_id) AS leader, COUNT(*) OVER () AS total_rows
			FROM `{$this->table()}` s
			JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			LEFT JOIN `{$this->db->table('strategy_topics')}` t ON t.id = s.topic_id AND t.project_id = s.project_id
			WHERE s.project_id = %d AND s.active = 1 AND m.provider = %s AND m.location_code = %d AND m.language_code = %s{$where}"
				. ($restricted ? " AND (t.status IS NULL OR t.status <> 'dismissed')" : '') . '
			ORDER BY t.priority IS NULL, t.priority DESC, leader DESC, m.search_volume IS NULL, m.search_volume DESC, s.id LIMIT %d OFFSET %d',
			[$projectId, $market->provider, $market->locationCode, $market->languageCode, ...$params, max(1, $limit), max(0, $offset)],
		);

		return [
			'rows' => array_map(static function (array $row): array {
				$evidence = json_decode((string) $row['evidence'], true);
				$row['evidence'] = is_array($evidence) ? $evidence : [];

				return $row;
			}, $rows),
			'total' => (int) ($rows[0]['total_rows'] ?? 0),
		];
	}

	/**
	 * Liczby aktywnych kandydatów według stanu zgodnego pomiaru SERP.
	 *
	 * @return array{fresh: int, stale: int, missing: int, all: int}
	 */
	public function serpCounts(int $projectId, string $fresh, string $expired, bool $restricted = false): array
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS n, COALESCE(SUM(s.serp_intel_at >= %s), 0) AS fresh, COALESCE(SUM(s.serp_intel_at >= %s AND s.serp_intel_at < %s), 0) AS stale
			FROM `{$this->table()}` s LEFT JOIN `{$this->db->table('strategy_topics')}` t ON t.id = s.topic_id AND t.project_id = s.project_id
			WHERE s.project_id = %d AND s.active = 1" . ($restricted ? " AND (t.status IS NULL OR t.status <> 'dismissed')" : ''),
			[$fresh, $expired, $fresh, $projectId],
		) ?? [];
		$all = (int) ($row['n'] ?? 0);

		return ['fresh' => (int) ($row['fresh'] ?? 0), 'stale' => (int) ($row['stale'] ?? 0), 'missing' => $all - (int) ($row['fresh'] ?? 0) - (int) ($row['stale'] ?? 0), 'all' => $all];
	}

	/**
	 * @return array{0: string, 1: list<string>}
	 */
	private function serpFilter(string $filter, string $fresh, string $expired): array
	{
		return match ($filter) {
			'fresh' => [' AND s.serp_intel_at >= %s', [$fresh]],
			'stale' => [' AND s.serp_intel_at >= %s AND s.serp_intel_at < %s', [$expired, $fresh]],
			'measured' => [' AND s.serp_intel_at >= %s', [$expired]],
			'missing' => [' AND (s.serp_intel_at IS NULL OR s.serp_intel_at < %s)', [$expired]],
			default => ['', []],
		};
	}

	/** Projekt ma aktywne tematy (faza C). */
	public function hasTopics(int $projectId): bool
	{
		return $this->db->fetchValue("SELECT 1 FROM `{$this->db->table('strategy_topics')}` WHERE project_id = %d AND active = 1 LIMIT 1", [$projectId]) !== null;
	}

	/**
	 * @param array<string, string|null> $row
	 * @return array{id: int, public_id: string, market_keyword_id: int, keyword: string, active: bool, tier: ?int}
	 */
	private static function resolved(array $row): array
	{
		return [
			'id' => (int) $row['id'],
			'public_id' => (string) $row['public_id'],
			'market_keyword_id' => (int) $row['market_keyword_id'],
			'keyword' => (string) $row['keyword'],
			'active' => (int) $row['active'] === 1,
			'tier' => $row['tier'] === null ? null : (int) $row['tier'],
		];
	}

	/**
	 * Dowody kandydatów projektu (ULID → zdekodowany JSON) — do kontekstu tematu.
	 *
	 * @param list<string> $publicIds
	 * @return array<string, array<string, mixed>>
	 */
	public function evidence(int $projectId, array $publicIds): array
	{
		$result = [];

		foreach (array_chunk(array_values(array_unique($publicIds)), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT public_id, evidence FROM `{$this->table()}` WHERE project_id = %d AND public_id IN (" . Connection::placeholders($chunk) . ')',
				[$projectId, ...$chunk],
			) as $row) {
				$decoded = json_decode((string) $row['evidence'], true);
				$result[(string) $row['public_id']] = is_array($decoded) ? $decoded : [];
			}
		}

		return $result;
	}

	/**
	 * Kandydaci projektu (aktywni i nieaktywni) na rynku: id frazy rynkowej → klucz rynkowy (hex).
	 *
	 * @return array<int, string>
	 */
	public function marketKeys(int $projectId, Market $market): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN s.market_keyword_id, LOWER(HEX(m.keyword_key)) AS h
			FROM `{$this->table()}` s JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			WHERE s.project_id = %d AND m.provider = %s AND m.location_code = %d AND m.language_code = %s",
			[$projectId, $market->provider, $market->locationCode, $market->languageCode],
		) as $row) {
			$result[(int) $row['market_keyword_id']] = (string) $row['h'];
		}

		return $result;
	}

	/**
	 * Liczby kandydatów projektu: aktywni, ręczni, nieaktywni wg powodu.
	 *
	 * @return array{active: int, manual: int, inactive: array<string, int>}
	 */
	public function counts(int $projectId): array
	{
		$result = ['active' => 0, 'manual' => 0, 'inactive' => []];

		foreach ($this->db->fetchAll(
			"SELECT active, inactive_reason, COUNT(*) AS n, SUM(manual) AS manual FROM `{$this->table()}` WHERE project_id = %d GROUP BY active, inactive_reason",
			[$projectId],
		) as $row) {
			if ((int) $row['active'] === 1) {
				$result['active'] += (int) $row['n'];
				$result['manual'] += (int) $row['manual'];
			} else {
				$result['inactive'][(string) ($row['inactive_reason'] ?? self::REASON_NO_SOURCE)] = (int) $row['n'];
			}
		}

		ksort($result['inactive']);

		return $result;
	}

	/**
	 * Istniejący kandydaci projektu: id frazy rynkowej → stan (czy fraza należy do bieżącego rynku projektu).
	 *
	 * @return array<int, array{id: int, public_id: string, active: bool, reason: ?string, hash: ?string, in_market: bool}>
	 */
	private function existing(int $projectId, Market $market): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN s.id, s.public_id, s.market_keyword_id, s.active, s.inactive_reason, LOWER(HEX(s.facts_hash)) AS h,
				(m.provider = %s AND m.location_code = %d AND m.language_code = %s) AS in_market
			FROM `{$this->table()}` s JOIN `{$this->db->table('market_keywords')}` m ON m.id = s.market_keyword_id
			WHERE s.project_id = %d",
			[$market->provider, $market->locationCode, $market->languageCode, $projectId],
		) as $row) {
			$result[(int) $row['market_keyword_id']] = [
				'id' => (int) $row['id'],
				'public_id' => (string) $row['public_id'],
				'active' => (int) $row['active'] === 1,
				'reason' => $row['inactive_reason'],
				'hash' => $row['h'],
				'in_market' => (int) $row['in_market'] === 1,
			];
		}

		return $result;
	}

	private function table(): string
	{
		return $this->db->table('strategy_keywords');
	}
}
