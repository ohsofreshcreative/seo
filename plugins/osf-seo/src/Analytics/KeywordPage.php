<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

/** Strona listy fraz (paginacja po stronie serwera). */
final class KeywordPage
{
	/**
	 * @param list<KeywordRow> $rows
	 */
	public function __construct(
		public readonly ?Period $period,
		public readonly KeywordFilters $filters,
		public readonly array $rows,
		/** Liczba fraz spełniających filtry (wszystkie strony). */
		public readonly int $total,
		/** Rynek danych rynkowych projektu (null — rynek nieobsługiwany lub brak dostawcy). */
		public readonly ?\OsfSeo\Market\Market $market = null,
	) {
	}

	public function pages(): int
	{
		return max(1, (int) ceil($this->total / $this->filters->perPage));
	}

	public function hasData(): bool
	{
		return $this->period !== null;
	}
}
