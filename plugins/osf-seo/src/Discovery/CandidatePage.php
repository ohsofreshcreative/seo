<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Strona listy nowych fraz.
 */
final class CandidatePage
{
	/**
	 * @param list<CandidateRow> $rows
	 */
	public function __construct(
		public readonly CandidateFilters $filters,
		public readonly array $rows,
		public readonly int $total,
	) {
	}

	public function pages(): int
	{
		return max(1, (int) ceil($this->total / $this->filters->perPage));
	}
}
