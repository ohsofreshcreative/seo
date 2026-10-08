<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Filtry, sortowanie i paginacja listy kandydatów Strategii (wartości wyłącznie z białych list).
 * Domyślnie: aktywni kandydaci w kolejności poziomu źródła, potem wyświetleń GSC.
 */
final class CandidateFilters
{
	public const SORTS = ['tier', 'impressions', 'volume', 'position', 'serp_rank', 'keyword', 'first_seen'];

	public const ASCENDING = ['tier', 'position', 'serp_rank', 'keyword'];

	public const STATUSES = ['active', 'inactive', 'all'];

	public const PER_PAGE = 50;

	public function __construct(
		public readonly ?StrategySource $source = null,
		public readonly string $status = 'active',
		public readonly string $q = '',
		public readonly string $sort = 'tier',
		public readonly string $direction = 'asc',
		public readonly int $page = 1,
		public readonly int $perPage = self::PER_PAGE,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function fromInput(array $input): self
	{
		$string = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
		$sort = in_array($string('sort'), self::SORTS, true) ? $string('sort') : 'tier';
		$direction = in_array($string('dir'), ['asc', 'desc'], true) ? $string('dir') : (in_array($sort, self::ASCENDING, true) ? 'asc' : 'desc');
		$page = filter_var($input['page'] ?? null, FILTER_VALIDATE_INT);
		$perPage = filter_var($input['per_page'] ?? null, FILTER_VALIDATE_INT);

		return new self(
			source: StrategySource::tryFrom($string('source')),
			status: in_array($string('status'), self::STATUSES, true) ? $string('status') : 'active',
			q: mb_substr($string('q'), 0, 100),
			sort: $sort,
			direction: $direction,
			page: $page === false ? 1 : max(1, min(10000, $page)),
			perPage: $perPage === false ? self::PER_PAGE : max(1, min(500, $perPage)),
		);
	}

	public function offset(): int
	{
		return ($this->page - 1) * $this->perPage;
	}
}
