<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

/**
 * Przegląd projektu dla dashboardu. Sumy kliknięć/wyświetleń, CTR i średnia pozycja projektu pochodzą
 * z `gsc_site_daily` (zapytanie GSC `[date]`, z zapytaniami zanonimizowanymi) — nie z sumy fraz.
 */
final class Overview
{
	/**
	 * @param array{clicks: int, impressions: int, position_sum: float} $current
	 * @param array{clicks: int, impressions: int, position_sum: float} $previous
	 * @param array{clicks: int, impressions: int} $queryTotals sumy widocznych fraz w bieżącym okresie
	 * @param list<KeywordRow> $gains
	 * @param list<KeywordRow> $losses
	 * @param array{dates: list<string>, previous_dates: list<string>, clicks: list<int>, impressions: list<int>, previous_clicks: list<int>, previous_impressions: list<int>} $series
	 */
	public function __construct(
		public readonly Period $period,
		public readonly array $current,
		public readonly array $previous,
		public readonly array $queryTotals,
		public readonly Visibility $visibility,
		public readonly array $gains,
		public readonly array $losses,
		public readonly int $moversMinImpressions,
		public readonly array $series,
	) {
	}

	public function ctr(): ?float
	{
		return Metrics::ctr($this->current['clicks'], $this->current['impressions']);
	}

	public function previousCtr(): ?float
	{
		return Metrics::ctr($this->previous['clicks'], $this->previous['impressions']);
	}

	public function position(): ?float
	{
		return Metrics::position($this->current['position_sum'], $this->current['impressions']);
	}

	public function previousPosition(): ?float
	{
		return Metrics::position($this->previous['position_sum'], $this->previous['impressions']);
	}

	public function positionChange(): ?float
	{
		return Metrics::positionChange($this->previousPosition(), $this->position());
	}

	/** Udział kliknięć z widocznych fraz w kliknięciach projektu (reszta: zapytania zanonimizowane). */
	public function visibleQueryClicksShare(): ?float
	{
		return $this->current['clicks'] > 0 ? min(1.0, $this->queryTotals['clicks'] / $this->current['clicks']) : null;
	}
}
