<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Domena w planie importu: konkurent albo domena projektu (punkt odniesienia) z oczekiwaną liczbą żądań i kosztem.
 */
final class PlannedTarget
{
	public const ROLE_COMPETITOR = 'competitor';

	public const ROLE_PROJECT = 'project';

	/** Świeży zbiór obejmuje zakres — bez żądań (z pamięci). */
	public const CACHED = 'cached';

	/** Import (płatny). */
	public const IMPORT = 'import';

	/** Zbiór jest właśnie importowany przez inny projekt — przebieg poczeka i użyje wyniku (bez opłaty). */
	public const WAITING = 'waiting';

	public function __construct(
		public readonly string $role,
		public readonly ?int $competitorId,
		public readonly ?string $competitorPublicId,
		public readonly string $label,
		public readonly string $domain,
		public readonly Coverage $coverage,
		public readonly string $state,
		public readonly ?GapDomain $dataset,
		/** Liczba fraz zakresu znana z ostatniego importu o tych samych filtrach (null — nieznana). */
		public readonly ?int $knownTotal,
		public readonly int $maxRequests,
		public readonly float $maxCost,
		public readonly int $expectedRequests,
		public readonly float $expectedCost,
	) {
	}

	public function needsImport(): bool
	{
		return $this->state === self::IMPORT;
	}

	/** Import nastąpi albo może nastąpić (czekanie na import innego projektu, który może się nie udać) — w maksimum kosztu. */
	public function mayImport(): bool
	{
		return $this->state === self::IMPORT || $this->state === self::WAITING;
	}

	public function stateLabel(): string
	{
		return match ($this->state) {
			self::CACHED => 'z pamięci (import z ' . substr((string) $this->dataset?->importedAt, 0, 10) . ')',
			self::WAITING => 'czeka na trwający import innego projektu (zwykle bez kosztu)',
			default => $this->dataset?->wasImported() === true ? 'odświeżenie' : 'nowy import',
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'role' => $this->role,
			'competitor' => $this->competitorPublicId,
			'label' => $this->label,
			'domain' => $this->domain,
			'coverage' => $this->coverage->toArray(),
			'state' => $this->state,
			'imported_at' => $this->dataset?->importedAt,
			'stale_after' => $this->dataset?->staleAfter,
			'known_total' => $this->knownTotal,
			'max_requests' => $this->maxRequests,
			'max_cost' => $this->maxCost,
			'expected_requests' => $this->expectedRequests,
			'expected_cost' => $this->expectedCost,
		];
	}
}
