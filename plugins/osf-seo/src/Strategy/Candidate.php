<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Kandydat po scaleniu sygnałów wszystkich źródeł: jedna fraza rynkowa, maska źródeł, najważniejszy poziom i waga w nim.
 */
final class Candidate
{
	/**
	 * @param array<string, float> $weights kod źródła → waga sygnału
	 */
	public function __construct(
		public readonly string $keyHex,
		public readonly ?int $marketKeywordId,
		public readonly string $keyword,
		public readonly ?string $intent,
		public readonly int $sources,
		public readonly int $tier,
		public readonly float $weight,
		public readonly array $weights,
	) {
	}

	public function has(StrategySource $source): bool
	{
		return ($this->sources & $source->bit()) !== 0;
	}

	public function withMarketKeywordId(int $id): self
	{
		return new self($this->keyHex, $id, $this->keyword, $this->intent, $this->sources, $this->tier, $this->weight, $this->weights);
	}

	/**
	 * @return list<string>
	 */
	public function sourceCodes(): array
	{
		return array_map(static fn (StrategySource $source): string => $source->value, StrategySource::fromBits($this->sources));
	}
}
