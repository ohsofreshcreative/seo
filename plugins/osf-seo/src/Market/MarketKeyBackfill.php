<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use OsfSeo\Database\Connection;

/**
 * Wylicza `keywords.market_key` (klucz rynkowy frazy GSC) dla fraz, które go nie mają — w tle i przed synchronizacją,
 * paczkami (indeks `project_market_key`). Importer GSC nie jest zmieniany: nowe frazy dostają klucz z opóźnieniem,
 * a do tego czasu raport pokazuje dla nich „—” (jak dla fraz bez danych rynkowych).
 */
final class MarketKeyBackfill
{
	private const BATCH = 2000;

	public function __construct(private readonly Connection $db)
	{
	}

	/** @return int liczba uzupełnionych fraz */
	public function fillProject(int $projectId, int $maxRows = 20000): int
	{
		return $this->fill($maxRows, $projectId);
	}

	/** Wszystkie projekty (krok w tle, ograniczony). */
	public function fillAll(int $maxRows = 5000): int
	{
		return $this->fill($maxRows, null);
	}

	private function fill(int $maxRows, ?int $projectId): int
	{
		$table = $this->db->table('keywords');
		$filled = 0;

		while ($filled < $maxRows) {
			$rows = $projectId === null
				? $this->db->fetchAll("SELECT id, keyword FROM `{$table}` WHERE market_key IS NULL LIMIT %d", [min(self::BATCH, $maxRows - $filled)])
				: $this->db->fetchAll("SELECT id, keyword FROM `{$table}` WHERE project_id = %d AND market_key IS NULL LIMIT %d", [$projectId, min(self::BATCH, $maxRows - $filled)]);

			if ($rows === []) {
				break;
			}

			$cases = [];
			$params = [];
			$ids = [];

			foreach ($rows as $row) {
				$cases[] = 'WHEN %d THEN UNHEX(%s)';
				array_push($params, (int) $row['id'], bin2hex(MarketKeyword::key((string) $row['keyword'])));
				$ids[] = (int) $row['id'];
			}

			$this->db->execute(
				"UPDATE `{$table}` SET market_key = CASE id " . implode(' ', $cases) . ' END WHERE id IN (' . Connection::placeholders($ids, '%d') . ')',
				[...$params, ...$ids],
			);
			$filled += count($rows);

			if (count($rows) < self::BATCH) {
				break;
			}
		}

		return $filled;
	}
}
