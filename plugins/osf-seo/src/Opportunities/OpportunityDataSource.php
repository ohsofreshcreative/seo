<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Analytics\KeywordReport;
use OsfSeo\Analytics\KeywordRow;
use OsfSeo\Analytics\OverviewReport;
use OsfSeo\Analytics\Period;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Gsc\Dictionary;

/**
 * Zapytania agregujące dla analizy szans — każde czyta jeden zakres dat projektu po kluczu głównym
 * `(project_id, date, …)` i zwraca do PHP wyłącznie sumy (bez historii dziennej, bez N+1):
 *
 * - frazy: KeywordReport::aggregate (`gsc_query_daily`, oba okresy w jednym skanie),
 * - fraza × podstrona i sumy podstron: `gsc_query_page_daily`, sumy warunkowe obu okresów,
 * - segmenty okresu tylko dla kandydatów do kanibalizacji (indeks project_keyword_date_page).
 *
 * Adresy z GSC są scalane po kluczu bez fragmentu `#…` (UrlKey) dopiero w PHP — słownik `pages` zostaje bez zmian.
 */
final class OpportunityDataSource
{
	private const BATCH = 1000;

	private const PAIR_COLUMNS = 'SUM(CASE WHEN qp.date >= %s THEN qp.clicks ELSE 0 END) AS cur_clicks,
		SUM(CASE WHEN qp.date >= %s THEN qp.impressions ELSE 0 END) AS cur_impr,
		SUM(CASE WHEN qp.date >= %s THEN qp.position_sum ELSE 0 END) AS cur_pos_sum,
		SUM(CASE WHEN qp.date < %s THEN qp.clicks ELSE 0 END) AS prev_clicks,
		SUM(CASE WHEN qp.date < %s THEN qp.impressions ELSE 0 END) AS prev_impr,
		SUM(CASE WHEN qp.date < %s THEN qp.position_sum ELSE 0 END) AS prev_pos_sum';

	public function __construct(
		private readonly Connection $db,
		private readonly KeywordReport $keywords,
		private readonly OverviewReport $overview,
	) {
	}

	/**
	 * Ostatnia kompletna data: wspólna dla sum witryny, fraz i fraz × podstron (dashboard + `query_page`).
	 * Null — brak danych któregoś zbioru (analiza czeka na import).
	 */
	public function latestDate(ProjectContext $context): ?string
	{
		$latest = $this->overview->latestDate($context);
		$pages = $this->db->fetchValue(
			"SELECT MAX(date) FROM `{$this->db->table(Dataset::QueryPage->factTable())}` WHERE project_id = %d",
			[$context->projectId()],
		);
		$queries = $this->keywords->latestDate($context);

		return $latest === null || $pages === null || $queries === null ? null : min($latest, $pages);
	}

	/**
	 * Najstarsza zaimportowana data fraz i fraz × podstron (backfill jest ciągły: od najnowszych do najstarszych).
	 *
	 * @return array{query: ?string, query_page: ?string}
	 */
	public function coverageStart(ProjectContext $context): array
	{
		$projectId = $context->projectId();

		return [
			'query' => $this->db->fetchValue("SELECT MIN(date) FROM `{$this->db->table(Dataset::Query->factTable())}` WHERE project_id = %d", [$projectId]),
			'query_page' => $this->db->fetchValue("SELECT MIN(date) FROM `{$this->db->table(Dataset::QueryPage->factTable())}` WHERE project_id = %d", [$projectId]),
		];
	}

	/**
	 * @return list<KeywordRow>
	 */
	public function keywords(ProjectContext $context, Period $period, int $minImpressions): array
	{
		return $this->keywords->aggregate($context, $period, $minImpressions);
	}

	/**
	 * Fraza × podstrona w obu okresach (pary z co najmniej $minImpressions wyświetleń w jednym z okresów).
	 *
	 * @return list<PagePair>
	 */
	public function pairs(ProjectContext $context, Period $period, int $minImpressions): array
	{
		$start = $period->current->start;
		$min = max(1, $minImpressions);
		$rows = $this->db->fetchAll(
			'SELECT qp.keyword_id, qp.page_id, ' . self::PAIR_COLUMNS . "
			FROM `{$this->db->table(Dataset::QueryPage->factTable())}` qp
			WHERE qp.project_id = %d AND qp.date BETWEEN %s AND %s
			GROUP BY qp.keyword_id, qp.page_id
			HAVING cur_impr >= %d OR prev_impr >= %d",
			[$start, $start, $start, $start, $start, $start, $context->projectId(), $period->previous->start, $period->current->end, $min, $min],
		);

		$urls = $this->pageUrls($context->projectId(), array_column($rows, 'page_id'));
		$merged = [];

		foreach ($rows as $row) {
			$url = $urls[(int) $row['page_id']] ?? null;

			if ($url === null) {
				continue;
			}

			$key = (int) $row['keyword_id'] . "\n" . $url;
			[$current, $previous] = self::stats($row);

			$merged[$key] = isset($merged[$key])
				? new PagePair((int) $row['keyword_id'], $url, $merged[$key]->current->add($current), $merged[$key]->previous->add($previous))
				: new PagePair((int) $row['keyword_id'], $url, $current, $previous);
		}

		return array_values($merged);
	}

	/**
	 * Sumy podstron (wszystkie widoczne frazy z `query_page`) w obu okresach.
	 *
	 * @return array<string, array{0: Stats, 1: Stats}>
	 */
	public function pageTotals(ProjectContext $context, Period $period, int $minImpressions): array
	{
		$start = $period->current->start;
		$min = max(1, $minImpressions);
		$rows = $this->db->fetchAll(
			'SELECT qp.page_id, ' . self::PAIR_COLUMNS . "
			FROM `{$this->db->table(Dataset::QueryPage->factTable())}` qp
			WHERE qp.project_id = %d AND qp.date BETWEEN %s AND %s
			GROUP BY qp.page_id
			HAVING cur_impr >= %d OR prev_impr >= %d",
			[$start, $start, $start, $start, $start, $start, $context->projectId(), $period->previous->start, $period->current->end, $min, $min],
		);

		$urls = $this->pageUrls($context->projectId(), array_column($rows, 'page_id'));
		$totals = [];

		foreach ($rows as $row) {
			$url = $urls[(int) $row['page_id']] ?? null;

			if ($url === null) {
				continue;
			}

			[$current, $previous] = self::stats($row);
			$totals[$url] = isset($totals[$url]) ? [$totals[$url][0]->add($current), $totals[$url][1]->add($previous)] : [$current, $previous];
		}

		return $totals;
	}

	/**
	 * Wyświetlenia fraz × podstron w kolejnych segmentach bieżącego okresu (zmiany dominującego adresu).
	 *
	 * @param list<int> $keywordIds
	 * @return array<int, array<string, array<int, int>>> fraza → adres → segment → wyświetlenia
	 */
	public function segments(ProjectContext $context, Period $period, array $keywordIds, int $segmentDays): array
	{
		$segments = [];

		foreach (array_chunk(array_values(array_unique($keywordIds)), self::BATCH) as $batch) {
			$rows = $this->db->fetchAll(
				"SELECT qp.keyword_id, qp.page_id, FLOOR(DATEDIFF(qp.date, %s) / %d) AS segment, SUM(qp.impressions) AS impressions
				FROM `{$this->db->table(Dataset::QueryPage->factTable())}` qp
				WHERE qp.project_id = %d AND qp.keyword_id IN (" . Connection::placeholders($batch, '%d') . ') AND qp.date BETWEEN %s AND %s
				GROUP BY qp.keyword_id, qp.page_id, segment',
				[$period->current->start, max(1, $segmentDays), $context->projectId(), ...$batch, $period->current->start, $period->current->end],
			);
			$urls = $this->pageUrls($context->projectId(), array_column($rows, 'page_id'));

			foreach ($rows as $row) {
				$url = $urls[(int) $row['page_id']] ?? null;

				if ($url !== null) {
					$keywordId = (int) $row['keyword_id'];
					$segment = (int) $row['segment'];
					$segments[$keywordId][$url][$segment] = ($segments[$keywordId][$url][$segment] ?? 0) + (int) $row['impressions'];
				}
			}
		}

		return $segments;
	}

	/**
	 * @param list<int> $keywordIds
	 * @return array<int, string>
	 */
	public function keywordTexts(int $projectId, array $keywordIds): array
	{
		$texts = [];

		foreach (array_chunk(array_values(array_unique($keywordIds)), self::BATCH) as $batch) {
			foreach ($this->db->fetchAll(
				"SELECT id, keyword FROM `{$this->db->table('keywords')}` WHERE project_id = %d AND id IN (" . Connection::placeholders($batch, '%d') . ')',
				[$projectId, ...$batch],
			) as $row) {
				$texts[(int) $row['id']] = (string) $row['keyword'];
			}
		}

		return $texts;
	}

	/**
	 * Sumy wskazanych fraz w zakresie dat (obserwacja po wdrożeniu). Frazy po dokładnym tekście (hash jak w słowniku).
	 *
	 * @param list<string> $keywords
	 */
	public function keywordTotals(ProjectContext $context, array $keywords, string $start, string $end): Stats
	{
		$hashes = array_values(array_unique(array_map(static fn (string $keyword): string => Dictionary::hash($keyword), $keywords)));
		$total = new Stats();

		foreach (array_chunk($hashes, self::BATCH) as $batch) {
			$row = $this->db->fetchRow(
				"SELECT SUM(q.clicks) AS clicks, SUM(q.impressions) AS impressions, SUM(q.position_sum) AS position_sum
				FROM `{$this->db->table('keywords')}` k
				JOIN `{$this->db->table(Dataset::Query->factTable())}` q ON q.project_id = k.project_id AND q.keyword_id = k.id AND q.date BETWEEN %s AND %s
				WHERE k.project_id = %d AND k.keyword_hash IN (" . Connection::placeholders($batch, 'UNHEX(%s)') . ')',
				[$start, $end, $context->projectId(), ...$batch],
			) ?? [];
			$total = $total->add(new Stats((int) ($row['clicks'] ?? 0), (int) ($row['impressions'] ?? 0), (float) ($row['position_sum'] ?? 0)));
		}

		return $total;
	}

	/**
	 * @param list<int|string> $pageIds
	 * @return array<int, string> id → adres bez fragmentu
	 */
	private function pageUrls(int $projectId, array $pageIds): array
	{
		$urls = [];

		foreach (array_chunk(array_values(array_unique(array_map('intval', $pageIds))), self::BATCH) as $batch) {
			foreach ($this->db->fetchAll(
				"SELECT id, url FROM `{$this->db->table('pages')}` WHERE project_id = %d AND id IN (" . Connection::placeholders($batch, '%d') . ')',
				[$projectId, ...$batch],
			) as $row) {
				$urls[(int) $row['id']] = UrlKey::normalize((string) $row['url']);
			}
		}

		return $urls;
	}

	/**
	 * @param array<string, string|null> $row
	 * @return array{0: Stats, 1: Stats}
	 */
	private static function stats(array $row): array
	{
		return [
			new Stats((int) $row['cur_clicks'], (int) $row['cur_impr'], (float) $row['cur_pos_sum']),
			new Stats((int) $row['prev_clicks'], (int) $row['prev_impr'], (float) $row['prev_pos_sum']),
		];
	}
}
