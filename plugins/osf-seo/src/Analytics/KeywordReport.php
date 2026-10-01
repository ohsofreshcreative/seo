<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Config;

/**
 * Lista fraz z porównaniem okresów — jedno zapytanie agregujące oba okresy w jednym skanie
 * `gsc_query_daily` (zakres PK: project_id, date), filtry w HAVING, sortowanie z białej listy,
 * LIMIT/OFFSET i liczba wszystkich wyników (COUNT(*) OVER()) w tym samym zapytaniu. Dla frazy z bieżącej
 * strony — jedno dodatkowe zapytanie o stronę docelową (`gsc_query_page_daily`, indeks fraza → data → strona).
 *
 * Metryki: CTR = SUM(clicks) / SUM(impressions), średnia pozycja (GSC) = SUM(position_sum) / SUM(impressions),
 * zmiana pozycji = poprzednia − obecna (dodatnia = wzrost). Wzrosty/spadki tylko dla fraz z co najmniej
 * MIN_IMPRESSIONS wyświetleń w obu okresach (bez szumu fraz z 1–2 wyświetleniami).
 */
final class KeywordReport
{
	/** Domyślny próg wyświetleń (w każdym z okresów) dla wzrostów i spadków. */
	public const MOVERS_MIN_IMPRESSIONS = 10;

	/** Sumy obu okresów w jednym skanie (6 parametrów: początek bieżącego okresu). */
	private const PERIOD_COLUMNS = 'SUM(CASE WHEN q.date >= %s THEN q.clicks ELSE 0 END) AS cur_clicks,
		SUM(CASE WHEN q.date >= %s THEN q.impressions ELSE 0 END) AS cur_impr,
		SUM(CASE WHEN q.date >= %s THEN q.position_sum ELSE 0 END) AS cur_pos_sum,
		SUM(CASE WHEN q.date < %s THEN q.clicks ELSE 0 END) AS prev_clicks,
		SUM(CASE WHEN q.date < %s THEN q.impressions ELSE 0 END) AS prev_impr,
		SUM(CASE WHEN q.date < %s THEN q.position_sum ELSE 0 END) AS prev_pos_sum';

	public function __construct(
		private readonly Connection $db,
		private readonly Config $config = new Config(),
	) {
	}

	public function moversMinImpressions(): int
	{
		$value = $this->config->get('OSF_SEO_MOVERS_MIN_IMPRESSIONS');

		return $value !== null && ctype_digit(trim($value)) ? max(1, (int) $value) : self::MOVERS_MIN_IMPRESSIONS;
	}

	/** Ostatnia data z danymi fraz projektu (null = brak danych). */
	public function latestDate(ProjectContext $context): ?string
	{
		return $this->db->fetchValue(
			"SELECT MAX(date) FROM `{$this->db->table('gsc_query_daily')}` WHERE project_id = %d",
			[$context->projectId()],
		);
	}

	public function keywords(ProjectContext $context, KeywordFilters $filters, ?Period $period = null): KeywordPage
	{
		if ($period === null) {
			$latest = $this->latestDate($context);

			if ($latest === null) {
				return new KeywordPage(null, $filters, [], 0);
			}

			$period = new Period($latest, $filters->days);
		}

		$projectId = $context->projectId();
		$curStart = $period->current->start;
		$params = [$curStart, $curStart, $curStart, $curStart, $curStart, $curStart, $projectId, $period->previous->start, $period->current->end];
		$where = '';
		$having = ['cur_impr > 0'];

		if ($filters->search !== '') {
			$where = " AND q.keyword_id IN (SELECT id FROM `{$this->db->table('keywords')}` WHERE project_id = %d AND keyword LIKE %s)";
			$params[] = $projectId;
			$params[] = '%' . $this->db->escapeLike($filters->search) . '%';
		}

		if ($filters->positionMin !== null) {
			$having[] = 'cur_pos_sum / cur_impr >= %f';
			$params[] = $filters->positionMin;
		}

		if ($filters->positionMax !== null) {
			$having[] = 'cur_pos_sum / cur_impr <= %f';
			$params[] = $filters->positionMax;
		}

		if ($filters->minImpressions !== null) {
			$having[] = 'cur_impr >= %d';
			$params[] = $filters->minImpressions;
		}

		if ($filters->movement !== null) {
			$min = $this->moversMinImpressions();
			$having[] = 'prev_impr >= %d AND cur_impr >= %d';
			$having[] = $filters->movement === 'gains'
				? 'prev_pos_sum / prev_impr - cur_pos_sum / cur_impr > 0'
				: 'prev_pos_sum / prev_impr - cur_pos_sum / cur_impr < 0';
			$params[] = $min;
			$params[] = $min;
		}

		$params[] = $projectId;
		$params[] = $filters->perPage;
		$params[] = ($filters->page - 1) * $filters->perPage;

		$sql = "SELECT a.*, k.keyword, COUNT(*) OVER () AS total_rows
			FROM (
				SELECT q.keyword_id, " . self::PERIOD_COLUMNS . "
				FROM `{$this->db->table('gsc_query_daily')}` q
				WHERE q.project_id = %d AND q.date BETWEEN %s AND %s{$where}
				GROUP BY q.keyword_id
				HAVING " . implode(' AND ', $having) . "
			) a
			JOIN `{$this->db->table('keywords')}` k ON k.id = a.keyword_id AND k.project_id = %d
			ORDER BY " . self::orderBy($filters) . '
			LIMIT %d OFFSET %d';

		$records = $this->db->fetchAll($sql, $params);
		$rows = array_map(static fn (array $record): KeywordRow => KeywordRow::fromRow($record), $records);
		$total = $records === [] ? 0 : (int) $records[0]['total_rows'];

		if ($records === [] && $filters->page > 1) {
			// Strona poza zakresem — liczba wyników z pierwszej strony.
			$total = $this->keywords($context, $filters->with(['page' => 1, 'perPage' => 25]), $period)->total;
		}

		$this->attachPrimaryPages($projectId, $rows, $period);

		return new KeywordPage($period, $filters, $rows, $total);
	}

	/**
	 * Wszystkie frazy okresu z porównaniem — bez paginacji, z progiem szumu: fraza musi mieć co najmniej
	 * $minImpressions wyświetleń w bieżącym albo poprzednim okresie. Ten sam skan co lista fraz
	 * (zakres PK project_id, date); do PHP trafiają wyłącznie sumy per fraza (analiza szans). Bez ORDER BY
	 * (kolejność nie ma znaczenia dla analizy).
	 *
	 * @return list<KeywordRow>
	 */
	public function aggregate(ProjectContext $context, Period $period, int $minImpressions): array
	{
		$curStart = $period->current->start;
		$projectId = $context->projectId();
		$min = max(1, $minImpressions);

		// STRAIGHT_JOIN: najpierw agregat projektu, potem słownik po PRIMARY (eq_ref) — bez tego planista potrafi
		// skanować cały słownik fraz wszystkich projektów i dopiero dopasowywać agregat.
		$records = $this->db->fetchAll(
			"SELECT STRAIGHT_JOIN a.*, k.keyword
			FROM (
				SELECT q.keyword_id, " . self::PERIOD_COLUMNS . "
				FROM `{$this->db->table('gsc_query_daily')}` q
				WHERE q.project_id = %d AND q.date BETWEEN %s AND %s
				GROUP BY q.keyword_id
				HAVING cur_impr >= %d OR prev_impr >= %d
			) a
			JOIN `{$this->db->table('keywords')}` k ON k.id = a.keyword_id AND k.project_id = %d",
			[$curStart, $curStart, $curStart, $curStart, $curStart, $curStart, $projectId, $period->previous->start, $period->current->end, $min, $min, $projectId],
		);

		return array_map(static fn (array $record): KeywordRow => KeywordRow::fromRow($record), $records);
	}

	/**
	 * Strona docelowa frazy w bieżącym okresie: najwięcej kliknięć, potem wyświetleń (dane `query_page`,
	 * agregowane przez Google per strona). Jedno zapytanie dla wszystkich fraz strony wyników.
	 *
	 * @param list<KeywordRow> $rows
	 */
	private function attachPrimaryPages(int $projectId, array $rows, Period $period): void
	{
		if ($rows === []) {
			return;
		}

		$ids = array_map(static fn (KeywordRow $row): int => $row->keywordId, $rows);
		$candidates = $this->db->fetchAll(
			"SELECT qp.keyword_id, qp.page_id, SUM(qp.clicks) AS clicks, SUM(qp.impressions) AS impressions
			FROM `{$this->db->table('gsc_query_page_daily')}` qp
			WHERE qp.project_id = %d AND qp.keyword_id IN (" . Connection::placeholders($ids, '%d') . ') AND qp.date BETWEEN %s AND %s
			GROUP BY qp.keyword_id, qp.page_id',
			[$projectId, ...$ids, $period->current->start, $period->current->end],
		);

		$best = [];

		foreach ($candidates as $candidate) {
			$keywordId = (int) $candidate['keyword_id'];
			$score = [(int) $candidate['clicks'], (int) $candidate['impressions'], -(int) $candidate['page_id']];

			if (! isset($best[$keywordId]) || $score > $best[$keywordId]['score']) {
				$best[$keywordId] = ['score' => $score, 'page_id' => (int) $candidate['page_id']];
			}
		}

		if ($best === []) {
			return;
		}

		$pageIds = array_values(array_unique(array_column($best, 'page_id')));
		$urls = [];

		foreach ($this->db->fetchAll(
			"SELECT id, url FROM `{$this->db->table('pages')}` WHERE project_id = %d AND id IN (" . Connection::placeholders($pageIds, '%d') . ')',
			[$projectId, ...$pageIds],
		) as $page) {
			$urls[(int) $page['id']] = (string) $page['url'];
		}

		foreach ($rows as $row) {
			$pageId = $best[$row->keywordId]['page_id'] ?? null;
			$row->primaryPage = $pageId === null ? null : ($urls[$pageId] ?? null);
		}
	}

	/** ORDER BY wyłącznie z białej listy; NULL (brak porównania) zawsze na końcu; stabilny tie-break. */
	private static function orderBy(KeywordFilters $filters): string
	{
		$direction = $filters->direction === 'asc' ? 'ASC' : 'DESC';
		$expression = match ($filters->sort) {
			'impressions' => 'a.cur_impr',
			'impressions_change' => '(CAST(a.cur_impr AS SIGNED) - CAST(a.prev_impr AS SIGNED))',
			'clicks_change' => '(CAST(a.cur_clicks AS SIGNED) - CAST(a.prev_clicks AS SIGNED))',
			'ctr' => 'a.cur_clicks / NULLIF(a.cur_impr, 0)',
			'position' => 'a.cur_pos_sum / NULLIF(a.cur_impr, 0)',
			'position_change' => 'a.prev_pos_sum / NULLIF(a.prev_impr, 0) - a.cur_pos_sum / NULLIF(a.cur_impr, 0)',
			'keyword' => 'k.keyword',
			default => 'a.cur_clicks',
		};

		return "({$expression} IS NULL), {$expression} {$direction}, a.cur_impr DESC, a.keyword_id ASC";
	}
}
