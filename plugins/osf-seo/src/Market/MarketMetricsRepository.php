<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Metryki rynkowe fraz (`market_keywords`) i historia miesięczna (`market_keyword_monthly`).
 *
 * Dane są wspólne dla rynku (dostawca × lokalizacja × język), nie dla projektu: dwa projekty na tym samym rynku
 * korzystają z jednego odczytu, a reset property projektu (usunięcie słownika GSC) ich nie usuwa.
 * Zapis paczkami: istniejące wiersze po kluczu (paczki IN po 500), brakujące — jednym INSERT-em,
 * aktualizacja metryk — `INSERT … ON DUPLICATE KEY UPDATE` po jawnym `id` (bez zużywania AUTO_INCREMENT).
 */
final class MarketMetricsRepository
{
	private const LOOKUP_CHUNK = 500;

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	/**
	 * Zapewnia wiersze dla fraz rynku (bez metryk). Zwraca postać znormalizowaną → id.
	 *
	 * @param list<string> $keywords postacie znormalizowane
	 * @return array<string, int>
	 */
	public function ensure(Market $market, array $keywords): array
	{
		$byKey = [];

		foreach ($keywords as $keyword) {
			$keyword = (string) $keyword;
			$byKey[bin2hex(MarketKeyword::key($keyword))] = $keyword;
		}

		if ($byKey === []) {
			return [];
		}

		$ids = $this->idsByKeys($market, array_keys($byKey));
		$missing = array_diff_key($byKey, $ids);

		if ($missing !== []) {
			$now = $this->now();
			$insert = new BulkInsert(
				$this->db,
				$this->db->table('market_keywords'),
				['provider', 'location_code', 'language_code', 'keyword_key', 'keyword', 'created_at', 'updated_at'],
				['%s', '%d', '%s', 'UNHEX(%s)', '%s', '%s', '%s'],
				'ON DUPLICATE KEY UPDATE id = id',
			);

			foreach ($missing as $hex => $keyword) {
				$insert->add([$market->provider, $market->locationCode, $market->languageCode, $hex, mb_substr($keyword, 0, 255, 'UTF-8'), $now, $now]);
			}

			$insert->flush();
			$ids += $this->idsByKeys($market, array_keys($missing));
		}

		$result = [];

		foreach ($byKey as $hex => $keyword) {
			if (isset($ids[$hex])) {
				$result[$keyword] = $ids[$hex];
			}
		}

		return $result;
	}

	/**
	 * Frazy zlecone w zadaniu wolumenu (Standard) — nie są zlecane ponownie do czasu wyniku lub `$until`.
	 *
	 * @param list<int> $ids
	 */
	public function markVolumePending(array $ids, string $until, int $taskId): void
	{
		foreach (array_chunk($ids, self::LOOKUP_CHUNK) as $chunk) {
			$this->db->execute(
				"UPDATE `{$this->table()}` SET volume_pending_until = %s, volume_task_id = %d, updated_at = %s
				WHERE id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$until, $taskId, $this->now(), ...$chunk],
			);
		}
	}

	/**
	 * Zwolnienie fraz zadania, które się nie powiodło lub przeterminowało (mogą zostać zlecone ponownie).
	 *
	 * @param list<string> $keywords postacie znormalizowane
	 */
	public function releaseVolumePending(Market $market, array $keywords, int $taskId): void
	{
		foreach (array_chunk(self::hexKeys($keywords), self::LOOKUP_CHUNK) as $chunk) {
			$this->db->execute(
				"UPDATE `{$this->table()}` SET volume_pending_until = NULL, updated_at = %s
				WHERE provider = %s AND location_code = %d AND language_code = %s AND volume_task_id = %d
					AND keyword_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				[$this->now(), $market->provider, $market->locationCode, $market->languageCode, $taskId, ...$chunk],
			);
		}
	}

	/**
	 * Zapis wolumenu dla fraz zadania. Fraza bez wyniku = pobrana bez danych (null), z tym samym TTL —
	 * dzięki temu nie jest opłacana ponownie przy każdym przebiegu.
	 *
	 * @param list<string> $requested postacie znormalizowane wysłane do dostawcy
	 * @param array<string, VolumeMetrics> $metrics postać znormalizowana → wynik
	 * @return int liczba zapisanych fraz
	 */
	public function storeVolume(Market $market, array $requested, array $metrics, int $taskId, int $ttlDays): int
	{
		$ids = $this->ensure($market, $requested);
		$now = $this->now();
		$staleAfter = $this->clock->now()->modify('+' . $ttlDays . ' days')->format('Y-m-d H:i:s');
		$upsert = new BulkInsert(
			$this->db,
			$this->table(),
			['id', 'provider', 'location_code', 'language_code', 'keyword_key', 'keyword', 'search_volume', 'cpc', 'competition_level', 'competition_index',
				'low_top_of_page_bid', 'high_top_of_page_bid', 'volume_fetched_at', 'volume_stale_after', 'volume_task_id', 'created_at', 'updated_at'],
			['%d', '%s', '%d', '%s', 'UNHEX(%s)', '%s', "NULLIF(%s, '')", "NULLIF(%s, '')", "NULLIF(%s, '')", "NULLIF(%s, '')",
				"NULLIF(%s, '')", "NULLIF(%s, '')", '%s', '%s', '%d', '%s', '%s'],
			'ON DUPLICATE KEY UPDATE search_volume = VALUES(search_volume), cpc = VALUES(cpc), competition_level = VALUES(competition_level),
				competition_index = VALUES(competition_index), low_top_of_page_bid = VALUES(low_top_of_page_bid),
				high_top_of_page_bid = VALUES(high_top_of_page_bid), volume_fetched_at = VALUES(volume_fetched_at),
				volume_stale_after = VALUES(volume_stale_after), volume_pending_until = NULL, volume_task_id = VALUES(volume_task_id),
				updated_at = VALUES(updated_at)',
		);
		$monthly = new BulkInsert(
			$this->db,
			$this->db->table('market_keyword_monthly'),
			['market_keyword_id', 'month', 'search_volume', 'updated_at'],
			['%d', '%s', "NULLIF(%s, '')", '%s'],
			'ON DUPLICATE KEY UPDATE search_volume = VALUES(search_volume), updated_at = VALUES(updated_at)',
		);

		foreach ($ids as $keyword => $id) {
			// Klucze tablic PHP zamieniają frazy liczbowe („2024”) na int.
			$keyword = (string) $keyword;
			$item = $metrics[$keyword] ?? null;
			$upsert->add([
				$id,
				$market->provider,
				$market->locationCode,
				$market->languageCode,
				bin2hex(MarketKeyword::key($keyword)),
				mb_substr($keyword, 0, 255, 'UTF-8'),
				self::nullable($item?->searchVolume),
				self::decimal($item?->cpc),
				$item?->competitionLevel ?? '',
				self::nullable($item?->competitionIndex),
				self::decimal($item?->lowTopOfPageBid),
				self::decimal($item?->highTopOfPageBid),
				$now,
				$staleAfter,
				$taskId,
				$now,
				$now,
			]);

			foreach ($item?->monthly ?? [] as $month) {
				$monthly->add([$id, $month['month'], self::nullable($month['search_volume']), $now]);
			}
		}

		$upsert->flush();
		$monthly->flush();

		return count($ids);
	}

	/**
	 * Zapis trudności SEO; fraza bez wyniku = pobrana bez danych.
	 *
	 * @param list<string> $requested
	 * @param array<string, ?int> $difficulty postać znormalizowana → trudność
	 */
	public function storeDifficulty(Market $market, array $requested, array $difficulty, int $taskId, int $ttlDays): int
	{
		$ids = $this->ensure($market, $requested);
		$now = $this->now();
		$staleAfter = $this->clock->now()->modify('+' . $ttlDays . ' days')->format('Y-m-d H:i:s');
		$upsert = new BulkInsert(
			$this->db,
			$this->table(),
			['id', 'provider', 'location_code', 'language_code', 'keyword_key', 'keyword', 'keyword_difficulty', 'difficulty_fetched_at',
				'difficulty_stale_after', 'difficulty_task_id', 'created_at', 'updated_at'],
			['%d', '%s', '%d', '%s', 'UNHEX(%s)', '%s', "NULLIF(%s, '')", '%s', '%s', '%d', '%s', '%s'],
			'ON DUPLICATE KEY UPDATE keyword_difficulty = VALUES(keyword_difficulty), difficulty_fetched_at = VALUES(difficulty_fetched_at),
				difficulty_stale_after = VALUES(difficulty_stale_after), difficulty_task_id = VALUES(difficulty_task_id), updated_at = VALUES(updated_at)',
		);

		foreach ($ids as $keyword => $id) {
			// Klucze tablic PHP zamieniają frazy liczbowe („2024”) na int.
			$keyword = (string) $keyword;
			$upsert->add([
				$id,
				$market->provider,
				$market->locationCode,
				$market->languageCode,
				bin2hex(MarketKeyword::key($keyword)),
				mb_substr($keyword, 0, 255, 'UTF-8'),
				self::nullable($difficulty[$keyword] ?? null),
				$now,
				$staleAfter,
				$taskId,
				$now,
				$now,
			]);
		}

		$upsert->flush();

		return count($ids);
	}

	/**
	 * Intencja wyszukiwania od dostawcy (informational, navigational, commercial, transactional) — zapisywana tylko,
	 * gdy dostawca ją zwrócił (brak wartości niczego nie nadpisuje).
	 *
	 * @param array<string, string> $intents postać znormalizowana → intencja
	 */
	public function storeIntent(Market $market, array $intents): int
	{
		$ids = $this->ensure($market, array_map('strval', array_keys($intents)));
		$now = $this->now();
		$upsert = new BulkInsert(
			$this->db,
			$this->table(),
			['id', 'provider', 'location_code', 'language_code', 'keyword_key', 'keyword', 'search_intent', 'intent_fetched_at', 'created_at', 'updated_at'],
			['%d', '%s', '%d', '%s', 'UNHEX(%s)', '%s', '%s', '%s', '%s', '%s'],
			'ON DUPLICATE KEY UPDATE search_intent = VALUES(search_intent), intent_fetched_at = VALUES(intent_fetched_at), updated_at = VALUES(updated_at)',
		);

		foreach ($ids as $keyword => $id) {
			// Klucze tablic PHP zamieniają frazy liczbowe („2024”) na int.
			$keyword = (string) $keyword;
			$upsert->add([$id, $market->provider, $market->locationCode, $market->languageCode, bin2hex(MarketKeyword::key($keyword)), mb_substr($keyword, 0, 255, 'UTF-8'), $intents[$keyword], $now, $now, $now]);
		}

		$upsert->flush();

		return count($ids);
	}

	/**
	 * Grupa synonimów dostawcy (`core_key` = klucz rynkowy frazy głównej grupy, np. DataForSEO Labs `core_keyword`) —
	 * sygnał grupowania fraz (Luki SEO), nie tożsamość frazy.
	 *
	 * @param array<string, string> $cores postać znormalizowana → fraza główna grupy
	 */
	public function storeCoreKeys(Market $market, array $cores): int
	{
		$ids = $this->ensure($market, array_map('strval', array_keys($cores)));
		$now = $this->now();
		$upsert = new BulkInsert(
			$this->db,
			$this->table(),
			['id', 'provider', 'location_code', 'language_code', 'keyword_key', 'keyword', 'core_key', 'created_at', 'updated_at'],
			['%d', '%s', '%d', '%s', 'UNHEX(%s)', '%s', 'UNHEX(%s)', '%s', '%s'],
			'ON DUPLICATE KEY UPDATE core_key = VALUES(core_key)',
		);

		foreach ($ids as $keyword => $id) {
			$keyword = (string) $keyword;
			$upsert->add([$id, $market->provider, $market->locationCode, $market->languageCode, bin2hex(MarketKeyword::key($keyword)), mb_substr($keyword, 0, 255, 'UTF-8'), bin2hex(MarketKeyword::key($cores[$keyword])), $now, $now]);
		}

		$upsert->flush();

		return count($ids);
	}

	/**
	 * Dostawca rozpoznał inny język frazy niż język rynku (filtr trafności Luk SEO).
	 *
	 * @param array<string, bool> $languages postać znormalizowana → inny język
	 */
	public function storeOtherLanguage(Market $market, array $languages): int
	{
		$ids = $this->ensure($market, array_map('strval', array_keys($languages)));
		$now = $this->now();
		$upsert = new BulkInsert(
			$this->db,
			$this->table(),
			['id', 'provider', 'location_code', 'language_code', 'keyword_key', 'keyword', 'other_language', 'created_at', 'updated_at'],
			['%d', '%s', '%d', '%s', 'UNHEX(%s)', '%s', '%d', '%s', '%s'],
			'ON DUPLICATE KEY UPDATE other_language = VALUES(other_language)',
		);

		foreach ($ids as $keyword => $id) {
			$keyword = (string) $keyword;
			$upsert->add([$id, $market->provider, $market->locationCode, $market->languageCode, bin2hex(MarketKeyword::key($keyword)), mb_substr($keyword, 0, 255, 'UTF-8'), $languages[$keyword] ? 1 : 0, $now, $now]);
		}

		$upsert->flush();

		return count($ids);
	}

	/**
	 * Metryki dla kluczy rynkowych (jedno zapytanie na paczkę — bez N+1). Klucz wyniku: hex klucza.
	 *
	 * @param list<string> $keys klucze binarne (MarketKeyword::key)
	 * @return array<string, MarketMetrics>
	 */
	public function findByKeys(Market $market, array $keys): array
	{
		$result = [];

		foreach (array_chunk(array_values(array_unique(array_map('bin2hex', $keys))), self::LOOKUP_CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				'SELECT ' . MarketMetrics::columns('m', '') . ", LOWER(HEX(m.keyword_key)) AS h
				FROM `{$this->table()}` m
				WHERE m.provider = %s AND m.location_code = %d AND m.language_code = %s AND m.keyword_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				[$market->provider, $market->locationCode, $market->languageCode, ...$chunk],
			) as $row) {
				$metrics = MarketMetrics::fromRow($row);

				if ($metrics !== null) {
					$result[(string) $row['h']] = $metrics;
				}
			}
		}

		return $result;
	}

	/**
	 * Historia miesięczna (ostatnie $months miesięcy dla każdej frazy), rosnąco.
	 *
	 * @param list<int> $ids
	 * @return array<int, list<array{month: string, search_volume: ?int}>>
	 */
	public function history(array $ids, int $months = 12): array
	{
		$history = [];

		foreach (array_chunk(array_values(array_unique($ids)), self::LOOKUP_CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT market_keyword_id, month, search_volume FROM `{$this->db->table('market_keyword_monthly')}`
				WHERE market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ') ORDER BY market_keyword_id, month DESC',
				$chunk,
			) as $row) {
				$id = (int) $row['market_keyword_id'];

				if (count($history[$id] ?? []) < $months) {
					$history[$id][] = ['month' => (string) $row['month'], 'search_volume' => $row['search_volume'] === null ? null : (int) $row['search_volume']];
				}
			}
		}

		return array_map('array_reverse', $history);
	}

	/**
	 * Liczby metryk rynku (status, panel).
	 *
	 * @return array{total: int, volume_fresh: int, volume_stale: int, volume_pending: int, volume_known: int, difficulty_fresh: int, difficulty_stale: int, difficulty_known: int}
	 */
	public function counts(Market $market): array
	{
		$now = $this->now();
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS total,
				SUM(volume_fetched_at IS NOT NULL AND volume_stale_after > %s) AS volume_fresh,
				SUM(volume_fetched_at IS NOT NULL AND volume_stale_after <= %s) AS volume_stale,
				SUM(volume_pending_until > %s) AS volume_pending,
				SUM(search_volume IS NOT NULL) AS volume_known,
				SUM(difficulty_fetched_at IS NOT NULL AND difficulty_stale_after > %s) AS difficulty_fresh,
				SUM(difficulty_fetched_at IS NOT NULL AND difficulty_stale_after <= %s) AS difficulty_stale,
				SUM(keyword_difficulty IS NOT NULL) AS difficulty_known
			FROM `{$this->table()}` WHERE provider = %s AND location_code = %d AND language_code = %s",
			[$now, $now, $now, $now, $now, $market->provider, $market->locationCode, $market->languageCode],
		) ?? [];

		return array_map(static fn (mixed $value): int => (int) $value, [
			'total' => $row['total'] ?? 0,
			'volume_fresh' => $row['volume_fresh'] ?? 0,
			'volume_stale' => $row['volume_stale'] ?? 0,
			'volume_pending' => $row['volume_pending'] ?? 0,
			'volume_known' => $row['volume_known'] ?? 0,
			'difficulty_fresh' => $row['difficulty_fresh'] ?? 0,
			'difficulty_stale' => $row['difficulty_stale'] ?? 0,
			'difficulty_known' => $row['difficulty_known'] ?? 0,
		]);
	}

	/**
	 * Frazy projektu (słownik GSC) z danymi rynkowymi na rynku projektu — liczone po kluczu rynkowym.
	 *
	 * @return array{enriched: int, with_volume: int, with_difficulty: int}
	 */
	public function projectCounts(int $projectId, Market $market): array
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS enriched, SUM(m.search_volume IS NOT NULL) AS with_volume, SUM(m.keyword_difficulty IS NOT NULL) AS with_difficulty
			FROM `{$this->db->table('keywords')}` k
			JOIN `{$this->table()}` m ON m.provider = %s AND m.location_code = %d AND m.language_code = %s AND m.keyword_key = k.market_key
			WHERE k.project_id = %d AND (m.volume_fetched_at IS NOT NULL OR m.difficulty_fetched_at IS NOT NULL)",
			[$market->provider, $market->locationCode, $market->languageCode, $projectId],
		) ?? [];

		return [
			'enriched' => (int) ($row['enriched'] ?? 0),
			'with_volume' => (int) ($row['with_volume'] ?? 0),
			'with_difficulty' => (int) ($row['with_difficulty'] ?? 0),
		];
	}

	/**
	 * @param list<string> $hexKeys
	 * @return array<string, int> hex → id
	 */
	private function idsByKeys(Market $market, array $hexKeys): array
	{
		$ids = [];

		foreach (array_chunk($hexKeys, self::LOOKUP_CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, LOWER(HEX(keyword_key)) AS h FROM `{$this->table()}`
				WHERE provider = %s AND location_code = %d AND language_code = %s AND keyword_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				[$market->provider, $market->locationCode, $market->languageCode, ...$chunk],
			) as $row) {
				$ids[(string) $row['h']] = (int) $row['id'];
			}
		}

		return $ids;
	}

	/**
	 * @param list<string> $keywords
	 * @return list<string>
	 */
	private static function hexKeys(array $keywords): array
	{
		return array_values(array_unique(array_map(static fn (string $keyword): string => bin2hex(MarketKeyword::key($keyword)), $keywords)));
	}

	private static function nullable(?int $value): string
	{
		return $value === null ? '' : (string) $value;
	}

	private static function decimal(?float $value): string
	{
		return $value === null ? '' : sprintf('%.4F', $value);
	}

	private function table(): string
	{
		return $this->db->table('market_keywords');
	}
}
