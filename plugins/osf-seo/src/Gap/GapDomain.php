<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Zbiór fraz domeny na rynku (`gap_domains`) — wspólny dla projektów (te same publiczne dane dostawcy). Zakres,
 * kompletność i świeżość opisują ostatni udany import.
 *
 * Brak frazy w zbiorze jest wiarygodnym dowodem nieobecności domeny tylko wtedy, gdy import był kompletny albo — przy
 * przycięciu limitem fraz (kolejność wolumenu malejąco) — dla fraz o wolumenie co najmniej `covered_min_volume`.
 */
final class GapDomain
{
	public const EMPTY = 'empty';

	public const IMPORTING = 'importing';

	public const READY = 'ready';

	public const PARTIAL = 'partial';

	public function __construct(
		public readonly int $id,
		public readonly string $provider,
		public readonly int $locationCode,
		public readonly string $languageCode,
		public readonly string $domain,
		public readonly string $status,
		public readonly ?Coverage $coverage,
		public readonly bool $complete,
		/** Dolna granica wolumenu (włącznie), od której nieobecność frazy jest wiarygodna; null — brak wiarygodności. */
		public readonly ?int $coveredMinVolume,
		public readonly ?int $totalCount,
		public readonly int $rowsPresent,
		public readonly ?string $labsUpdatedAt,
		public readonly ?int $importRunId,
		public readonly ?string $importedAt,
		public readonly ?string $staleAfter,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$coverage = $row['coverage_max_rank'] === null || $row['coverage_min_volume'] === null || $row['coverage_max_rows'] === null
			? null
			: new Coverage((int) $row['coverage_max_rank'], (int) $row['coverage_min_volume'], max(1, (int) $row['coverage_max_rows']));

		return new self(
			(int) $row['id'],
			(string) $row['provider'],
			(int) $row['location_code'],
			(string) $row['language_code'],
			(string) $row['domain'],
			(string) $row['status'],
			$coverage,
			(int) $row['complete'] === 1,
			$row['covered_min_volume'] === null ? null : (int) $row['covered_min_volume'],
			$row['total_count'] === null ? null : (int) $row['total_count'],
			(int) $row['rows_present'],
			$row['labs_updated_at'],
			$row['import_run_id'] === null ? null : (int) $row['import_run_id'],
			$row['imported_at'],
			$row['stale_after'],
		);
	}

	public function wasImported(): bool
	{
		return $this->importedAt !== null && $this->coverage !== null;
	}

	public function isFresh(string $now): bool
	{
		return $this->staleAfter !== null && $this->staleAfter > $now;
	}

	public function isImportingBy(int $runId): bool
	{
		return $this->status === self::IMPORTING && $this->importRunId === $runId;
	}

	/** Świeży zbiór o zakresie obejmującym żądany — import nie jest potrzebny (bezpłatnie z pamięci). */
	public function satisfies(Coverage $requested, string $now): bool
	{
		if ($this->status === self::IMPORTING || ! $this->isFresh($now) || $this->coverage === null) {
			return false;
		}

		return $this->coverage->maxRank >= $requested->maxRank
			&& $this->coverage->minVolume <= $requested->minVolume
			&& ($this->complete || $this->coverage->maxRows >= $requested->maxRows);
	}

	/** Czy brak frazy o tej pozycji granicznej i wolumenie oznacza, że domena na nią nie rankuje (w zakresie importu). */
	public function absenceReliable(?int $volume): bool
	{
		return $this->wasImported() && $this->coveredMinVolume !== null && $volume !== null && $volume >= $this->coveredMinVolume;
	}

	public function statusLabel(): string
	{
		return match ($this->status) {
			self::EMPTY => 'brak danych',
			self::IMPORTING => 'import w toku',
			self::READY => $this->complete ? 'kompletny' : 'przycięty limitem',
			self::PARTIAL => 'ostatni import niepełny',
			default => $this->status,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'domain' => $this->domain,
			'status' => $this->status,
			'coverage' => $this->coverage?->toArray(),
			'complete' => $this->complete,
			'covered_min_volume' => $this->coveredMinVolume,
			'total_count' => $this->totalCount,
			'rows_present' => $this->rowsPresent,
			'labs_updated_at' => $this->labsUpdatedAt,
			'imported_at' => $this->importedAt,
			'stale_after' => $this->staleAfter,
		];
	}
}
