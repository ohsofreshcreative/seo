<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Support\Clock;

/**
 * Wspólne zbiory fraz domen (`gap_domains`), ich bieżący stan (`gap_domain_keywords`) i historia zmian
 * (`gap_domain_events`).
 *
 * Import strona po stronie wyłącznie dopisuje i aktualizuje wiersze — nic nie jest usuwane. Fraza znika z zakresu
 * (`present = 0`, zdarzenie `lost`) dopiero po zakończonym imporcie, który wiarygodnie objął jej wolumen (z zapasem);
 * blisko granicy wolumenu staje się niepotwierdzona (`present = 2`, bez zdarzenia). Import przerwany, wstrzymany albo
 * niespójny (dublowanie fraz między stronami, zmiana liczby fraz u dostawcy, krótka strona…) niczego nie oznacza jako
 * utraconego, a zbiór zachowuje dane i wiarygodność poprzedniego udanego importu. Pierwszy import zbioru nie zapisuje
 * zdarzeń (to stan wyjściowy, nie zmiana).
 */
final class GapDomainRepository
{
	private const CHUNK = 1000;

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	public function today(): string
	{
		return $this->clock->now()->format('Y-m-d');
	}

	public function find(Market $market, string $domain): ?GapDomain
	{
		$row = $this->db->fetchRow(
			"SELECT * FROM `{$this->table()}` WHERE provider = %s AND location_code = %d AND language_code = %s AND domain_key = UNHEX(%s)",
			[$market->provider, $market->locationCode, $market->languageCode, md5($domain)],
		);

		return $row === null ? null : GapDomain::fromRow($row);
	}

	/** Zbiór domeny na rynku — tworzony przy pierwszym planowanym imporcie (pusty, bez danych). */
	public function ensure(Market $market, string $domain): GapDomain
	{
		$existing = $this->find($market, $domain);

		if ($existing !== null) {
			return $existing;
		}

		$now = $this->now();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (provider, location_code, language_code, domain, domain_key, status, created_at, updated_at)
			VALUES (%s, %d, %s, %s, UNHEX(%s), 'empty', %s, %s) ON DUPLICATE KEY UPDATE id = id",
			[$market->provider, $market->locationCode, $market->languageCode, $domain, md5($domain), $now, $now],
		);

		return $this->find($market, $domain) ?? throw new \RuntimeException('Gap domain was not created.');
	}

	public function findById(int $id): ?GapDomain
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE id = %d", [$id]);

		return $row === null ? null : GapDomain::fromRow($row);
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, GapDomain>
	 */
	public function findByIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

		if ($ids === []) {
			return [];
		}

		$result = [];

		foreach ($this->db->fetchAll("SELECT * FROM `{$this->table()}` WHERE id IN (" . Connection::placeholders($ids, '%d') . ')', $ids) as $row) {
			$domain = GapDomain::fromRow($row);
			$result[$domain->id] = $domain;
		}

		return $result;
	}

	/**
	 * Zbiory domen rynku po nazwach domen (plan, raporty).
	 *
	 * @param list<string> $domains
	 * @return array<string, GapDomain> domena → zbiór
	 */
	public function forDomains(Market $market, array $domains): array
	{
		$keys = array_values(array_unique(array_map('md5', $domains)));

		if ($keys === []) {
			return [];
		}

		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT * FROM `{$this->table()}` WHERE provider = %s AND location_code = %d AND language_code = %s AND domain_key IN (" . Connection::placeholders($keys, 'UNHEX(%s)') . ')',
			[$market->provider, $market->locationCode, $market->languageCode, ...$keys],
		) as $row) {
			$domain = GapDomain::fromRow($row);
			$result[$domain->domain] = $domain;
		}

		return $result;
	}

	/**
	 * Przejęcie importu zbioru przez przebieg — atomowo: nie uda się, gdy zbiór importuje właśnie inny przebieg (dwa
	 * projekty z tą samą domeną nie zapłacą dwa razy ani nie przeplotą zapisów). Zwraca, czy przebieg importuje zbiór.
	 */
	public function claimImport(int $domainId, int $runId): bool
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'importing', import_run_id = %d, updated_at = %s
			WHERE id = %d AND (status <> 'importing' OR import_run_id IS NULL OR import_run_id = %d)",
			[$runId, $this->now(), $domainId, $runId],
		);

		return (int) $this->db->fetchValue(
			"SELECT COUNT(*) FROM `{$this->table()}` WHERE id = %d AND status = 'importing' AND import_run_id = %d",
			[$domainId, $runId],
		) === 1;
	}

	/**
	 * Zapis strony importu. Dla każdej frazy: pozycja, URL, data migawki Labs, daty pierwszego/ostatniego wykrycia
	 * i poprzednia pozycja; zdarzenia (gdy zbiór miał wcześniejszy import): nowa w zakresie, powrót, zmiana URL,
	 * istotna zmiana pozycji. Fraza już zapisana w tym imporcie (przesunięcie stron u dostawcy) liczy się jako duplikat —
	 * zostaje lepsza pozycja, a import nie będzie uznany za wiarygodny dla nieobecności.
	 *
	 * @param list<array{market_keyword_id: int, rank_group: int, rank_absolute: ?int, url_id: ?int, etv: ?float, serp_on: ?string, volume: ?int}> $entries
	 * @return array{new: int, changed: int, duplicates: int}
	 */
	public function storePage(GapDomain $domain, int $runId, array $entries): array
	{
		$result = ['new' => 0, 'changed' => 0, 'duplicates' => 0];

		if ($entries === []) {
			return $result;
		}

		$today = $this->today();
		$events = $domain->wasImported();
		$existing = $this->existing($domain->id, array_map(static fn (array $entry): int => $entry['market_keyword_id'], $entries));
		$upsert = new BulkInsert(
			$this->db,
			$this->rowsTable(),
			['domain_id', 'market_keyword_id', 'rank_group', 'rank_absolute', 'url_id', 'etv', 'serp_on', 'first_seen', 'last_seen', 'seen_run_id', 'prev_rank', 'changed_on', 'present'],
			['%d', '%d', '%d', 'NULLIF(%d, 0)', 'NULLIF(%d, 0)', "NULLIF(%s, '')", "NULLIF(%s, '')", '%s', '%s', '%d', 'NULLIF(%d, 0)', "NULLIF(%s, '')", '%d'],
			'ON DUPLICATE KEY UPDATE rank_group = VALUES(rank_group), rank_absolute = VALUES(rank_absolute), url_id = VALUES(url_id), etv = VALUES(etv),
				serp_on = VALUES(serp_on), first_seen = VALUES(first_seen), last_seen = VALUES(last_seen), seen_run_id = VALUES(seen_run_id),
				prev_rank = VALUES(prev_rank), changed_on = VALUES(changed_on), present = VALUES(present)',
		);
		$history = new BulkInsert(
			$this->db,
			$this->eventsTable(),
			['domain_id', 'market_keyword_id', 'run_id', 'event', 'rank_old', 'rank_new', 'url_old', 'url_new', 'observed_on'],
			['%d', '%d', '%d', '%s', 'NULLIF(%d, 0)', 'NULLIF(%d, 0)', 'NULLIF(%d, 0)', 'NULLIF(%d, 0)', '%s'],
			'ON DUPLICATE KEY UPDATE event = event',
		);
		$seen = [];

		foreach ($entries as $entry) {
			$id = $entry['market_keyword_id'];
			$old = $existing[$id] ?? null;

			if (isset($seen[$id]) || ($old !== null && (int) $old['seen_run_id'] === $runId)) {
				$result['duplicates']++;
				$best = $seen[$id] ?? $old;

				if ($best !== null && (int) $best['rank_group'] <= $entry['rank_group']) {
					continue;
				}
			}

			$rank = $entry['rank_group'];
			$url = $entry['url_id'];
			$prevRank = $old === null || $old['prev_rank'] === null ? 0 : (int) $old['prev_rank'];
			$changedOn = $old === null ? '' : (string) $old['changed_on'];

			if ($old !== null && (int) $old['seen_run_id'] !== $runId) {
				$oldRank = (int) $old['rank_group'];

				if ((int) $old['present'] === GapDomain::ROW_PRESENT && $oldRank !== $rank) {
					$prevRank = $oldRank;
					$changedOn = $today;
				}

				if ($events) {
					$event = $this->event($old, $rank, $url);

					if ($event !== null) {
						$history->add([$domain->id, $id, $runId, $event, $oldRank, $rank, (int) $old['url_id'], (int) $url, $today]);
						$result['changed'] += in_array($event, ['up', 'down', 'url'], true) ? 1 : 0;
					}
				}
			} elseif ($old === null) {
				$result['new']++;

				if ($events && $domain->coverage !== null && $rank <= $domain->coverage->maxRank && $domain->absenceReliable($entry['volume'])) {
					$history->add([$domain->id, $id, $runId, 'new', 0, $rank, 0, (int) $url, $today]);
				}
			}

			$row = [
				$domain->id,
				$id,
				$rank,
				(int) $entry['rank_absolute'],
				(int) $url,
				$entry['etv'] === null ? '' : number_format($entry['etv'], 2, '.', ''),
				(string) $entry['serp_on'],
				$old === null ? $today : (string) $old['first_seen'],
				$today,
				$runId,
				$prevRank,
				$changedOn,
				1,
			];
			$upsert->add($row);
			$seen[$id] = ['rank_group' => $rank, 'seen_run_id' => $runId];
		}

		$this->db->transaction(static function () use ($upsert, $history): void {
			$upsert->flush();
			$history->flush();
		});

		return $result;
	}

	/**
	 * Strony domeny ze zbioru: tytuł wyniku (z najlepiej ocenionej frazy strony) i data ostatniego wykrycia.
	 *
	 * @param array<int, string> $titles id adresu → tytuł
	 */
	public function storePages(int $domainId, array $titles): void
	{
		if ($titles === []) {
			return;
		}

		$today = $this->today();
		$upsert = new BulkInsert(
			$this->db,
			$this->db->table('gap_domain_pages'),
			['domain_id', 'url_id', 'title', 'last_seen'],
			['%d', '%d', "NULLIF(%s, '')", '%s'],
			'ON DUPLICATE KEY UPDATE title = COALESCE(VALUES(title), title), last_seen = VALUES(last_seen)',
		);

		foreach ($titles as $urlId => $title) {
			$upsert->add([$domainId, $urlId, mb_substr($title, 0, 512, 'UTF-8'), $today]);
		}

		$upsert->flush();
	}

	/**
	 * Zakończenie importu (wszystkie strony zakresu albo limit fraz): kompletność, granica wiarygodnej nieobecności,
	 * frazy utracone z zakresu, statystyki i świeżość zbioru. Import niespójny (`$unreliable`) zapisuje pobrane frazy, ale
	 * nie oznacza żadnej jako utraconej ani niepotwierdzonej i nie daje wiarygodnej nieobecności.
	 *
	 * @return array{rows_unique: int, rows_lost: int, rows_unconfirmed: int, complete: bool, covered_min_volume: ?int, stats: array<string, int|float>}
	 */
	public function finishImport(GapDomain $domain, int $runId, Coverage $coverage, int $totalCount, ?string $unreliable, ?int $lastVolume, int $ttlDays): array
	{
		$truncated = $totalCount > $coverage->maxRows;
		$reliable = $unreliable === null;
		$complete = ! $truncated && $reliable;
		$coveredMin = $complete ? $coverage->minVolume : ($reliable && $lastVolume !== null ? max($coverage->minVolume, $lastVolume + 1) : null);
		[$lost, $unconfirmed] = $coveredMin === null ? [0, 0] : $this->markLost($domain, $runId, $coverage->maxRank, $coveredMin);
		$now = $this->clock->now();
		$stats = $this->stats($domain->id, $runId);

		$this->db->update($this->table(), [
			'status' => GapDomain::READY,
			'coverage_max_rank' => $coverage->maxRank,
			'coverage_min_volume' => $coverage->minVolume,
			'coverage_max_rows' => $coverage->maxRows,
			'complete' => $complete ? 1 : 0,
			'covered_min_volume' => $coveredMin,
			'total_count' => $totalCount,
			'rows_present' => $this->present($domain->id),
			'labs_updated_at' => $this->labsUpdated($domain, $runId),
			'import_run_id' => $runId,
			'imported_at' => $now->format('Y-m-d H:i:s'),
			'stale_after' => $now->modify('+' . max(1, $ttlDays) . ' days')->format('Y-m-d H:i:s'),
			'updated_at' => $now->format('Y-m-d H:i:s'),
		], ['id' => $domain->id]);

		return ['rows_unique' => (int) $stats['rows'], 'rows_lost' => $lost, 'rows_unconfirmed' => $unconfirmed, 'complete' => $complete, 'covered_min_volume' => $coveredMin, 'stats' => $stats];
	}

	/**
	 * Import przerwany (limit kosztów, anulowanie, błąd): dotychczasowe dane zostają, nic nie jest oznaczane jako utracone.
	 * Zbiór z wcześniejszym udanym importem zachowuje jego zakres, kompletność, granicę wiarygodnej nieobecności i świeżość;
	 * pierwszy import zapisuje to, co pobrał (frazy o największym wolumenie), i od razu jest nieaktualny (kolejny plan
	 * pobierze go ponownie).
	 *
	 * @return array{rows_unique: int, stats: array<string, int|float>}
	 */
	public function abortImport(GapDomain $domain, int $runId, Coverage $coverage, ?int $totalCount, ?string $unreliable, ?int $lastVolume): array
	{
		$now = $this->now();
		$stats = $this->stats($domain->id, $runId);

		if ($domain->wasImported()) {
			$this->db->update($this->table(), ['status' => GapDomain::PARTIAL, 'rows_present' => $this->present($domain->id), 'updated_at' => $now], ['id' => $domain->id]);
		} else {
			$this->db->update($this->table(), [
				'status' => GapDomain::PARTIAL,
				'coverage_max_rank' => $coverage->maxRank,
				'coverage_min_volume' => $coverage->minVolume,
				'coverage_max_rows' => $coverage->maxRows,
				'complete' => 0,
				'covered_min_volume' => $unreliable === null && $lastVolume !== null && (int) $stats['rows'] > 0 ? max($coverage->minVolume, $lastVolume + 1) : null,
				'total_count' => $totalCount,
				'rows_present' => $this->present($domain->id),
				'labs_updated_at' => $this->labsUpdated($domain, $runId),
				'import_run_id' => $runId,
				'imported_at' => $now,
				'stale_after' => $now,
				'updated_at' => $now,
			], ['id' => $domain->id]);
		}

		return ['rows_unique' => (int) $stats['rows'], 'stats' => $stats];
	}

	/**
	 * Historia zmian frazy w zbiorach domen (szczegóły luki).
	 *
	 * @param list<int> $domainIds
	 * @return list<array<string, string|null>>
	 */
	public function events(array $domainIds, int $marketKeywordId, int $limit = 50): array
	{
		$domainIds = array_values(array_unique(array_map('intval', $domainIds)));

		if ($domainIds === []) {
			return [];
		}

		return $this->db->fetchAll(
			"SELECT e.domain_id, e.event, e.rank_old, e.rank_new, e.observed_on, uo.url AS url_old, un.url AS url_new
			FROM `{$this->eventsTable()}` e
			LEFT JOIN `{$this->db->table('serp_urls')}` uo ON uo.id = e.url_old
			LEFT JOIN `{$this->db->table('serp_urls')}` un ON un.id = e.url_new
			WHERE e.domain_id IN (" . Connection::placeholders($domainIds, '%d') . ') AND e.market_keyword_id = %d
			ORDER BY e.observed_on DESC, e.run_id DESC LIMIT %d',
			[...$domainIds, $marketKeywordId, max(1, $limit)],
		);
	}

	/**
	 * Zdarzenia ostatniego importu zbioru (liczby wg typu).
	 *
	 * @return array<string, int>
	 */
	public function eventCounts(int $domainId, int $runId): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT event, COUNT(*) AS n FROM `{$this->eventsTable()}` WHERE domain_id = %d AND run_id = %d GROUP BY event",
			[$domainId, $runId],
		) as $row) {
			$result[(string) $row['event']] = (int) $row['n'];
		}

		return $result;
	}

	/**
	 * Historia importów zbioru domeny (data, liczby, koszt) — trend konkurenta.
	 *
	 * @return list<array<string, string|null>>
	 */
	public function imports(int $domainId, int $limit = 12): array
	{
		return $this->db->fetchAll(
			"SELECT t.run_id, t.status, t.total_count, t.rows_unique, t.rows_new, t.rows_lost, t.rows_changed, t.cost, t.stats, t.finished_at
			FROM `{$this->db->table('gap_run_targets')}` t
			WHERE t.domain_id = %d AND t.finished_at IS NOT NULL AND t.status IN ('done', 'partial')
			ORDER BY t.finished_at DESC LIMIT %d",
			[$domainId, max(1, $limit)],
		);
	}

	/**
	 * @param array<string, int|string|null> $old
	 */
	private function event(array $old, int $rank, ?int $url): ?string
	{
		if ((int) $old['present'] === GapDomain::ROW_LOST) {
			return 'back';
		}

		if ((int) $old['present'] === GapDomain::ROW_UNCONFIRMED) {
			// Bez zapisanej utraty nie ma „powrotu”, a pozycja sprzed niepotwierdzonego importu nie jest punktem odniesienia.
			return null;
		}

		$oldRank = (int) $old['rank_group'];

		if (abs($oldRank - $rank) >= GapConfig::EVENT_MIN_DELTA || self::band($oldRank) !== self::band($rank)) {
			return $rank < $oldRank ? 'up' : 'down';
		}

		if ($old['url_id'] !== null && $url !== null && (int) $old['url_id'] !== $url) {
			return 'url';
		}

		return null;
	}

	public static function band(int $rank): int
	{
		foreach (GapConfig::BANDS as $band) {
			if ($rank <= $band) {
				return $band;
			}
		}

		return 101;
	}

	/**
	 * Frazy zbioru niewidziane w zakończonym, wiarygodnym imporcie (w jego zakresie pozycji i wolumenu): z wolumenem
	 * z zapasem nad granicą → utracone (`lost`); tuż nad granicą → niepotwierdzone, bez zdarzenia (mogły wypaść z filtra
	 * wolumenu, a nie z pozycji).
	 *
	 * @return array{0: int, 1: int} utracone, niepotwierdzone
	 */
	private function markLost(GapDomain $domain, int $runId, int $maxRank, int $coveredMin): array
	{
		$where = 'dk.domain_id = %d AND dk.present = %d AND dk.seen_run_id <> %d AND dk.rank_group <= %d AND m.search_volume >= %d';
		$reliable = GapDomain::reliableVolume($coveredMin);
		$lost = [$domain->id, GapDomain::ROW_PRESENT, $runId, $maxRank, $reliable];
		$unconfirmed = [$domain->id, GapDomain::ROW_PRESENT, $runId, $maxRank, $coveredMin];
		$today = $this->today();

		return $this->db->transaction(function () use ($domain, $where, $lost, $unconfirmed, $runId, $today): array {
			if ($domain->wasImported()) {
				$this->db->execute(
					"INSERT INTO `{$this->eventsTable()}` (domain_id, market_keyword_id, run_id, event, rank_old, rank_new, url_old, url_new, observed_on)
					SELECT dk.domain_id, dk.market_keyword_id, %d, 'lost', dk.rank_group, NULL, dk.url_id, NULL, %s
					FROM `{$this->rowsTable()}` dk JOIN `{$this->db->table('market_keywords')}` m ON m.id = dk.market_keyword_id
					WHERE {$where} ON DUPLICATE KEY UPDATE event = event",
					[$runId, $today, ...$lost],
				);
			}

			$marked = $this->db->execute(
				"UPDATE `{$this->rowsTable()}` dk JOIN `{$this->db->table('market_keywords')}` m ON m.id = dk.market_keyword_id
				SET dk.present = %d, dk.prev_rank = dk.rank_group, dk.changed_on = %s
				WHERE {$where}",
				[GapDomain::ROW_LOST, $today, ...$lost],
			);
			$doubtful = $this->db->execute(
				"UPDATE `{$this->rowsTable()}` dk JOIN `{$this->db->table('market_keywords')}` m ON m.id = dk.market_keyword_id
				SET dk.present = %d
				WHERE {$where}",
				[GapDomain::ROW_UNCONFIRMED, ...$unconfirmed],
			);

			return [(int) $marked, (int) $doubtful];
		});
	}

	/**
	 * @return array{rows: int, top3: int, top10: int, top20: int, top50: int, etv: float}
	 */
	private function stats(int $domainId, int $runId): array
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS n, COALESCE(SUM(rank_group <= 3), 0) AS top3, COALESCE(SUM(rank_group <= 10), 0) AS top10,
				COALESCE(SUM(rank_group <= 20), 0) AS top20, COALESCE(SUM(rank_group <= 50), 0) AS top50, COALESCE(SUM(etv), 0) AS etv
			FROM `{$this->rowsTable()}` WHERE domain_id = %d AND seen_run_id = %d",
			[$domainId, $runId],
		) ?? [];

		return [
			'rows' => (int) ($row['n'] ?? 0),
			'top3' => (int) ($row['top3'] ?? 0),
			'top10' => (int) ($row['top10'] ?? 0),
			'top20' => (int) ($row['top20'] ?? 0),
			'top50' => (int) ($row['top50'] ?? 0),
			'etv' => round((float) ($row['etv'] ?? 0), 2),
		];
	}

	/** Najnowsza data migawki Labs wśród fraz importu (albo poprzednia wartość zbioru). */
	private function labsUpdated(GapDomain $domain, int $runId): ?string
	{
		$date = $this->db->fetchValue("SELECT MAX(serp_on) FROM `{$this->rowsTable()}` WHERE domain_id = %d AND seen_run_id = %d", [$domain->id, $runId]);

		return $date === null ? $domain->labsUpdatedAt : $date . ' 00:00:00';
	}

	private function present(int $domainId): int
	{
		return (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$this->rowsTable()}` WHERE domain_id = %d AND present = 1", [$domainId]);
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, array<string, string|null>>
	 */
	private function existing(int $domainId, array $ids): array
	{
		$result = [];

		foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT market_keyword_id, rank_group, url_id, first_seen, seen_run_id, prev_rank, changed_on, present
				FROM `{$this->rowsTable()}` WHERE domain_id = %d AND market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$domainId, ...$chunk],
			) as $row) {
				$result[(int) $row['market_keyword_id']] = $row;
			}
		}

		return $result;
	}

	private function table(): string
	{
		return $this->db->table('gap_domains');
	}

	private function rowsTable(): string
	{
		return $this->db->table('gap_domain_keywords');
	}

	private function eventsTable(): string
	{
		return $this->db->table('gap_domain_events');
	}
}
