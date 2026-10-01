<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Support\DateRange;

/**
 * Dashboard projektu: KPI (sumy witryny), widoczność TOP N, największe wzrosty/spadki, udział widocznych
 * fraz i dzienna seria do wykresu. Każdy element to jedno zapytanie agregujące (bez N+1).
 *
 * Koniec okresu = ostatnia data obecna zarówno w sumach witryny, jak i we frazach (oba zbiory kompletne),
 * żeby KPI i tabele fraz dotyczyły tych samych dni.
 */
final class OverviewReport
{
	public const MOVERS_LIMIT = 10;

	public function __construct(
		private readonly Connection $db,
		private readonly KeywordReport $keywords,
		private readonly ?ReportCache $cache = null,
	) {
	}

	public function latestDate(ProjectContext $context): ?string
	{
		$site = $this->db->fetchValue(
			"SELECT MAX(date) FROM `{$this->db->table('gsc_site_daily')}` WHERE project_id = %d AND device = %d",
			[$context->projectId(), Dataset::DEVICE_ALL],
		);
		$query = $this->keywords->latestDate($context);

		return match (true) {
			$site === null => null,
			$query === null => $site,
			default => min($site, $query),
		};
	}

	public function overview(ProjectContext $context, int $days = Period::DEFAULT_DAYS): ?Overview
	{
		$latest = $this->latestDate($context);

		if ($latest === null) {
			return null;
		}

		$period = new Period($latest, Period::days($days));
		$project = $context->project();
		$key = ['overview', $project->publicId, $period->days, $latest, $project->lastSyncedAt?->format('Y-m-d H:i:s'), $project->gscDataProperty, $this->keywords->moversMinImpressions()];

		return $this->cache === null ? $this->compute($context, $period) : $this->cache->remember($key, fn (): Overview => $this->compute($context, $period));
	}

	private function compute(ProjectContext $context, Period $period): Overview
	{
		$min = $this->keywords->moversMinImpressions();
		$movers = static fn (string $movement, string $direction): KeywordFilters => new KeywordFilters(
			days: $period->days,
			movement: $movement,
			sort: 'position_change',
			direction: $direction,
			perPage: 25,
		);

		[$current, $previous] = $this->siteTotals($context, $period);

		return new Overview(
			period: $period,
			current: $current,
			previous: $previous,
			queryTotals: $this->queryTotals($context, $period),
			visibility: $this->visibility($context, $period),
			gains: array_slice($this->keywords->keywords($context, $movers('gains', 'desc'), $period)->rows, 0, self::MOVERS_LIMIT),
			losses: array_slice($this->keywords->keywords($context, $movers('losses', 'asc'), $period)->rows, 0, self::MOVERS_LIMIT),
			moversMinImpressions: $min,
			series: $this->series($context, $period),
		);
	}

	/**
	 * @return array{0: array{clicks: int, impressions: int, position_sum: float}, 1: array{clicks: int, impressions: int, position_sum: float}}
	 */
	private function siteTotals(ProjectContext $context, Period $period): array
	{
		$start = $period->current->start;
		$row = $this->db->fetchRow(
			"SELECT
				SUM(CASE WHEN date >= %s THEN clicks ELSE 0 END) AS cur_clicks,
				SUM(CASE WHEN date >= %s THEN impressions ELSE 0 END) AS cur_impr,
				SUM(CASE WHEN date >= %s THEN position_sum ELSE 0 END) AS cur_pos_sum,
				SUM(CASE WHEN date < %s THEN clicks ELSE 0 END) AS prev_clicks,
				SUM(CASE WHEN date < %s THEN impressions ELSE 0 END) AS prev_impr,
				SUM(CASE WHEN date < %s THEN position_sum ELSE 0 END) AS prev_pos_sum
			FROM `{$this->db->table('gsc_site_daily')}`
			WHERE project_id = %d AND device = %d AND date BETWEEN %s AND %s",
			[$start, $start, $start, $start, $start, $start, $context->projectId(), Dataset::DEVICE_ALL, $period->previous->start, $period->current->end],
		) ?? [];

		return [
			['clicks' => (int) ($row['cur_clicks'] ?? 0), 'impressions' => (int) ($row['cur_impr'] ?? 0), 'position_sum' => (float) ($row['cur_pos_sum'] ?? 0)],
			['clicks' => (int) ($row['prev_clicks'] ?? 0), 'impressions' => (int) ($row['prev_impr'] ?? 0), 'position_sum' => (float) ($row['prev_pos_sum'] ?? 0)],
		];
	}

	/**
	 * @return array{clicks: int, impressions: int}
	 */
	private function queryTotals(ProjectContext $context, Period $period): array
	{
		$row = $this->db->fetchRow(
			"SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions FROM `{$this->db->table('gsc_query_daily')}`
			WHERE project_id = %d AND date BETWEEN %s AND %s",
			[$context->projectId(), $period->current->start, $period->current->end],
		) ?? [];

		return ['clicks' => (int) ($row['clicks'] ?? 0), 'impressions' => (int) ($row['impressions'] ?? 0)];
	}

	private function visibility(ProjectContext $context, Period $period): Visibility
	{
		$start = $period->current->start;
		$buckets = [];

		foreach (Visibility::BUCKETS as $bucket) {
			$buckets[] = "SUM(cur_pos <= {$bucket}) AS cur_top{$bucket}, SUM(prev_pos <= {$bucket}) AS prev_top{$bucket}";
		}

		$row = $this->db->fetchRow(
			'SELECT ' . implode(', ', $buckets) . ", SUM(cur_pos IS NOT NULL) AS cur_total, SUM(prev_pos IS NOT NULL) AS prev_total
			FROM (
				SELECT keyword_id,
					SUM(CASE WHEN date >= %s THEN position_sum ELSE 0 END) / NULLIF(SUM(CASE WHEN date >= %s THEN impressions ELSE 0 END), 0) AS cur_pos,
					SUM(CASE WHEN date < %s THEN position_sum ELSE 0 END) / NULLIF(SUM(CASE WHEN date < %s THEN impressions ELSE 0 END), 0) AS prev_pos
				FROM `{$this->db->table('gsc_query_daily')}`
				WHERE project_id = %d AND date BETWEEN %s AND %s
				GROUP BY keyword_id
			) t",
			[$start, $start, $start, $start, $context->projectId(), $period->previous->start, $period->current->end],
		) ?? [];

		$current = [];
		$previous = [];

		foreach (Visibility::BUCKETS as $bucket) {
			$current[$bucket] = (int) ($row['cur_top' . $bucket] ?? 0);
			$previous[$bucket] = (int) ($row['prev_top' . $bucket] ?? 0);
		}

		return new Visibility($current, $previous, (int) ($row['cur_total'] ?? 0), (int) ($row['prev_total'] ?? 0));
	}

	/**
	 * Dzienna seria sum witryny: bieżący okres i poprzedni (wyrównany dzień do dnia) — brakujące dni = 0.
	 *
	 * @return array{dates: list<string>, previous_dates: list<string>, clicks: list<int>, impressions: list<int>, previous_clicks: list<int>, previous_impressions: list<int>}
	 */
	private function series(ProjectContext $context, Period $period): array
	{
		$daily = [];

		foreach ($this->db->fetchAll(
			"SELECT date, clicks, impressions FROM `{$this->db->table('gsc_site_daily')}`
			WHERE project_id = %d AND device = %d AND date BETWEEN %s AND %s ORDER BY date",
			[$context->projectId(), Dataset::DEVICE_ALL, $period->previous->start, $period->current->end],
		) as $row) {
			$daily[(string) $row['date']] = [(int) $row['clicks'], (int) $row['impressions']];
		}

		$series = ['dates' => [], 'previous_dates' => [], 'clicks' => [], 'impressions' => [], 'previous_clicks' => [], 'previous_impressions' => []];

		for ($i = 0; $i < $period->days; $i++) {
			$date = DateRange::shift($period->current->start, $i);
			$previousDate = DateRange::shift($period->previous->start, $i);
			$series['dates'][] = $date;
			$series['previous_dates'][] = $previousDate;
			$series['clicks'][] = $daily[$date][0] ?? 0;
			$series['impressions'][] = $daily[$date][1] ?? 0;
			$series['previous_clicks'][] = $daily[$previousDate][0] ?? 0;
			$series['previous_impressions'][] = $daily[$previousDate][1] ?? 0;
		}

		return $series;
	}
}
