<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use OsfSeo\Analytics\Metrics;

/**
 * Wynik `gsc:probe`. Sumy dotyczą zwróconych wierszy: przy wymiarze `[date]` i limicie ≥ liczbie dni
 * to sumy property; przy wymiarze `query` — tylko pokazane frazy (bez zanonimizowanych).
 */
final class ProbeResult
{
	/**
	 * @param list<array{keys: list<string>, clicks: int, impressions: int, position: float}> $rows
	 */
	public function __construct(
		public readonly string $property,
		public readonly string $permission,
		public readonly SearchAnalyticsRequest $request,
		public readonly array $rows,
		public readonly int $clicks,
		public readonly int $impressions,
		public readonly ?float $ctr,
		public readonly ?float $position,
		public readonly string $aggregationType,
		public readonly int $apiRequests,
		public readonly int $durationMs,
	) {
	}

	/** Zwrócono tyle wierszy, ile wynosił limit — Google może mieć ich więcej. */
	public function limitReached(): bool
	{
		return count($this->rows) >= $this->request->rowLimit;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'property' => $this->property,
			'permission' => $this->permission,
			'start_date' => $this->request->range->start,
			'end_date' => $this->request->range->end,
			'dimensions' => $this->request->dimensions,
			'data_state' => $this->request->dataState,
			'row_limit' => $this->request->rowLimit,
			'rows_returned' => count($this->rows),
			'limit_reached' => $this->limitReached(),
			'clicks' => $this->clicks,
			'impressions' => $this->impressions,
			'ctr' => $this->ctr,
			'average_position' => $this->position,
			'aggregation_type' => $this->aggregationType,
			'api_requests' => $this->apiRequests,
			'duration_ms' => $this->durationMs,
			'sample' => array_map(fn (array $row): array => $this->sampleRow($row), $this->rows),
		];
	}

	/**
	 * @param array{keys: list<string>, clicks: int, impressions: int, position: float} $row
	 * @return array<string, mixed>
	 */
	public function sampleRow(array $row): array
	{
		$sample = [];

		foreach ($this->request->dimensions as $index => $dimension) {
			$sample[$dimension] = $row['keys'][$index];
		}

		return $sample + [
			'clicks' => $row['clicks'],
			'impressions' => $row['impressions'],
			'ctr' => Metrics::ctr($row['clicks'], $row['impressions']),
			'position' => round($row['position'], 2),
		];
	}
}
