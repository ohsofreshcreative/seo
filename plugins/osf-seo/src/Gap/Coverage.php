<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use InvalidArgumentException;

/**
 * Zakres importu fraz domeny (filtry dostawcy): najgorsza pozycja, minimalny wolumen i limit fraz. Zakres jest zapisany
 * przy zbiorze domeny — import innego projektu z węższym lub równym zakresem korzysta ze świeżych danych bez opłaty.
 */
final class Coverage
{
	public function __construct(
		public readonly int $maxRank,
		public readonly int $minVolume,
		public readonly int $maxRows,
	) {
		if ($maxRank < 1 || $maxRank > 100 || $minVolume < 0 || $maxRows < 1) {
			throw new InvalidArgumentException('Invalid gap coverage.');
		}
	}

	/** Ten zakres zawiera wszystkie frazy zakresu $other (szerszy lub równy w każdym wymiarze). */
	public function includes(self $other): bool
	{
		return $this->maxRank >= $other->maxRank && $this->minVolume <= $other->minVolume && $this->maxRows >= $other->maxRows;
	}

	public function sameFilters(self $other): bool
	{
		return $this->maxRank === $other->maxRank && $this->minVolume === $other->minVolume;
	}

	public function label(): string
	{
		return sprintf('TOP%d, wolumen ≥ %s, maks. %s fraz', $this->maxRank, number_format($this->minVolume, 0, ',', ' '), number_format($this->maxRows, 0, ',', ' '));
	}

	/**
	 * @return array{max_rank: int, min_volume: int, max_rows: int}
	 */
	public function toArray(): array
	{
		return ['max_rank' => $this->maxRank, 'min_volume' => $this->minVolume, 'max_rows' => $this->maxRows];
	}
}
