<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Filtry, sortowanie i paginacja listy monitorowanych fraz (serwerowo).
 */
final class PositionsFilters
{
	public const SORTS = ['rank', 'change', 'keyword', 'volume', 'difficulty', 'checked', 'added'];

	public const CHANGES = ['all', 'up', 'down', 'entered', 'left', 'new', 'out', 'top10_entered', 'top10_left'];

	public const BANDS = ['all', 'top3', 'top10', 'top20', 'top50', 'found', 'out', 'unchecked'];

	public const PER_PAGE = 50;

	public function __construct(
		public readonly string $q = '',
		public readonly string $change = 'all',
		public readonly string $band = 'all',
		public readonly string $sort = 'rank',
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
		$sort = in_array($string('sort'), self::SORTS, true) ? $string('sort') : 'rank';
		$direction = in_array($string('dir'), ['asc', 'desc'], true) ? $string('dir') : (in_array($sort, ['rank', 'keyword', 'difficulty'], true) ? 'asc' : 'desc');

		return new self(
			mb_substr($string('q'), 0, 100),
			in_array($string('change'), self::CHANGES, true) ? $string('change') : 'all',
			in_array($string('band'), self::BANDS, true) ? $string('band') : 'all',
			$sort,
			$direction,
			max(1, min(100000, (int) $string('page'))),
		);
	}

	/**
	 * @param array<string, string|int> $override
	 * @return array<string, string|int>
	 */
	public function toQuery(array $override = []): array
	{
		return array_filter($override + [
			'q' => $this->q,
			'change' => $this->change === 'all' ? '' : $this->change,
			'band' => $this->band === 'all' ? '' : $this->band,
			'sort' => $this->sort === 'rank' ? '' : $this->sort,
			'dir' => $this->direction,
			'page' => $this->page > 1 ? $this->page : '',
		], static fn (string|int $value): bool => $value !== '');
	}

	public function offset(): int
	{
		return ($this->page - 1) * $this->perPage;
	}
}
