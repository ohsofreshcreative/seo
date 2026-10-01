<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

use OsfSeo\Market\MarketMetrics;

/**
 * Fraza w okresie i jej porównanie z poprzednim okresem. Metryki z sum (Metrics).
 * Opcjonalnie dane rynkowe (wolumen, trudność SEO…) — dodatkowe źródło, nie zastępują metryk GSC.
 */
final class KeywordRow
{
	public function __construct(
		public readonly int $keywordId,
		public readonly string $keyword,
		public readonly int $clicks,
		public readonly int $impressions,
		public readonly float $positionSum,
		public readonly int $previousClicks,
		public readonly int $previousImpressions,
		public readonly float $previousPositionSum,
		public ?string $primaryPage = null,
		/** Metryki rynkowe frazy na rynku projektu; null — brak danych (nie 0). */
		public ?MarketMetrics $market = null,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			(int) $row['keyword_id'],
			(string) $row['keyword'],
			(int) $row['cur_clicks'],
			(int) $row['cur_impr'],
			(float) $row['cur_pos_sum'],
			(int) $row['prev_clicks'],
			(int) $row['prev_impr'],
			(float) $row['prev_pos_sum'],
			market: MarketMetrics::fromRow($row, 'm_'),
		);
	}

	public function ctr(): ?float
	{
		return Metrics::ctr($this->clicks, $this->impressions);
	}

	public function previousCtr(): ?float
	{
		return Metrics::ctr($this->previousClicks, $this->previousImpressions);
	}

	/** Zmiana CTR w punktach (udział); brak przy braku wyświetleń w którymkolwiek okresie. */
	public function ctrChange(): ?float
	{
		$current = $this->ctr();
		$previous = $this->previousCtr();

		return $current === null || $previous === null ? null : $current - $previous;
	}

	/** Średnia pozycja (GSC) w okresie. */
	public function position(): ?float
	{
		return Metrics::position($this->positionSum, $this->impressions);
	}

	public function previousPosition(): ?float
	{
		return Metrics::position($this->previousPositionSum, $this->previousImpressions);
	}

	/** poprzednia − obecna: dodatnia = poprawa (wzrost), ujemna = spadek. */
	public function positionChange(): ?float
	{
		return Metrics::positionChange($this->previousPosition(), $this->position());
	}

	public function clicksChange(): int
	{
		return $this->clicks - $this->previousClicks;
	}

	public function impressionsChange(): int
	{
		return $this->impressions - $this->previousImpressions;
	}

	/** Fraza bez wyświetleń w poprzednim okresie. */
	public function isNew(): bool
	{
		return $this->previousImpressions === 0;
	}
}
