<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Market\CostBudget;
use OsfSeo\Market\Market;

/**
 * Plan pomiaru bez żadnego wywołania API: frazy, zadania, paczki POST, głębokość i szacowany MAKSYMALNY koszt
 * (koszt zgłoszony przez dostawcę po wykonaniu jest rozstrzygający) oraz ocena wspólnych limitów kosztów.
 */
final class SerpPlan
{
	public const NOT_CONFIGURED = 'not_configured';

	public const UNSUPPORTED_MARKET = 'unsupported_market';

	public const NO_KEYWORDS = 'no_keywords';

	public const NOTHING_TO_DO = 'nothing_to_do';

	/**
	 * @param list<array{id: int, market_keyword_id: int, keyword: string, location_code: int, language_code: string}> $keywords
	 * @param array{status: string, daily_limit: float, spent_today: float, monthly_limit: float, spent_month: float, max_tasks_per_run: int} $budget
	 */
	public function __construct(
		public readonly ?Market $market,
		public readonly ?SerpContext $context,
		public readonly SerpFrequency $frequency,
		public readonly ?string $skipReason,
		public readonly array $keywords,
		public readonly int $tracked,
		public readonly int $recent,
		public readonly float $costPerTask,
		public readonly int $tasksPerPost,
		public readonly array $budget,
	) {
	}

	public function tasks(): int
	{
		return count($this->keywords);
	}

	public function posts(): int
	{
		return (int) ceil($this->tasks() / max(1, $this->tasksPerPost));
	}

	public function estimatedCost(): float
	{
		return round($this->tasks() * $this->costPerTask, 6);
	}

	/** Koszt pełnego pomiaru wszystkich monitorowanych fraz (bez pomijania niedawno sprawdzonych). */
	public function fullMeasurementCost(): float
	{
		return round($this->tracked * $this->costPerTask, 6);
	}

	/** Szacowany koszt miesięczny pomiarów automatycznych przy wybranej częstotliwości. */
	public function monthlyCost(): float
	{
		return round($this->fullMeasurementCost() * $this->frequency->checksPerMonth(), 4);
	}

	public function remainingToday(): float
	{
		return max(0.0, round($this->budget['daily_limit'] - $this->budget['spent_today'], 6));
	}

	public function remainingMonth(): float
	{
		return max(0.0, round($this->budget['monthly_limit'] - $this->budget['spent_month'], 6));
	}

	/** Limit, który nie pozwala wykonać całego pomiaru (cały albo wcale — bez arbitralnego częściowego pomiaru). */
	public function blockedBy(): ?string
	{
		$cost = $this->estimatedCost();

		return match (true) {
			$cost <= 0 => null,
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
			'location_code' => $this->context?->locationCode,
			'language_code' => $this->context?->languageCode,
			'device' => $this->context?->device->value,
			'depth' => $this->context?->depth,
			'frequency' => $this->frequency->value,
			'tracked_keywords' => $this->tracked,
			'keywords' => $this->tasks(),
			'skipped_recent' => $this->recent,
			'tasks' => $this->tasks(),
			'posts' => $this->posts(),
			'cost_per_task' => $this->costPerTask,
			'estimated_max_cost' => $this->estimatedCost(),
			'full_measurement_cost' => $this->fullMeasurementCost(),
			'monthly_cost' => $this->monthlyCost(),
			'remaining_today' => $this->remainingToday(),
			'remaining_month' => $this->remainingMonth(),
			'blocked_by' => $this->blockedBy(),
		];
	}
}
