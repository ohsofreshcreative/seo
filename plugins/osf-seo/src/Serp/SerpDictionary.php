<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Słowniki pełnych SERP-ów: hosty (`serp_domains`), adresy (`serp_urls`) i treści wyników (`serp_snippets`).
 * Wyniki pomiaru przechowują tylko identyfikatory, więc powtarzający się tekst (te same strony tygodniami w TOP100)
 * nie jest zapisywany przy każdym pomiarze.
 *
 * Najpierw odczyt istniejących (po skrócie MD5 w UNIQUE), potem zapis tylko brakujących i ponowny odczyt:
 * - zwykły przypadek (większość wyników już znana) nie spala wartości AUTO_INCREMENT (wstawienie z duplikatem rezerwuje
 *   je także dla istniejących wpisów — przy milionach wyników wyczerpałoby zakres INT),
 * - równoległy zapis tego samego wpisu jest bezpieczny (UNIQUE + ON DUPLICATE KEY, potem odczyt), wpisy są niezmienne.
 */
final class SerpDictionary
{
	private const CHUNK = 500;

	/** Wpis dodany w międzyczasie przez inny proces nie jest błędem (odczyt po zapisie go zwróci). */
	private const ON_DUPLICATE = 'ON DUPLICATE KEY UPDATE id = id';

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @param list<string> $hosts znormalizowane hosty
	 * @return array<string, int> host → id
	 */
	public function domainIds(array $hosts): array
	{
		$hosts = array_values(array_unique($hosts));
		$byHash = [];

		foreach ($hosts as $host) {
			$byHash[md5($host)] = $host;
		}

		$ids = $this->resolve('serp_domains', 'host_hash', array_keys($byHash));
		$missing = array_diff_key($byHash, $ids);

		if ($missing !== []) {
			$insert = new BulkInsert($this->db, $this->db->table('serp_domains'), ['host_hash', 'host', 'host_rev', 'created_at'], ['UNHEX(%s)', '%s', '%s', '%s'], self::ON_DUPLICATE, 500);
			$now = $this->now();

			foreach ($missing as $hash => $host) {
				$insert->add([$hash, $host, DomainFamily::reverse($host), $now]);
			}

			$insert->flush();
			$ids += $this->resolve('serp_domains', 'host_hash', array_keys($missing));
		}

		$result = [];

		foreach ($byHash as $hash => $host) {
			if (isset($ids[$hash])) {
				$result[$host] = $ids[$hash];
			}
		}

		return $result;
	}

	/**
	 * @param array<string, array{url: string, domain_id: int}> $urls skrót adresu (hex) → adres i domena
	 * @return array<string, int> skrót → id
	 */
	public function urlIds(array $urls): array
	{
		$ids = $this->resolve('serp_urls', 'url_hash', array_keys($urls));
		$missing = array_diff_key($urls, $ids);

		if ($missing !== []) {
			$insert = new BulkInsert($this->db, $this->db->table('serp_urls'), ['url_hash', 'domain_id', 'url', 'created_at'], ['UNHEX(%s)', '%d', '%s', '%s'], self::ON_DUPLICATE, 300);
			$now = $this->now();

			foreach ($missing as $hash => $row) {
				$insert->add([$hash, $row['domain_id'], $row['url'], $now]);
			}

			$insert->flush();
			$ids += $this->resolve('serp_urls', 'url_hash', array_keys($missing));
		}

		return $ids;
	}

	/**
	 * @param array<string, SerpItem> $items skrót treści (hex) → wynik
	 * @return array<string, int> skrót → id
	 */
	public function snippetIds(array $items): array
	{
		$ids = $this->resolve('serp_snippets', 'snippet_hash', array_keys($items));
		$missing = array_diff_key($items, $ids);

		if ($missing !== []) {
			$insert = new BulkInsert(
				$this->db,
				$this->db->table('serp_snippets'),
				['snippet_hash', 'title', 'description', 'breadcrumb', 'website_name', 'extra', 'created_at'],
				['UNHEX(%s)', '%s', '%s', '%s', '%s', '%s', '%s'],
				self::ON_DUPLICATE,
				200,
			);
			$now = $this->now();

			foreach ($missing as $hash => $item) {
				// Puste pola jako pusty tekst (BulkInsert nie przyjmuje NULL); odczyt zamienia '' na brak wartości.
				$insert->add([
					$hash,
					(string) $item->title,
					(string) $item->description,
					(string) $item->breadcrumb,
					(string) $item->websiteName,
					$item->extra === [] ? '' : (string) json_encode($item->extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
					$now,
				]);
			}

			$insert->flush();
			$ids += $this->resolve('serp_snippets', 'snippet_hash', array_keys($missing));
		}

		return $ids;
	}

	/**
	 * @param list<string> $hashes skróty hex
	 * @return array<string, int> skrót → id
	 */
	private function resolve(string $table, string $column, array $hashes): array
	{
		$ids = [];

		foreach (array_chunk(array_values(array_unique($hashes)), self::CHUNK) as $chunk) {
			$rows = $this->db->fetchAll(
				"SELECT id, LOWER(HEX(`{$column}`)) AS h FROM `{$this->db->table($table)}` WHERE `{$column}` IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				$chunk,
			);

			foreach ($rows as $row) {
				$ids[(string) $row['h']] = (int) $row['id'];
			}
		}

		return $ids;
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
