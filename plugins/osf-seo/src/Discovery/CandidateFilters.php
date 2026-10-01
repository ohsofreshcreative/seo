<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Filtry, sortowanie i paginacja listy nowych fraz (wartości wyłącznie z białych list).
 * Domyślnie: frazy czekające na decyzję (Nowa, Do analizy) z luką widoczności (brak, słaba, nieznana), bez wykluczonych,
 * sortowane po priorytecie.
 */
final class CandidateFilters
{
	public const SORTS = ['priority', 'volume', 'difficulty', 'position', 'discovered', 'keyword'];

	public const ASCENDING = ['difficulty', 'position', 'keyword'];

	public const PER_PAGE = 50;

	public function __construct(
		public readonly string $q = '',
		/** `open` (Nowa + Do analizy), `all` albo wartość statusu. */
		public readonly string $status = 'open',
		/** `gap` (brak, słaba, nieznana), `all` albo wartość widoczności. */
		public readonly string $visibility = 'gap',
		public readonly ?int $minVolume = null,
		public readonly ?int $maxDifficulty = null,
		public readonly ?int $minPriority = null,
		public readonly ?string $intent = null,
		/** Pokaż frazy pasujące do wykluczeń projektu (domyślnie ukryte). */
		public readonly bool $excluded = false,
		public readonly string $sort = 'priority',
		public readonly string $direction = 'desc',
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
		$int = static function (string $key, int $min, int $max) use ($input): ?int {
			$value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);

			return $value === false ? null : max($min, min($max, $value));
		};
		$status = $string('status');
		$visibility = $string('visibility');
		$sort = in_array($string('sort'), self::SORTS, true) ? $string('sort') : 'priority';
		$direction = in_array($string('dir'), ['asc', 'desc'], true) ? $string('dir') : (in_array($sort, self::ASCENDING, true) ? 'asc' : 'desc');
		$intent = $string('intent');

		return new self(
			q: mb_substr($string('q'), 0, 100),
			status: in_array($status, ['open', 'all', ...CandidateStatus::values()], true) ? $status : 'open',
			visibility: in_array($visibility, ['gap', 'all', ...Visibility::values()], true) ? $visibility : 'gap',
			minVolume: $int('min_volume', 0, 100000000),
			maxDifficulty: $int('max_kd', 0, 100),
			minPriority: $int('min_priority', 0, 100),
			intent: in_array($intent, DiscoveredKeyword::INTENTS, true) ? $intent : null,
			excluded: ($input['excluded'] ?? null) === '1',
			sort: $sort,
			direction: $direction,
			page: max(1, min(10000, (int) ($int('page', 1, 10000) ?? 1))),
		);
	}

	/**
	 * Parametry w URL (bez wartości domyślnych).
	 *
	 * @return array<string, string|int>
	 */
	public function toQuery(array $override = []): array
	{
		$query = [
			'q' => $this->q,
			'status' => $this->status === 'open' ? '' : $this->status,
			'visibility' => $this->visibility === 'gap' ? '' : $this->visibility,
			'min_volume' => $this->minVolume ?? '',
			'max_kd' => $this->maxDifficulty ?? '',
			'min_priority' => $this->minPriority ?? '',
			'intent' => $this->intent ?? '',
			'excluded' => $this->excluded ? '1' : '',
			'sort' => $this->sort === 'priority' ? '' : $this->sort,
			'dir' => $this->direction === (in_array($this->sort, self::ASCENDING, true) ? 'asc' : 'desc') ? '' : $this->direction,
			'page' => $this->page > 1 ? $this->page : '',
		];

		return array_filter(array_merge($query, $override), static fn (mixed $value): bool => $value !== '' && $value !== null);
	}

	public function withPage(int $page): self
	{
		return new self($this->q, $this->status, $this->visibility, $this->minVolume, $this->maxDifficulty, $this->minPriority, $this->intent, $this->excluded, $this->sort, $this->direction, max(1, $page), $this->perPage);
	}
}
