<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Discovery\DiscoveredKeyword;
use OsfSeo\Strategy\Decision\StrategyAction;
use OsfSeo\Strategy\StrategySource;
use OsfSeo\Strategy\Target\TargetState;

/**
 * Filtry listy tematów (backlog): działanie, status (domyślnie otwarte), minimalny priorytet, poziom pewności, źródło dowodów, intencja
 * (frazy głównej), stan strony docelowej, stan SERP, popyt i trudność SEO (frazy głównej), zmiana po decyzji, wyszukiwanie (temat i frazy),
 * sortowanie (domyślnie priorytet), stan (aktywne / nieaktywne / wszystkie). Monitorowanie domyślnie ukryte (chyba że filtr działania = monitor
 * albo `includeMonitor`) — domyślna lista to aktywne tematy wymagające działania.
 */
final class TopicFilters
{
	public const SORTS = ['priority', 'confidence', 'demand', 'keywords', 'label', 'updated', 'serp_rank', 'gsc_position'];

	/** Sortowania domyślnie rosnące. */
	public const ASCENDING = ['label', 'serp_rank', 'gsc_position'];

	public const STATUSES = ['open', 'all', 'new', 'review', 'planned', 'in_progress', 'completed', 'dismissed'];

	public const STATES = ['active', 'inactive', 'all'];

	public const LEVELS = ['low', 'medium', 'high'];

	/**
	 * Stan SERP tematu: pasmo Pozycji SERP świeżego pomiaru, `fresh` (dowolny świeży), `stale` (31–90 dni), `none` (brak pomiaru),
	 * `nofresh` (brak świeżego — nieaktualny albo brak; jak licznik przeglądu).
	 */
	public const SERP = ['fresh', 'top3', 'top10', 'top20', 'top50', 'top100', 'out', 'stale', 'none', 'nofresh'];

	public const PER_PAGE = 50;

	public function __construct(
		public readonly ?StrategyAction $action = null,
		public readonly string $status = 'open',
		public readonly ?string $confidence = null,
		public readonly string $q = '',
		public readonly string $sort = 'priority',
		public readonly string $direction = 'desc',
		public readonly int $page = 1,
		public readonly int $perPage = self::PER_PAGE,
		public readonly bool $includeMonitor = false,
		public readonly string $state = 'active',
		public readonly ?int $minPriority = null,
		public readonly ?StrategySource $source = null,
		public readonly ?string $intent = null,
		public readonly ?TargetState $target = null,
		public readonly ?string $serp = null,
		public readonly ?int $minVolume = null,
		public readonly ?int $maxDifficulty = null,
		public readonly bool $changed = false,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function fromInput(array $input): self
	{
		$string = static fn (string $key): string => is_string($input[$key] ?? null) ? trim((string) $input[$key]) : '';
		$int = static function (string $key, int $min, int $max) use ($input): ?int {
			$value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

			return $value === false ? null : (int) $value;
		};
		$sort = in_array($string('sort'), self::SORTS, true) ? $string('sort') : 'priority';
		$direction = in_array($string('dir'), ['asc', 'desc'], true) ? $string('dir') : (in_array($string('direction'), ['asc', 'desc'], true) ? $string('direction') : (in_array($sort, self::ASCENDING, true) ? 'asc' : 'desc'));

		return new self(
			StrategyAction::fromInput($input['action'] ?? null),
			in_array($string('status'), self::STATUSES, true) ? $string('status') : 'open',
			in_array($string('confidence'), self::LEVELS, true) ? $string('confidence') : null,
			mb_substr($string('q'), 0, 200),
			$sort,
			$direction,
			$int('page', 1, 100000) ?? 1,
			$int('per_page', 1, 500) ?? self::PER_PAGE,
			in_array($input['include_monitor'] ?? false, [true, '1', 1, 'on'], true),
			in_array($string('state'), self::STATES, true) ? $string('state') : 'active',
			$int('min_priority', 0, 100),
			StrategySource::tryFrom($string('source')),
			in_array($string('intent'), DiscoveredKeyword::INTENTS, true) ? $string('intent') : null,
			TargetState::tryFrom($string('target')),
			in_array($string('serp'), self::SERP, true) ? $string('serp') : null,
			$int('min_volume', 0, 100000000),
			$int('max_kd', 0, 100),
			in_array($input['changed'] ?? false, [true, '1', 1, 'on'], true),
		);
	}

	public function offset(): int
	{
		return ($this->page - 1) * $this->perPage;
	}

	/**
	 * Parametry adresu listy (bez wartości domyślnych) z nadpisaniami.
	 *
	 * @param array<string, mixed> $override
	 * @return array<string, mixed>
	 */
	public function toQuery(array $override = []): array
	{
		$query = [
			'q' => $this->q,
			'action' => $this->action?->value ?? '',
			'status' => $this->status === 'open' ? '' : $this->status,
			'confidence' => $this->confidence ?? '',
			'state' => $this->state === 'active' ? '' : $this->state,
			'min_priority' => $this->minPriority ?? '',
			'source' => $this->source?->value ?? '',
			'intent' => $this->intent ?? '',
			'target' => $this->target?->value ?? '',
			'serp' => $this->serp ?? '',
			'min_volume' => $this->minVolume ?? '',
			'max_kd' => $this->maxDifficulty ?? '',
			'changed' => $this->changed ? '1' : '',
			'include_monitor' => $this->includeMonitor ? '1' : '',
			'sort' => $this->sort === 'priority' ? '' : $this->sort,
			'dir' => $this->direction === (in_array($this->sort, self::ASCENDING, true) ? 'asc' : 'desc') ? '' : $this->direction,
			'page' => $this->page > 1 ? $this->page : '',
		];

		return array_filter(array_merge($query, $override), static fn (mixed $value): bool => $value !== '' && $value !== null);
	}

	public function withPage(int $page): self
	{
		return self::fromInput(['page' => max(1, $page), 'per_page' => $this->perPage, 'include_monitor' => $this->includeMonitor, 'changed' => $this->changed] + $this->toQuery(['page' => '']));
	}

	/** Czy filtry inne niż domyślne (komunikat pustej listy). */
	public function isDefault(): bool
	{
		return $this->toQuery(['sort' => '', 'dir' => '', 'page' => '']) === [];
	}
}
