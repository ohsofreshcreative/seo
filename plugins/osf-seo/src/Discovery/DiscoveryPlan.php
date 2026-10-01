<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Market\CostBudget;
use OsfSeo\Market\Market;

/**
 * Plan przebiegu wyszukiwania — liczony bez żadnego wywołania API (podgląd „Sprawdź koszt”, `discovery:plan`).
 * Maksymalny koszt = Σ (cena żądania + (limit elementów + 1) × cena elementu) po żądaniach seedów spoza cache.
 * Uruchomienie wymaga potwierdzenia liczby zadań i kosztu z podglądu (plan przeliczany ponownie przy starcie).
 */
final class DiscoveryPlan
{
	public const UNSUPPORTED_MARKET = 'unsupported_market';

	public const NOT_CONFIGURED = 'not_configured';

	public const NO_SEEDS = 'no_seeds';

	/**
	 * @param list<PlannedSeed> $seeds
	 * @param list<array{seed: string, reason: string}> $rejected
	 * @param array<string, mixed> $budget stan limitów kosztów (wspólny z danymi rynkowymi)
	 */
	public function __construct(
		public readonly ?Market $market,
		public readonly DiscoveryRequest $request,
		public readonly ?string $skipReason,
		public readonly array $seeds,
		public readonly array $rejected,
		/** Maksymalna liczba elementów na seed (limit kandydatów / liczba seedów, z granicą metody). */
		public readonly int $seedLimit,
		public readonly array $budget,
		public readonly int $ttlDays,
	) {
	}

	public function requests(): int
	{
		return array_sum(array_map(static fn (PlannedSeed $seed): int => $seed->requests, $this->seeds));
	}

	public function maxItems(): int
	{
		return array_sum(array_map(static fn (PlannedSeed $seed): int => $seed->maxItems, $this->seeds));
	}

	public function estimatedCost(): float
	{
		return round(array_sum(array_map(static fn (PlannedSeed $seed): float => $seed->estimatedCost, $this->seeds)), 6);
	}

	public function cachedSeeds(): int
	{
		return count(array_filter($this->seeds, static fn (PlannedSeed $seed): bool => $seed->isCached()));
	}

	public function remainingToday(): float
	{
		return round(max(0.0, (float) ($this->budget['daily_limit'] ?? 0) - (float) ($this->budget['spent_today'] ?? 0)), 6);
	}

	public function remainingMonth(): float
	{
		return round(max(0.0, (float) ($this->budget['monthly_limit'] ?? 0) - (float) ($this->budget['spent_month'] ?? 0)), 6);
	}

	/** Limit, którego maksymalny koszt planu by nie zmieścił (null — plan mieści się w limitach). */
	public function blockedBy(): ?string
	{
		$cost = $this->estimatedCost();

		return match (true) {
			$cost <= 0 => null,
			$cost > $this->remainingToday() + 1e-9 => CostBudget::DAILY_LIMIT,
			$cost > $this->remainingMonth() + 1e-9 => CostBudget::MONTHLY_LIMIT,
			default => null,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'market' => $this->market?->label(),
			'location_code' => $this->market?->locationCode,
			'language_code' => $this->market?->languageCode,
			'skip_reason' => $this->skipReason,
			'method' => $this->request->method->value,
			'depth' => $this->request->depth,
			'max_candidates' => $this->request->maxCandidates,
			'min_volume' => $this->request->minVolume,
			'max_difficulty' => $this->request->maxDifficulty,
			'skip_other_language' => $this->request->skipOtherLanguage,
			'force' => $this->request->force,
			'seed_limit' => $this->seedLimit,
			'seeds' => array_map(static fn (PlannedSeed $seed): array => [
				'seed' => $seed->seed,
				'source' => $seed->source,
				'requests' => $seed->requests,
				'max_items' => $seed->maxItems,
				'estimated_cost' => $seed->estimatedCost,
				'cached_at' => $seed->cachedAt,
			], $this->seeds),
			'rejected' => $this->rejected,
			'requests' => $this->requests(),
			'max_items' => $this->maxItems(),
			'estimated_max_cost' => $this->estimatedCost(),
			'cached_seeds' => $this->cachedSeeds(),
			'ttl_days' => $this->ttlDays,
			'remaining_today' => $this->remainingToday(),
			'remaining_month' => $this->remainingMonth(),
			'blocked_by' => $this->blockedBy(),
			'budget' => $this->budget,
		];
	}
}
