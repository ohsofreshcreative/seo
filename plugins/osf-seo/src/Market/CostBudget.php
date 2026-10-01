<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Lokalny bezpiecznik kosztów jednego przebiegu: limity dzienny i miesięczny (USD, z rejestru zadań)
 * oraz twardy limit płatnych zadań na przebieg. Sprawdzany PRZED każdym płatnym wywołaniem —
 * gdy szacowany koszt przekroczyłby limit, synchronizacja się zatrzymuje (nie tylko ostrzega).
 */
final class CostBudget
{
	public const OK = 'ok';

	public const DAILY_LIMIT = 'daily_limit';

	public const MONTHLY_LIMIT = 'monthly_limit';

	public const TASK_LIMIT = 'task_limit';

	private int $tasks = 0;

	public function __construct(
		public readonly float $dailyLimit,
		public readonly float $monthlyLimit,
		private float $spentToday,
		private float $spentMonth,
		public readonly int $maxTasks,
	) {
	}

	/** Powód blokady kolejnego zadania o szacowanym koszcie (albo `ok`). */
	public function check(float $estimatedCost): string
	{
		return match (true) {
			$this->tasks >= $this->maxTasks => self::TASK_LIMIT,
			$this->spentToday + $estimatedCost > $this->dailyLimit + 1e-9 => self::DAILY_LIMIT,
			$this->spentMonth + $estimatedCost > $this->monthlyLimit + 1e-9 => self::MONTHLY_LIMIT,
			default => self::OK,
		};
	}

	public function allows(float $estimatedCost): bool
	{
		return $this->check($estimatedCost) === self::OK;
	}

	/** Zadanie wysłane — liczy się do limitów (koszt zgłoszony albo szacowany). */
	public function spend(float $cost): void
	{
		$this->tasks++;
		$this->spentToday += $cost;
		$this->spentMonth += $cost;
	}

	/** Stan limitów kosztów bez limitu zadań (status, panel). */
	public function status(): string
	{
		return match (true) {
			$this->spentToday >= $this->dailyLimit => self::DAILY_LIMIT,
			$this->spentMonth >= $this->monthlyLimit => self::MONTHLY_LIMIT,
			default => self::OK,
		};
	}

	public function spentToday(): float
	{
		return round($this->spentToday, 6);
	}

	public function spentMonth(): float
	{
		return round($this->spentMonth, 6);
	}

	public function tasks(): int
	{
		return $this->tasks;
	}

	/**
	 * @return array{status: string, daily_limit: float, spent_today: float, monthly_limit: float, spent_month: float, max_tasks_per_run: int}
	 */
	public function toArray(): array
	{
		return [
			'status' => $this->status(),
			'daily_limit' => $this->dailyLimit,
			'spent_today' => $this->spentToday(),
			'monthly_limit' => $this->monthlyLimit,
			'spent_month' => $this->spentMonth(),
			'max_tasks_per_run' => $this->maxTasks,
		];
	}

	public static function label(string $status): string
	{
		return match ($status) {
			self::DAILY_LIMIT => 'osiągnięto dzienny limit kosztów',
			self::MONTHLY_LIMIT => 'osiągnięto miesięczny limit kosztów',
			self::TASK_LIMIT => 'osiągnięto limit zadań na przebieg',
			default => 'w limicie',
		};
	}
}
