<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Discovery\DiscoveredKeyword;

/**
 * Filtry, sortowanie i paginacja listy luk fraz (wartości wyłącznie z białych list).
 * Domyślnie: luki (brak, słaba, nieznana) bez odrzuconych i odfiltrowanych, sortowane po priorytecie.
 */
final class GapFilters
{
	public const SORTS = ['priority', 'volume', 'difficulty', 'competitor_rank', 'competitors', 'keyword', 'first_seen'];

	public const ASCENDING = ['difficulty', 'competitor_rank', 'keyword'];

	public const PER_PAGE = 50;

	public function __construct(
		public readonly string $q = '',
		/** `gaps` (brak, słaba, nieznana), `all` albo typ luki. */
		public readonly string $type = 'gaps',
		/** `` (wszystkie poza odrzuconymi), `all` albo status. */
		public readonly string $status = '',
		public readonly ?string $content = null,
		public readonly ?string $visibility = null,
		public readonly ?string $intent = null,
		/** Identyfikator publiczny konkurenta (rankuje na frazę w progu znaczącej pozycji). */
		public readonly ?string $competitor = null,
		public readonly ?int $minVolume = null,
		public readonly ?int $maxDifficulty = null,
		public readonly ?int $minPriority = null,
		/** Pokaż frazy odfiltrowane (marka, wykluczenia, trafność) zamiast listy. */
		public readonly bool $filtered = false,
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
		$type = $string('type');
		$status = $string('status');
		$sort = in_array($string('sort'), self::SORTS, true) ? $string('sort') : 'priority';
		$direction = in_array($string('dir'), ['asc', 'desc'], true) ? $string('dir') : (in_array($sort, self::ASCENDING, true) ? 'asc' : 'desc');
		$competitor = strtoupper($string('competitor'));

		return new self(
			q: mb_substr($string('q'), 0, 100),
			type: in_array($type, ['gaps', 'all', ...array_map(static fn (GapType $case): string => $case->value, GapType::cases())], true) ? $type : 'gaps',
			status: in_array($status, ['all', ...array_map(static fn (GapStatus $case): string => $case->value, GapStatus::cases())], true) ? $status : '',
			content: ContentGap::fromInput($string('content'))?->value,
			visibility: ProjectVisibility::tryFrom($string('visibility'))?->value,
			intent: in_array($string('intent'), DiscoveredKeyword::INTENTS, true) ? $string('intent') : null,
			competitor: preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $competitor) === 1 ? $competitor : null,
			minVolume: $int('min_volume', 0, 100000000),
			maxDifficulty: $int('max_kd', 0, 100),
			minPriority: $int('min_priority', 0, 100),
			filtered: ($input['filtered'] ?? null) === '1',
			sort: $sort,
			direction: $direction,
			page: max(1, (int) ($int('page', 1, 100000) ?? 1)),
		);
	}

	/**
	 * Typy luki filtra (null — wszystkie).
	 *
	 * @return list<string>|null
	 */
	public function types(): ?array
	{
		return match ($this->type) {
			'all' => null,
			'gaps' => [GapType::Missing->value, GapType::Weak->value, GapType::Unknown->value],
			default => [$this->type],
		};
	}

	/**
	 * Parametry w URL (bez wartości domyślnych).
	 *
	 * @param array<string, string|int> $override
	 * @return array<string, string|int>
	 */
	public function toQuery(array $override = []): array
	{
		$query = [
			'q' => $this->q,
			'type' => $this->type === 'gaps' ? '' : $this->type,
			'status' => $this->status,
			'content' => $this->content ?? '',
			'visibility' => $this->visibility ?? '',
			'intent' => $this->intent ?? '',
			'competitor' => $this->competitor ?? '',
			'min_volume' => $this->minVolume ?? '',
			'max_kd' => $this->maxDifficulty ?? '',
			'min_priority' => $this->minPriority ?? '',
			'filtered' => $this->filtered ? '1' : '',
			'sort' => $this->sort === 'priority' ? '' : $this->sort,
			'dir' => $this->direction === (in_array($this->sort, self::ASCENDING, true) ? 'asc' : 'desc') ? '' : $this->direction,
			'page' => $this->page > 1 ? $this->page : '',
		];

		return array_filter(array_merge($query, $override), static fn (mixed $value): bool => $value !== '' && $value !== null);
	}

	public function withPage(int $page): self
	{
		return new self(
			$this->q, $this->type, $this->status, $this->content, $this->visibility, $this->intent, $this->competitor, $this->minVolume,
			$this->maxDifficulty, $this->minPriority, $this->filtered, $this->sort, $this->direction, max(1, $page), $this->perPage,
		);
	}

	public function withPerPage(int $perPage): self
	{
		return new self(
			$this->q, $this->type, $this->status, $this->content, $this->visibility, $this->intent, $this->competitor, $this->minVolume,
			$this->maxDifficulty, $this->minPriority, $this->filtered, $this->sort, $this->direction, $this->page, max(1, min(1000, $perPage)),
		);
	}
}
