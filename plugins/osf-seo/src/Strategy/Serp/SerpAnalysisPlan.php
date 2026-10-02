<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

use OsfSeo\Market\CostBudget;
use OsfSeo\Market\Market;
use OsfSeo\Serp\SerpContext;

/**
 * Podgląd analizy SERP Strategii bez żadnego żądania (docs/ARCHITECTURE.md, sekcja 15.12, D56): dla każdej frazy — ponowne użycie
 * świeżego zgodnego pomiaru (bez kosztu), pomiar w toku (zlecony w oknie ponownego sprawdzenia), nowy pomiar albo odrzucenie z powodem;
 * liczba zadań, szacowany MAKSYMALNY koszt (rozstrzyga koszt zgłoszony przez dostawcę) i wspólne limity kosztów.
 */
final class SerpAnalysisPlan
{
	public const REUSE = 'reuse';

	public const PENDING = 'pending';

	public const MEASURE = 'measure';

	public const REJECTED = 'rejected';

	public const NOT_CONFIGURED = 'not_configured';

	public const UNSUPPORTED_MARKET = 'unsupported_market';

	public const NO_CANDIDATES = 'no_candidates';

	public const NOTHING_TO_DO = 'nothing_to_do';

	/** Jawny wybór wymaga więcej nowych pomiarów niż limit na uruchomienie — bez cichego obcinania. */
	public const OVER_RUN_LIMIT = 'over_run_limit';

	/**
	 * @param list<array{candidate: string, market_keyword_id: int, keyword: string, action: string, reason: string, snapshot: ?string, checked_at: ?string, freshness: ?string, tracking: ?string}> $items
	 * @param array{status: string, daily_limit: float, spent_today: float, monthly_limit: float, spent_month: float, max_tasks_per_run: int}|null $budget
	 */
	public function __construct(
		public readonly ?Market $market,
		public readonly ?SerpContext $context,
		public readonly ?string $skipReason,
		public readonly array $items,
		public readonly int $limit,
		public readonly bool $explicit,
		public readonly float $costPerTask,
		public readonly ?array $budget,
		public readonly bool $paused = false,
		public readonly bool $coolingDown = false,
	) {
	}

	/**
	 * @return list<array{candidate: string, market_keyword_id: int, keyword: string, action: string, reason: string, snapshot: ?string, checked_at: ?string, freshness: ?string, tracking: ?string}>
	 */
	public function byAction(string $action): array
	{
		return array_values(array_filter($this->items, static fn (array $item): bool => $item['action'] === $action));
	}

	public function tasks(): int
	{
		return count($this->byAction(self::MEASURE));
	}

	public function estimatedCost(): float
	{
		return round($this->tasks() * $this->costPerTask, 6);
	}

	/** Limit kosztów, który nie pozwala wykonać całej analizy (cała albo wcale). */
	public function blockedBy(): ?string
	{
		$cost = $this->estimatedCost();

		return match (true) {
			$this->budget === null || $cost <= 0 => null,
			$this->budget['spent_today'] + $cost > $this->budget['daily_limit'] + 1e-9 => CostBudget::DAILY_LIMIT,
			$this->budget['spent_month'] + $cost > $this->budget['monthly_limit'] + 1e-9 => CostBudget::MONTHLY_LIMIT,
			default => null,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'dry_run' => true,
			'api_requests' => 0,
			'skip_reason' => $this->skipReason,
			'market' => $this->market?->label(),
			'context' => $this->context?->toArray(),
			'selection' => $this->explicit ? 'explicit' : 'priority',
			'limit' => $this->limit,
			'keywords' => count($this->items),
			'reuse' => count($this->byAction(self::REUSE)),
			'pending' => count($this->byAction(self::PENDING)),
			'measure' => $this->tasks(),
			'rejected' => count($this->byAction(self::REJECTED)),
			'tasks' => $this->tasks(),
			'cost_per_task' => $this->costPerTask,
			'estimated_max_cost' => $this->estimatedCost(),
			'remaining_today' => $this->budget === null ? null : max(0.0, round($this->budget['daily_limit'] - $this->budget['spent_today'], 6)),
			'remaining_month' => $this->budget === null ? null : max(0.0, round($this->budget['monthly_limit'] - $this->budget['spent_month'], 6)),
			'blocked_by' => $this->blockedBy(),
			'paused' => $this->paused,
			'cooling_down' => $this->coolingDown,
			'items' => $this->items,
		];
	}
}
