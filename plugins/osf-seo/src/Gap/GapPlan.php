<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Market\Market;

/**
 * Plan importu bez wywołań API (podgląd „Sprawdź koszt”, `gap:plan`): domeny, stan zbiorów, żądania, maksymalny
 * i szacowany koszt, budżet DataForSEO (wspólny z danymi rynkowymi, Nowymi frazami i Pozycjami SERP).
 *
 * Maksymalny koszt = każda strona pełna aż do limitu fraz domeny (górna granica, potwierdzana przez użytkownika);
 * szacowany = liczba fraz z ostatniego importu o tych samych filtrach. Rozstrzyga koszt zgłoszony przez dostawcę.
 */
final class GapPlan
{
	public const UNSUPPORTED_MARKET = 'unsupported_market';

	public const NO_COMPETITORS = 'no_competitors';

	public const NO_TARGETS = 'no_targets';

	/**
	 * @param list<PlannedTarget> $targets
	 * @param list<array{domain: string, label: string, reason: string}> $skipped
	 */
	public function __construct(
		public readonly ?Market $market,
		public readonly ?string $skipReason,
		public readonly GapRequest $request,
		public readonly array $targets,
		public readonly array $skipped,
		public readonly float $requestPrice,
		public readonly float $itemPrice,
		public readonly float $dailyLimit,
		public readonly float $spentToday,
		public readonly float $monthlyLimit,
		public readonly float $spentMonth,
	) {
	}

	/**
	 * @return list<PlannedTarget>
	 */
	public function imports(): array
	{
		return array_values(array_filter($this->targets, static fn (PlannedTarget $target): bool => $target->needsImport()));
	}

	/** Najwięcej płatnych żądań (górna granica). */
	public function requests(): int
	{
		return array_sum(array_map(static fn (PlannedTarget $target): int => $target->mayImport() ? $target->maxRequests : 0, $this->targets));
	}

	/** Maksymalny koszt (USD) — potwierdzany przez użytkownika. */
	public function estimatedCost(): float
	{
		return round(array_sum(array_map(static fn (PlannedTarget $target): float => $target->mayImport() ? $target->maxCost : 0.0, $this->targets)), 6);
	}

	public function expectedRequests(): int
	{
		return array_sum(array_map(static fn (PlannedTarget $target): int => $target->expectedRequests, $this->imports()));
	}

	public function expectedCost(): float
	{
		return round(array_sum(array_map(static fn (PlannedTarget $target): float => $target->expectedCost, $this->imports())), 6);
	}

	public function remainingToday(): float
	{
		return round(max(0.0, $this->dailyLimit - $this->spentToday), 6);
	}

	public function remainingMonth(): float
	{
		return round(max(0.0, $this->monthlyLimit - $this->spentMonth), 6);
	}

	/** Koszt pierwszego żądania (pełna strona) — import ruszy dziś, jeśli mieści się w dziennym limicie. */
	public function firstRequestCost(): float
	{
		$first = $this->imports()[0] ?? null;

		return $first === null ? 0.0 : round($this->requestPrice + min($first->coverage->maxRows, ImportPages::PAGE) * $this->itemPrice, 6);
	}

	/**
	 * Blokada uruchomienia: szacowany koszt przekracza pozostały limit miesięczny (import nie mógłby się zakończyć
	 * w tym miesiącu) albo limit dzienny wynosi 0. Dzienny limit mniejszy od kosztu nie blokuje — import rozkłada się
	 * na kolejne dni (przebieg czeka, aż limit się odnowi).
	 */
	public function blockedBy(): ?string
	{
		if ($this->imports() === []) {
			return null;
		}

		return match (true) {
			$this->dailyLimit <= 0.0 => 'daily_limit',
			$this->expectedCost() > $this->remainingMonth() + 1e-6 => 'monthly_limit',
			default => null,
		};
	}

	/** Import nie zmieści się dziś w dziennym limicie — rozłoży się na kolejne dni. */
	public function spansDays(): bool
	{
		return $this->imports() !== [] && $this->expectedCost() > $this->remainingToday() + 1e-6;
	}

	/** Przybliżona liczba dni przy dziennym limicie (dla szacowanego kosztu). */
	public function daysEstimate(): ?int
	{
		if ($this->dailyLimit <= 0.0 || $this->imports() === []) {
			return null;
		}

		$remaining = max(0.0, $this->expectedCost() - $this->remainingToday());

		return 1 + (int) ceil($remaining / $this->dailyLimit - 1e-9);
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
			'location_code' => $this->market?->locationCode,
			'language_code' => $this->market?->languageCode,
			'coverage' => $this->request->coverage->toArray(),
			'baseline' => $this->request->baseline,
			'force' => $this->request->force,
			'targets' => array_map(static fn (PlannedTarget $target): array => $target->toArray(), $this->targets),
			'skipped' => $this->skipped,
			'requests' => $this->requests(),
			'estimated_max_cost' => $this->estimatedCost(),
			'expected_requests' => $this->expectedRequests(),
			'expected_cost' => $this->expectedCost(),
			'price_per_request' => $this->requestPrice,
			'price_per_item' => $this->itemPrice,
			'daily_limit' => $this->dailyLimit,
			'spent_today' => round($this->spentToday, 6),
			'remaining_today' => $this->remainingToday(),
			'monthly_limit' => $this->monthlyLimit,
			'spent_month' => round($this->spentMonth, 6),
			'remaining_month' => $this->remainingMonth(),
			'spans_days' => $this->spansDays(),
			'days_estimate' => $this->daysEstimate(),
			'blocked_by' => $this->blockedBy(),
		];
	}
}
