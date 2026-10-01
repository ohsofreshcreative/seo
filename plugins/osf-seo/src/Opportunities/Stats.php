<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Analytics\Metrics;

/**
 * Sumy metryk GSC w okresie. CTR i średnia pozycja liczone wyłącznie z sum (Metrics):
 * CTR = clicks / impressions, pozycja = position_sum / impressions.
 */
final class Stats
{
	public function __construct(
		public readonly int $clicks = 0,
		public readonly int $impressions = 0,
		public readonly float $positionSum = 0.0,
	) {
	}

	public function add(self $other): self
	{
		return new self($this->clicks + $other->clicks, $this->impressions + $other->impressions, $this->positionSum + $other->positionSum);
	}

	public function ctr(): ?float
	{
		return Metrics::ctr($this->clicks, $this->impressions);
	}

	public function position(): ?float
	{
		return Metrics::position($this->positionSum, $this->impressions);
	}

	public function hasData(): bool
	{
		return $this->impressions > 0;
	}

	/**
	 * @return array{clicks: int, impressions: int, position_sum: float}
	 */
	public function toArray(): array
	{
		return ['clicks' => $this->clicks, 'impressions' => $this->impressions, 'position_sum' => round($this->positionSum, 4)];
	}

	/**
	 * @param array<string, mixed>|null $data
	 */
	public static function fromArray(?array $data): self
	{
		return new self((int) ($data['clicks'] ?? 0), (int) ($data['impressions'] ?? 0), (float) ($data['position_sum'] ?? 0));
	}
}
