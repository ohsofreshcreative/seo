<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Analytics;

use OsfSeo\Analytics\KeywordRow;

final class OverviewReportTest extends AnalyticsTestCase
{
	public function test_kpis_come_from_site_totals_not_keyword_sums(): void
	{
		$context = $this->readyProject();
		$this->seedFixture($context);

		$overview = $this->overview->overview($context, 7);

		self::assertSame('2026-01-14', $overview->period->latestDate);
		// Bieżący okres: 6 dni sum (2026-01-11 bez danych) × 20 kliknięć / 1000 wyświetleń.
		self::assertSame(['clicks' => 120, 'impressions' => 6000, 'position_sum' => 48000.0], $overview->current);
		self::assertSame(['clicks' => 70, 'impressions' => 7000, 'position_sum' => 84000.0], $overview->previous);
		self::assertSame(0.02, $overview->ctr());
		self::assertSame(0.01, $overview->previousCtr());
		self::assertSame(8.0, $overview->position());
		self::assertSame(12.0, $overview->previousPosition());
		self::assertSame(4.0, $overview->positionChange(), '12 → 8 = +4 (poprawa).');

		// Frazy widoczne w GSC: 25 kliknięć z 120 — reszta to zapytania zanonimizowane (bez wyrównywania).
		self::assertSame(['clicks' => 25, 'impressions' => 363], $overview->queryTotals);
		self::assertEqualsWithDelta(25 / 120, $overview->visibleQueryClicksShare(), 1e-12);
	}

	public function test_visibility_buckets_are_cumulative_and_based_on_average_position(): void
	{
		$context = $this->readyProject();
		$this->seedFixture($context);

		$visibility = $this->overview->overview($context, 7)->visibility;

		self::assertSame([3 => 2, 10 => 5, 20 => 6, 50 => 8, 100 => 9], $visibility->current, 'Granice włącznie: 3,0 ∈ TOP 3, 10,0 ∈ TOP 10, 100,0 ∈ TOP 100, 100,5 ∉; lambda (ważona 29,3) w TOP 50, nie w TOP 20.');
		self::assertSame([3 => 1, 10 => 2, 20 => 3, 50 => 4, 100 => 5], $visibility->previous);
		self::assertSame(10, $visibility->keywordsCurrent);
		self::assertSame(5, $visibility->keywordsPrevious);
		self::assertSame(3, $visibility->change(10));
	}

	public function test_biggest_gains_and_losses(): void
	{
		$context = $this->readyProject();
		$this->seedFixture($context);

		$overview = $this->overview->overview($context, 7);

		self::assertSame(['zeta', 'alfa'], array_map(static fn (KeywordRow $row): string => $row->keyword, $overview->gains));
		self::assertSame(['beta'], array_map(static fn (KeywordRow $row): string => $row->keyword, $overview->losses));
		self::assertSame(10, $overview->moversMinImpressions);
	}

	public function test_daily_series_fills_missing_days_and_aligns_previous_period(): void
	{
		$context = $this->readyProject();
		$this->seedFixture($context);

		$series = $this->overview->overview($context, 7)->series;

		self::assertSame(['2026-01-08', '2026-01-09', '2026-01-10', '2026-01-11', '2026-01-12', '2026-01-13', '2026-01-14'], $series['dates']);
		self::assertSame([20, 20, 20, 0, 20, 20, 20], $series['clicks']);
		self::assertSame([10, 10, 10, 10, 10, 10, 10], $series['previous_clicks']);
		self::assertSame(['2026-01-01', '2026-01-07'], [$series['previous_dates'][0], $series['previous_dates'][6]]);
	}

	public function test_period_end_waits_for_both_datasets(): void
	{
		$context = $this->readyProject();
		$this->seedFixture($context);
		$db = self::db();
		$db->insert($db->table('gsc_site_daily'), ['project_id' => $context->projectId(), 'date' => '2026-01-15', 'device' => 0, 'clicks' => 5, 'impressions' => 50, 'position_sum' => 100.0]);

		self::assertSame('2026-01-14', $this->overview->latestDate($context), 'Frazy jeszcze bez 15.01 — okres kończy się na wspólnej dacie.');
		self::assertNull($this->overview->overview($this->readyProject('empty.pl'), 28));
	}

	public function test_overview_cache_is_invalidated_by_new_import(): void
	{
		$context = $this->readyProject();
		$this->seedFixture($context);
		$cached = new \OsfSeo\Analytics\OverviewReport(self::db(), $this->keywords, new \OsfSeo\Analytics\ReportCache());

		self::assertSame(120, $cached->overview($context, 7)->current['clicks']);

		$db = self::db();
		$db->execute("UPDATE `{$db->table('gsc_site_daily')}` SET clicks = clicks + 1 WHERE project_id = %d AND date = '2026-01-14'", [$context->projectId()]);
		self::assertSame(120, $cached->overview($context, 7)->current['clicks'], 'Bez nowego importu — wynik z cache.');

		// Import zapisuje last_synced_at → nowy klucz cache.
		$this->clock->advance(5);
		$this->projects->touchLastSynced($context->projectId());
		$context = $context->withProject($this->projects->reload($context->project()));
		self::assertSame(121, $cached->overview($context, 7)->current['clicks']);
	}
}
