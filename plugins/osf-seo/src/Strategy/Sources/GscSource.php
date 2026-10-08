<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Sources;

use OsfSeo\Database\Connection;
use OsfSeo\DataForSeo\DataForSeoSerpProvider;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Serp\SerpDictionary;
use OsfSeo\Strategy\CandidateSource;
use OsfSeo\Strategy\SignalBatch;
use OsfSeo\Strategy\SourceScope;
use OsfSeo\Strategy\SourceSignal;
use OsfSeo\Strategy\StrategyConfig;
use OsfSeo\Strategy\StrategySource;

/**
 * Google Search Console (dane już zaimportowane, bez wywołań Google): frazy projektu z co najmniej progiem wyświetleń w oknie
 * i średnią pozycją (GSC) do progu są kandydatami (najniższy poziom — reszta limitu). Dowody dla każdego kandydata:
 * wyświetlenia, kliknięcia, średnia pozycja (GSC) = `SUM(position_sum) / SUM(impressions)` wszystkich wariantów zapisu frazy
 * (po kluczu rynkowym) i strony z wyświetleniami (query × page, adres w słowniku `serp_urls` — ta sama tożsamość adresu co SERP
 * i Labs). Projekt bez danych GSC → dowód `null` (brak danych), fraza bez wyświetleń → zera — to nigdy nie jest dowód braku
 * widoczności ani braku strony.
 */
final class GscSource implements CandidateSource
{
	private const CHUNK = 500;

	public function __construct(
		private readonly Connection $db,
		private readonly MarketKeywordLookup $lookup,
		private readonly SerpDictionary $dictionary,
	) {
	}

	public function source(): StrategySource
	{
		return StrategySource::Gsc;
	}

	public function fingerprint(SourceScope $scope): string
	{
		$project = $this->db->fetchRow(
			"SELECT last_synced_at, gsc_data_property FROM `{$this->db->table('projects')}` WHERE id = %d",
			[$scope->projectId],
		) ?? [];
		$coverage = array_map(
			static fn (array $row): string => implode('/', [$row['dataset'], $row['oldest_date'], $row['newest_date']]),
			$this->db->fetchAll(
				"SELECT dataset, oldest_date, newest_date FROM `{$this->db->table('sync_state')}` WHERE project_id = %d AND dataset IN ('query', 'query_page') ORDER BY dataset",
				[$scope->projectId],
			),
		);
		// Frazy bez klucza rynkowego (wyliczany w tle) — po uzupełnieniu kluczy zbiór kandydatów GSC się zmienia.
		$unkeyed = $this->unkeyed($scope);

		return implode('|', [
			$scope->window === null ? '' : implode('/', $scope->window),
			$project['last_synced_at'] ?? '',
			$project['gsc_data_property'] ?? '',
			implode(',', $coverage),
			(string) $unkeyed,
		]);
	}

	/** Frazy GSC projektu bez klucza rynkowego — poza źródłem GSC do czasu wyliczenia klucza (w tle albo przy przeliczeniu). */
	public function unkeyed(SourceScope $scope): int
	{
		return (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$this->db->table('keywords')}` WHERE project_id = %d AND market_key IS NULL", [$scope->projectId]);
	}

	/**
	 * Kompletność okna: oba zbiory fraz GSC pokrywają całe okno (inaczej fakty GSC mogą być zaniżone — np. w trakcie backfillu).
	 *
	 * @return array{query: bool, query_page: bool}
	 */
	public function coverage(SourceScope $scope): array
	{
		$result = ['query' => false, 'query_page' => false];

		if ($scope->window === null) {
			return $result;
		}

		foreach ($this->db->fetchAll(
			"SELECT dataset, oldest_date, newest_date FROM `{$this->db->table('sync_state')}` WHERE project_id = %d AND dataset IN ('query', 'query_page')",
			[$scope->projectId],
		) as $row) {
			$result[(string) $row['dataset']] = $row['oldest_date'] !== null && $row['newest_date'] !== null
				&& (string) $row['oldest_date'] <= $scope->window[0] && (string) $row['newest_date'] >= $scope->window[1];
		}

		return $result;
	}

	public function signals(SourceScope $scope): SignalBatch
	{
		if ($scope->window === null) {
			return new SignalBatch([]);
		}

		$limit = $scope->config->maxKeywords();
		$rows = $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN LOWER(HEX(k.market_key)) AS h, MIN(k.keyword) AS variant, SUM(q.impressions) AS impressions,
				SUM(q.position_sum) AS position_sum, COUNT(*) OVER () AS total_rows
			FROM `{$this->db->table('gsc_query_daily')}` q
			JOIN `{$this->db->table('keywords')}` k ON k.id = q.keyword_id
			WHERE q.project_id = %d AND q.date BETWEEN %s AND %s AND k.market_key IS NOT NULL
			GROUP BY k.market_key
			HAVING SUM(q.impressions) >= %d AND SUM(q.position_sum) <= %f * SUM(q.impressions)
			ORDER BY impressions DESC, h LIMIT %d",
			[$scope->projectId, $scope->window[0], $scope->window[1], $scope->config->gscMinImpressions(), $scope->config->gscMaxPosition(), $limit],
		);
		$market = $this->lookup->byKeys($scope->market, array_map(static fn (array $row): string => (string) $row['h'], $rows));
		$signals = [];

		foreach ($rows as $row) {
			$hex = (string) $row['h'];
			$known = $market[$hex] ?? null;
			$signals[] = new SourceSignal(
				$hex,
				$known['id'] ?? null,
				$known['keyword'] ?? MarketKeyword::normalize((string) $row['variant']),
				$known['intent'] ?? null,
				StrategySource::Gsc,
				SourceSignal::TIER_GSC,
				(float) $row['impressions'],
			);
		}

		return new SignalBatch($signals, max(0, (int) ($rows[0]['total_rows'] ?? 0) - count($rows)));
	}

	/**
	 * Które z podanych fraz (klucze hex) spełniają kryteria źródła GSC — dla kandydatów, którzy wypadli ponad limit, a nie
	 * zostali zwróceni przez `signals()` (powód nieaktywności „ponad limit”, nie „brak źródła”).
	 *
	 * @param list<string> $hexKeys
	 * @return list<string>
	 */
	public function qualifying(SourceScope $scope, array $hexKeys): array
	{
		if ($scope->window === null || $hexKeys === []) {
			return [];
		}

		$result = [];

		foreach (array_chunk(array_values(array_unique($hexKeys)), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN LOWER(HEX(k.market_key)) AS h
				FROM `{$this->db->table('keywords')}` k
				JOIN `{$this->db->table('gsc_query_daily')}` q ON q.project_id = k.project_id AND q.keyword_id = k.id AND q.date BETWEEN %s AND %s
				WHERE k.project_id = %d AND k.market_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')
				GROUP BY k.market_key
				HAVING SUM(q.impressions) >= %d AND SUM(q.position_sum) <= %f * SUM(q.impressions)',
				[$scope->window[0], $scope->window[1], $scope->projectId, ...$chunk, $scope->config->gscMinImpressions(), $scope->config->gscMaxPosition()],
			) as $row) {
				$result[] = (string) $row['h'];
			}
		}

		return $result;
	}

	public function evidence(SourceScope $scope, array $keys, array $collected): array
	{
		if ($scope->window === null || $keys === []) {
			return [];
		}

		$totals = [];
		$pages = [];
		$hexes = array_values(array_unique(array_map('strval', $keys)));

		foreach (array_chunk($hexes, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN LOWER(HEX(k.market_key)) AS h, SUM(q.impressions) AS impressions, SUM(q.clicks) AS clicks, SUM(q.position_sum) AS position_sum,
					COUNT(DISTINCT k.id) AS variants
				FROM `{$this->db->table('keywords')}` k
				JOIN `{$this->db->table('gsc_query_daily')}` q ON q.project_id = k.project_id AND q.keyword_id = k.id AND q.date BETWEEN %s AND %s
				WHERE k.project_id = %d AND k.market_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')
				GROUP BY k.market_key',
				[$scope->window[0], $scope->window[1], $scope->projectId, ...$chunk],
			) as $row) {
				$totals[(string) $row['h']] = $row;
			}

			foreach ($this->db->fetchAll(
				"SELECT STRAIGHT_JOIN LOWER(HEX(k.market_key)) AS h, qp.page_id, SUM(qp.impressions) AS impressions, SUM(qp.clicks) AS clicks
				FROM `{$this->db->table('keywords')}` k
				JOIN `{$this->db->table('gsc_query_page_daily')}` qp ON qp.project_id = k.project_id AND qp.keyword_id = k.id AND qp.date BETWEEN %s AND %s
				WHERE k.project_id = %d AND k.market_key IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')
				GROUP BY k.market_key, qp.page_id',
				[$scope->window[0], $scope->window[1], $scope->projectId, ...$chunk],
			) as $row) {
				if ((int) $row['impressions'] > 0 || (int) $row['clicks'] > 0) {
					$pages[(string) $row['h']][(int) $row['page_id']] = [(int) $row['impressions'], (int) $row['clicks']];
				}
			}
		}

		$urls = $this->pageUrls($scope, $pages);
		$result = [];

		foreach ($keys as $id => $hex) {
			$hex = (string) $hex;
			$total = $totals[$hex] ?? null;
			$impressions = $total === null ? 0 : (int) $total['impressions'];
			$byUrl = [];
			$pageImpressions = 0;

			foreach ($pages[$hex] ?? [] as $pageId => [$pageImpr, $pageClicks]) {
				$url = $urls[$pageId] ?? null;

				if ($url === null) {
					continue;
				}

				// Adresy różniące się tylko `#fragmentem` (albo parametrem `srsltid`) to ta sama strona.
				$byUrl[$url['url']] ??= ['url' => $url['url'], 'url_id' => $url['url_id'], 'impressions' => 0, 'clicks' => 0];
				$byUrl[$url['url']]['impressions'] += $pageImpr;
				$byUrl[$url['url']]['clicks'] += $pageClicks;
				$pageImpressions += $pageImpr;
			}

			uasort($byUrl, static fn (array $a, array $b): int => [$b['impressions'], $b['clicks'], $a['url']] <=> [$a['impressions'], $a['clicks'], $b['url']]);
			$top = array_slice(array_values($byUrl), 0, StrategyConfig::EVIDENCE_PAGES);
			$result[(int) $id] = [
				'_facts' => ['top_url_id' => $top[0]['url_id'] ?? null],
				'impressions' => $impressions,
				'clicks' => $total === null ? 0 : (int) $total['clicks'],
				'position' => $impressions > 0 ? round((float) $total['position_sum'] / $impressions, 2) : null,
				'variants' => $total === null ? 0 : (int) $total['variants'],
				'pages' => array_map(static fn (array $page): array => [
					'url' => $page['url'],
					'impressions' => $page['impressions'],
					'clicks' => $page['clicks'],
					'share' => $pageImpressions > 0 ? round($page['impressions'] / $pageImpressions, 4) : null,
				], $top),
				'pages_total' => count($byUrl),
			];
		}

		return $result;
	}

	/**
	 * Strony GSC → adres znormalizowany (bez fragmentu i `srsltid`) i jego identyfikator w słowniku `serp_urls` (zapis brakujących
	 * wpisów słownika — niezmiennych i wspólnych dla SERP, Labs i GSC; w podglądzie bez zapisu).
	 *
	 * @param array<string, array<int, array{0: int, 1: int}>> $pages
	 * @return array<int, array{url: string, url_id: ?int}>
	 */
	private function pageUrls(SourceScope $scope, array $pages): array
	{
		$pageIds = [];

		foreach ($pages as $list) {
			foreach (array_keys($list) as $pageId) {
				$pageIds[(int) $pageId] = true;
			}
		}

		$byPage = [];

		foreach (array_chunk(array_keys($pageIds), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, url FROM `{$this->db->table('pages')}` WHERE project_id = %d AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$scope->projectId, ...$chunk],
			) as $row) {
				$url = DataForSeoSerpProvider::url((string) $row['url']);
				$host = $url === null ? null : DomainFamily::fromUrl($url);

				if ($url !== null && $host !== null) {
					$byPage[(int) $row['id']] = ['url' => $url, 'host' => $host];
				}
			}
		}

		if ($byPage === [] || $scope->dryRun) {
			return array_map(static fn (array $page): array => ['url' => $page['url'], 'url_id' => null], $byPage);
		}

		$hosts = $this->dictionary->domainIds(array_values(array_unique(array_column($byPage, 'host'))));
		$entries = [];

		foreach ($byPage as $page) {
			if (isset($hosts[$page['host']])) {
				$entries[md5($page['url'])] = ['url' => $page['url'], 'domain_id' => $hosts[$page['host']]];
			}
		}

		$ids = $this->dictionary->urlIds($entries);

		return array_map(static fn (array $page): array => ['url' => $page['url'], 'url_id' => $ids[md5($page['url'])] ?? null], $byPage);
	}
}
