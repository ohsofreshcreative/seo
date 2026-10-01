<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Słowniki fraz (`osf_keywords`) i adresów (`osf_pages`) projektu: tekst → ID, wsadowo.
 *
 * Tożsamość = dokładne bajty UTF-8 zwrócone przez GSC (MD5 jako BINARY(16) w kluczu UNIQUE):
 * bez zmiany wielkości liter, przycinania białych znaków i normalizacji Unicode — `Buty` i `buty`
 * to dwie frazy, tak jak w GSC. Kolumna tekstu przechowuje maks. 500 (fraza) / 2048 (URL) znaków;
 * dłuższe wartości są skracane w kolumnie, ale skrót liczony jest z pełnej wartości (tożsamość bez kolizji).
 *
 * Najpierw SELECT istniejących, potem INSERT tylko brakujących — INSERT … ON DUPLICATE KEY dla
 * istniejących rezerwowałby przy każdym imporcie wartości AUTO_INCREMENT (ryzyko wyczerpania INT UNSIGNED).
 * Pamięć podręczna obejmuje jeden import (kolejne strony tego samego okna).
 */
final class Dictionary
{
	public const KEYWORD_MAX_LENGTH = 500;

	public const URL_MAX_LENGTH = 2048;

	private const LOOKUP_CHUNK = 1000;

	/** @var array<string, array<string, int>> cache: "{table}:{project}" → hash hex → id */
	private array $cache = [];

	/** @var array<string, int> tabela → liczba nowych wpisów */
	private array $created = ['keywords' => 0, 'pages' => 0];

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @param list<string> $keywords
	 * @return array<string, int> fraza → id
	 */
	public function keywordIds(int $projectId, array $keywords): array
	{
		return $this->resolve('keywords', 'keyword', 'keyword_hash', $projectId, $keywords, self::KEYWORD_MAX_LENGTH);
	}

	/**
	 * @param list<string> $urls
	 * @return array<string, int> URL → id
	 */
	public function pageIds(int $projectId, array $urls): array
	{
		return $this->resolve('pages', 'url', 'url_hash', $projectId, $urls, self::URL_MAX_LENGTH);
	}

	/** Nowe frazy utworzone od ostatniego reset(). */
	public function keywordsCreated(): int
	{
		return $this->created['keywords'];
	}

	/** Nowe adresy utworzone od ostatniego reset(). */
	public function pagesCreated(): int
	{
		return $this->created['pages'];
	}

	/** Czyści pamięć podręczną i liczniki (początek importu). */
	public function reset(): void
	{
		$this->cache = [];
		$this->created = ['keywords' => 0, 'pages' => 0];
	}

	public static function hash(string $value): string
	{
		return md5($value);
	}

	/**
	 * @param list<string> $values
	 * @return array<string, int>
	 */
	private function resolve(string $table, string $column, string $hashColumn, int $projectId, array $values, int $maxLength): array
	{
		$cacheKey = $table . ':' . $projectId;
		$cache = $this->cache[$cacheKey] ?? [];
		$byHash = [];

		foreach ($values as $value) {
			$byHash[self::hash($value)] = $value;
		}

		$missing = array_diff_key($byHash, $cache);

		if ($missing !== []) {
			$cache += $this->lookup($table, $hashColumn, $projectId, array_keys($missing));
			$missing = array_diff_key($byHash, $cache);
		}

		if ($missing !== []) {
			$this->insert($table, $column, $hashColumn, $projectId, $missing, $maxLength);
			$found = $this->lookup($table, $hashColumn, $projectId, array_keys($missing));
			$this->created[$table] += count($found);
			$cache += $found;
		}

		$this->cache[$cacheKey] = $cache;
		$ids = [];

		foreach ($byHash as $hash => $value) {
			if (! isset($cache[$hash])) {
				throw new \RuntimeException('Dictionary entry could not be resolved.');
			}

			$ids[$value] = $cache[$hash];
		}

		return $ids;
	}

	/**
	 * @param list<string> $hashes heksadecymalne MD5
	 * @return array<string, int>
	 */
	private function lookup(string $table, string $hashColumn, int $projectId, array $hashes): array
	{
		$found = [];

		foreach (array_chunk($hashes, self::LOOKUP_CHUNK) as $chunk) {
			$rows = $this->db->fetchAll(
				"SELECT id, LOWER(HEX(`{$hashColumn}`)) AS h FROM `{$this->db->table($table)}` WHERE project_id = %d AND `{$hashColumn}` IN ("
				. Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				[$projectId, ...$chunk],
			);

			foreach ($rows as $row) {
				$found[(string) $row['h']] = (int) $row['id'];
			}
		}

		return $found;
	}

	/**
	 * @param array<string, string> $missing hash → wartość
	 */
	private function insert(string $table, string $column, string $hashColumn, int $projectId, array $missing, int $maxLength): void
	{
		$columns = ['project_id', $column, $hashColumn, 'created_at'];
		$placeholders = ['%d', '%s', 'UNHEX(%s)', '%s'];

		if ($table === 'pages') {
			$columns[] = 'path';
			$placeholders[] = '%s';
		}

		// ON DUPLICATE KEY tylko jako zabezpieczenie przed wyścigiem (wpisy istniejące odfiltrował SELECT).
		$insert = new BulkInsert($this->db, $this->db->table($table), $columns, $placeholders, 'ON DUPLICATE KEY UPDATE `id` = `id`', 500);
		$now = $this->clock->now()->format('Y-m-d H:i:s');

		foreach ($missing as $hash => $value) {
			$row = [$projectId, mb_substr($value, 0, $maxLength, 'UTF-8'), $hash, $now];

			if ($table === 'pages') {
				$row[] = mb_substr(self::path($value), 0, $maxLength, 'UTF-8');
			}

			$insert->add($row);
		}

		$insert->flush();
	}

	/** Ścieżka z zapytaniem (do wyświetlania) — URL pozostaje bez zmian w kolumnie `url`. */
	public static function path(string $url): string
	{
		$parts = parse_url($url);

		if (! is_array($parts)) {
			return '';
		}

		return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
	}
}
