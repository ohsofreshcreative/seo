<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

/**
 * Filtry, sortowanie i paginacja listy fraz — z zapytania HTTP/CLI, zawsze przez białą listę
 * (wartości spoza listy wracają do domyślnych; nic nie trafia do SQL bez walidacji).
 */
final class KeywordFilters
{
	public const SORTS = ['clicks', 'clicks_change', 'impressions', 'impressions_change', 'ctr', 'position', 'position_change', 'keyword', 'volume', 'difficulty'];

	/** Sortowania rosnące domyślnie (mniejsza wartość = lepiej / alfabetycznie). */
	public const ASCENDING = ['position', 'keyword', 'difficulty'];

	public const PER_PAGE = [25, 50, 100];

	public const MOVEMENTS = ['gains', 'losses'];

	public function __construct(
		public readonly int $days = Period::DEFAULT_DAYS,
		public readonly string $search = '',
		public readonly ?float $positionMin = null,
		public readonly ?float $positionMax = null,
		public readonly ?int $minImpressions = null,
		/** gains | losses | null */
		public readonly ?string $movement = null,
		public readonly string $sort = 'clicks',
		public readonly string $direction = 'desc',
		public readonly int $page = 1,
		public readonly int $perPage = 50,
		/** Minimalny wolumen (dane rynkowe); fraza bez znanego wolumenu nie spełnia filtra. */
		public readonly ?int $minVolume = null,
		/** Maksymalna trudność SEO 0–100; fraza bez znanej trudności nie spełnia filtra. */
		public readonly ?int $maxDifficulty = null,
	) {
	}

	/**
	 * @param array<string, mixed> $input np. $request->query->all()
	 */
	public static function fromInput(array $input): self
	{
		$sort = in_array($input['sort'] ?? null, self::SORTS, true) ? (string) $input['sort'] : 'clicks';
		$defaultDirection = in_array($sort, self::ASCENDING, true) ? 'asc' : 'desc';
		$direction = in_array($input['dir'] ?? null, ['asc', 'desc'], true) ? (string) $input['dir'] : $defaultDirection;
		$search = is_string($input['q'] ?? null) ? mb_substr(trim((string) $input['q']), 0, 100) : '';
		$perPage = (int) ($input['per_page'] ?? 50);

		return new self(
			days: Period::days($input['days'] ?? null),
			search: $search,
			positionMin: self::position($input['pos_min'] ?? null),
			positionMax: self::position($input['pos_max'] ?? null),
			minImpressions: isset($input['min_impr']) && is_numeric($input['min_impr']) && (int) $input['min_impr'] > 0 ? min((int) $input['min_impr'], 100000000) : null,
			movement: in_array($input['movement'] ?? null, self::MOVEMENTS, true) ? (string) $input['movement'] : null,
			sort: $sort,
			direction: $direction,
			page: max(1, min((int) ($input['page'] ?? 1), 100000)),
			perPage: in_array($perPage, self::PER_PAGE, true) ? $perPage : 50,
			minVolume: self::bounded($input['min_volume'] ?? null, 0, 1000000000),
			maxDifficulty: self::bounded($input['max_kd'] ?? null, 0, 100),
		);
	}

	public function with(array $changes): self
	{
		$values = get_object_vars($this);

		return new self(...array_merge($values, $changes));
	}

	/**
	 * Parametry zapytania dla linków (bez wartości domyślnych).
	 *
	 * @return array<string, string|int|float>
	 */
	public function toQuery(): array
	{
		return array_filter([
			'days' => $this->days !== Period::DEFAULT_DAYS ? $this->days : null,
			'q' => $this->search !== '' ? $this->search : null,
			'pos_min' => $this->positionMin,
			'pos_max' => $this->positionMax,
			'min_impr' => $this->minImpressions,
			'movement' => $this->movement,
			'sort' => $this->sort !== 'clicks' ? $this->sort : null,
			'dir' => $this->direction,
			'per_page' => $this->perPage !== 50 ? $this->perPage : null,
			'min_volume' => $this->minVolume,
			'max_kd' => $this->maxDifficulty,
			'page' => $this->page > 1 ? $this->page : null,
		], static fn (mixed $value): bool => $value !== null);
	}

	/** Filtry danych rynkowych są ustawione (wymagają znanej wartości). */
	public function hasMarketFilters(): bool
	{
		return $this->minVolume !== null || $this->maxDifficulty !== null;
	}

	private static function bounded(mixed $value, int $min, int $max): ?int
	{
		if (! is_numeric($value) || (string) $value === '') {
			return null;
		}

		$number = (int) $value;

		return $number >= $min && $number <= $max ? $number : null;
	}

	private static function position(mixed $value): ?float
	{
		if (! is_numeric($value)) {
			return null;
		}

		$position = round((float) $value, 1);

		return $position >= 1 && $position <= 1000 ? $position : null;
	}
}
