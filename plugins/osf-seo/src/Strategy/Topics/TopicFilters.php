<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Strategy\Decision\StrategyAction;

/**
 * Filtry listy tematów: działanie, status (domyślnie otwarte), poziom pewności, wyszukiwanie, sortowanie (domyślnie priorytet),
 * stan (aktywne / nieaktywne / wszystkie). Monitorowanie domyślnie ukryte (chyba że filtr działania = monitor albo `includeMonitor`).
 */
final class TopicFilters
{
	public const SORTS = ['priority', 'confidence', 'demand', 'keywords', 'label', 'updated'];

	public const STATUSES = ['open', 'all', 'new', 'review', 'planned', 'in_progress', 'completed', 'dismissed'];

	public const STATES = ['active', 'inactive', 'all'];

	public const LEVELS = ['low', 'medium', 'high'];

	public function __construct(
		public readonly ?StrategyAction $action = null,
		public readonly string $status = 'open',
		public readonly ?string $confidence = null,
		public readonly string $q = '',
		public readonly string $sort = 'priority',
		public readonly string $direction = 'desc',
		public readonly int $page = 1,
		public readonly int $perPage = 50,
		public readonly bool $includeMonitor = false,
		public readonly string $state = 'active',
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function fromInput(array $input): self
	{
		$action = StrategyAction::fromInput($input['action'] ?? null);
		$status = is_string($input['status'] ?? null) && in_array($input['status'], self::STATUSES, true) ? $input['status'] : 'open';
		$confidence = is_string($input['confidence'] ?? null) && in_array($input['confidence'], self::LEVELS, true) ? $input['confidence'] : null;
		$sort = is_string($input['sort'] ?? null) && in_array($input['sort'], self::SORTS, true) ? $input['sort'] : 'priority';
		$direction = ($input['direction'] ?? null) === 'asc' ? 'asc' : (($input['direction'] ?? null) === 'desc' ? 'desc' : ($sort === 'label' ? 'asc' : 'desc'));
		$state = is_string($input['state'] ?? null) && in_array($input['state'], self::STATES, true) ? $input['state'] : 'active';

		return new self(
			$action,
			$status,
			$confidence,
			mb_substr(trim((string) ($input['q'] ?? '')), 0, 200),
			$sort,
			$direction,
			max(1, (int) ($input['page'] ?? 1)),
			max(1, min(500, (int) ($input['per_page'] ?? 50))),
			(bool) ($input['include_monitor'] ?? false),
			$state,
		);
	}

	public function offset(): int
	{
		return ($this->page - 1) * $this->perPage;
	}
}
