<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Serp\SerpItem;
use OsfSeo\Support\Clock;

/**
 * Profile pomiarów (`serp_snapshot_profiles`) — liczone leniwie z zapisanych wyników STEP 14 (bez żądań do API i bez kopii wyników)
 * i zapisywane raz na wersję reguł (`SerpProfiler::VERSION`). Wyłącznie zakończone pomiary danego projektu.
 */
final class SerpProfileRepository
{
	private const CHUNK = 200;

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
		private readonly SerpProfiler $profiler = new SerpProfiler(),
	) {
	}

	/**
	 * Profile pomiarów projektu: zapisane w bieżącej wersji albo policzone teraz (zapis — chyba że `$persist = false`, np. w podglądzie).
	 *
	 * @param list<int> $snapshotIds
	 * @return array<int, SerpProfile> id pomiaru → profil
	 */
	public function ensure(int $projectId, array $snapshotIds, bool $persist = true): array
	{
		$snapshotIds = array_values(array_unique(array_map('intval', $snapshotIds)));
		$profiles = [];

		foreach (array_chunk($snapshotIds, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT * FROM `{$this->table()}` WHERE project_id = %d AND version = %d AND snapshot_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$projectId, SerpProfiler::VERSION, ...$chunk],
			) as $row) {
				$profiles[(int) $row['snapshot_id']] = SerpProfile::fromRow($row);
			}
		}

		$missing = array_values(array_diff($snapshotIds, array_keys($profiles)));

		if ($missing === []) {
			return $profiles;
		}

		$computed = $this->compute($projectId, $missing);

		if ($persist && $computed !== []) {
			$this->store($projectId, $computed);
		}

		return $profiles + $computed;
	}

	/**
	 * @param list<int> $snapshotIds
	 * @return array<int, SerpProfile>
	 */
	private function compute(int $projectId, array $snapshotIds): array
	{
		$result = [];

		foreach (array_chunk($snapshotIds, self::CHUNK) as $chunk) {
			$snapshots = [];

			foreach ($this->db->fetchAll(
				"SELECT id, item_types FROM `{$this->db->table('serp_snapshots')}`
				WHERE project_id = %d AND status = 'completed' AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$projectId, ...$chunk],
			) as $row) {
				$snapshots[(int) $row['id']] = ['item_types' => (int) $row['item_types'], 'organic' => []];
			}

			if ($snapshots === []) {
				continue;
			}

			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN r.snapshot_id, r.rank_group, r.flags, d.host, u.url, sn.extra
				FROM `{$this->db->table('serp_results')}` r
				JOIN `{$this->db->table('serp_domains')}` d ON d.id = r.domain_id
				JOIN `{$this->db->table('serp_urls')}` u ON u.id = r.url_id
				LEFT JOIN `{$this->db->table('serp_snippets')}` sn ON sn.id = r.snippet_id
				WHERE r.snapshot_id IN (" . Connection::placeholders(array_keys($snapshots), '%d') . ') AND r.result_type = %d AND r.rank_group <= 20',
				[...array_keys($snapshots), SerpItem::TYPE_ORGANIC],
			) as $row) {
				$extra = $row['extra'] === null ? null : json_decode((string) $row['extra'], true);
				$snapshots[(int) $row['snapshot_id']]['organic'][] = [
					'rank' => (int) $row['rank_group'],
					'host' => (string) $row['host'],
					'url' => (string) $row['url'],
					'flags' => (int) $row['flags'],
					'published_at' => is_array($extra) && is_string($extra['published_at'] ?? null) ? $extra['published_at'] : null,
				];
			}

			foreach ($snapshots as $id => $snapshot) {
				$result[$id] = $this->profiler->profile($id, $snapshot['organic'], $snapshot['item_types']);
			}
		}

		return $result;
	}

	/**
	 * @param array<int, SerpProfile> $profiles
	 */
	private function store(int $projectId, array $profiles): void
	{
		$now = $this->clock->now()->format('Y-m-d H:i:s');
		$columns = ['snapshot_id', 'project_id', 'version', 'organic_top10', 'organic_top20', 'domains_top10', 'domains_top20', 'top_domain_top10', 'home_top10',
			'shape', 'shape_share', 'shape_confidence', 'intent', 'intent_confidence', 'composition', 'created_at'];
		$insert = new BulkInsert(
			$this->db,
			$this->table(),
			$columns,
			['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', "NULLIF(%s, '')", '%s', '%s', '%s', '%s', '%s'],
			'ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $column): string => "{$column} = VALUES({$column})", array_slice($columns, 2))),
			100,
		);

		foreach ($profiles as $profile) {
			$row = $profile->toRow();
			$insert->add([
				$row['snapshot_id'],
				$projectId,
				$row['version'],
				$row['organic_top10'],
				$row['organic_top20'],
				$row['domains_top10'],
				$row['domains_top20'],
				$row['top_domain_top10'],
				$row['home_top10'],
				$row['shape'],
				$row['shape_share'] === null ? '' : number_format((float) $row['shape_share'], 3, '.', ''),
				$row['shape_confidence'],
				$row['intent'],
				$row['intent_confidence'],
				$row['composition'],
				$now,
			]);
		}

		$insert->flush();
	}

	private function table(): string
	{
		return $this->db->table('serp_snapshot_profiles');
	}
}
