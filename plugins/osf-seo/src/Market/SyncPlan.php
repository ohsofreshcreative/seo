<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use OsfSeo\Support\DateRange;

/**
 * Plan synchronizacji danych rynkowych projektu — liczony bez żadnego wywołania API (dry-run, podgląd w panelu,
 * potwierdzenie przed płatnym przebiegiem). Zadania przeplatane: wolumen 1, trudność 1, wolumen 2…, więc przy limicie
 * zadań najważniejsze frazy dostają obie metryki najpierw. Każde zadanie ma szacowany koszt i ocenę limitów.
 */
final class SyncPlan
{
	/**
	 * @param list<MarketCandidate> $candidates
	 * @param list<PlannedTask> $tasks
	 * @param array<string, mixed> $budget
	 */
	public function __construct(
		public readonly ?Market $market,
		public readonly ?string $skipReason,
		public readonly array $candidates,
		public readonly array $tasks,
		public readonly array $budget,
		public readonly ?DateRange $window = null,
		public readonly int $rejected = 0,
		public readonly int $duplicates = 0,
	) {
	}

	public static function skipped(?Market $market, string $reason, array $budget = []): self
	{
		return new self($market, $reason, [], [], $budget);
	}

	/**
	 * @param list<MarketCandidate> $candidates
	 */
	public static function build(Market $market, KeywordMetricsProvider $provider, array $candidates, CostBudget $budget, ?DateRange $window = null, int $rejected = 0, int $duplicates = 0): self
	{
		$size = $provider->maxKeywordsPerTask();
		$volume = array_chunk(array_values(array_map(static fn (MarketCandidate $c): string => $c->keyword, array_filter($candidates, static fn (MarketCandidate $c): bool => $c->needsVolume))), $size);
		$difficulty = array_chunk(array_values(array_map(static fn (MarketCandidate $c): string => $c->keyword, array_filter($candidates, static fn (MarketCandidate $c): bool => $c->needsDifficulty))), $size);
		$tasks = [];
		$simulation = clone $budget;

		for ($i = 0; $i < max(count($volume), count($difficulty)); $i++) {
			foreach ([[PlannedTask::VOLUME, $volume[$i] ?? null], [PlannedTask::DIFFICULTY, $difficulty[$i] ?? null]] as [$type, $keywords]) {
				if ($keywords === null) {
					continue;
				}

				$estimate = $type === PlannedTask::VOLUME ? $provider->estimateVolumeCost(count($keywords)) : $provider->estimateDifficultyCost(count($keywords));
				$blocked = $simulation->check($estimate);

				if ($blocked === CostBudget::OK) {
					$simulation->spend($estimate);
				}

				$tasks[] = new PlannedTask($type, $type === PlannedTask::VOLUME ? $provider->volumeEndpoint() : $provider->difficultyEndpoint(), $keywords, $estimate, $blocked);
			}
		}

		return new self($market, null, $candidates, $tasks, $budget->toArray(), $window, $rejected, $duplicates);
	}

	/**
	 * @return list<PlannedTask>
	 */
	public function allowedTasks(): array
	{
		return array_values(array_filter($this->tasks, static fn (PlannedTask $task): bool => $task->isAllowed()));
	}

	public function keywordCount(): int
	{
		return count($this->candidates);
	}

	/** Frazy, które trafią do wysłanych w tym przebiegu zadań (bez zablokowanych limitem). */
	public function keywordsToSend(): int
	{
		$keywords = [];

		foreach ($this->allowedTasks() as $task) {
			foreach ($task->keywords as $keyword) {
				$keywords[$keyword] = true;
			}
		}

		return count($keywords);
	}

	public function estimatedCost(): float
	{
		return round(array_sum(array_map(static fn (PlannedTask $task): float => $task->estimatedCost, $this->allowedTasks())), 6);
	}

	/** Pierwszy powód zablokowania zadania (limit kosztów lub zadań), null gdy wszystkie przejdą. */
	public function blockedBy(): ?string
	{
		foreach ($this->tasks as $task) {
			if (! $task->isAllowed()) {
				return $task->blockedBy;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$byType = fn (string $type): array => array_values(array_filter($this->tasks, static fn (PlannedTask $task): bool => $task->type === $type));
		$endpoint = static function (array $tasks): ?array {
			$first = $tasks[0] ?? null;

			return $first === null ? null : [
				'endpoint' => $first->endpoint->name,
				'mode' => $first->endpoint->mode,
				'path' => $first->endpoint->path,
				'tasks' => count($tasks),
				'tasks_allowed' => count(array_filter($tasks, static fn (PlannedTask $task): bool => $task->isAllowed())),
				'keywords' => array_sum(array_map(static fn (PlannedTask $task): int => count($task->keywords), $tasks)),
				'estimated_cost' => round(array_sum(array_map(static fn (PlannedTask $task): float => $task->isAllowed() ? $task->estimatedCost : 0.0, $tasks)), 6),
			];
		};

		return [
			'market' => $this->market?->label(),
			'location_code' => $this->market?->locationCode,
			'language_code' => $this->market?->languageCode,
			'skip_reason' => $this->skipReason,
			'window' => $this->window === null ? null : [$this->window->start, $this->window->end],
			'keywords' => $this->keywordCount(),
			'keywords_to_send' => $this->keywordsToSend(),
			'rejected_by_provider_rules' => $this->rejected,
			'merged_variants' => $this->duplicates,
			'tasks_total' => count($this->tasks),
			'tasks_allowed' => count($this->allowedTasks()),
			'estimated_cost' => $this->estimatedCost(),
			'blocked_by' => $this->blockedBy(),
			'volume' => $endpoint($byType(PlannedTask::VOLUME)),
			'difficulty' => $endpoint($byType(PlannedTask::DIFFICULTY)),
			'budget' => $this->budget,
		];
	}
}
