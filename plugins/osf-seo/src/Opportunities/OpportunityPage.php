<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Strona wyników listy szans (paginacja po stronie serwera). W widoku „wg podstron” elementami
 * są grupy (podstrona → szanse), a $total to liczba grup.
 */
final class OpportunityPage
{
	/**
	 * @param list<Opportunity> $rows
	 * @param list<array{page_url: ?string, count: int, max_priority: int, types: list<OpportunityType>, opportunities: list<Opportunity>}> $groups
	 */
	public function __construct(
		public readonly OpportunityFilters $filters,
		public readonly array $rows,
		public readonly int $total,
		public readonly array $groups = [],
	) {
	}

	public function pages(): int
	{
		return max(1, (int) ceil($this->total / $this->filters->perPage));
	}
}
