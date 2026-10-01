<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Wynik analizy jednego okresu.
 */
final class AnalysisResult
{
	public const SUCCESS = 'success';

	/** Stan danych bez zmian od ostatniej udanej analizy — nic nie przeliczano. */
	public const UNCHANGED = 'unchanged';

	/** Okres niegotowy (brak property, danych albo pełnego okresu) albo property zmieniona w trakcie. */
	public const SKIPPED = 'skipped';

	/** Inny proces analizuje właśnie ten projekt. */
	public const LOCKED = 'locked';

	public const FAILED = 'failed';

	public function __construct(
		public readonly int $days,
		public readonly string $status,
		public readonly int $opportunities = 0,
		public readonly ?string $reason = null,
		public readonly int $inserted = 0,
		public readonly int $deactivated = 0,
		public readonly int $durationMs = 0,
	) {
	}

	/** Polski opis powodu pominięcia (UI). */
	public function reasonLabel(): string
	{
		return match ($this->reason) {
			'property_not_ready' => 'projekt nie ma wybranej property GSC z zaimportowanymi danymi',
			'no_data' => 'brak zaimportowanych danych GSC (frazy i strony)',
			'insufficient_history' => 'okres nie jest jeszcze w pełni zaimportowany (trwa pobieranie historii)',
			'property_changed', 'project_missing' => 'property projektu zmieniła się w trakcie analizy',
			'internal_error' => 'błąd analizy (szczegóły w logu)',
			default => (string) $this->reason,
		};
	}

	/**
	 * @return array<string, int|string|null>
	 */
	public function toArray(): array
	{
		return [
			'days' => $this->days,
			'status' => $this->status,
			'opportunities' => $this->opportunities,
			'inserted' => $this->inserted,
			'deactivated' => $this->deactivated,
			'reason' => $this->reason,
			'duration_ms' => $this->durationMs,
		];
	}
}
