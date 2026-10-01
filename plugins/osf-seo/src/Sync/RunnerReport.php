<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

/** Wynik jednego uruchomienia kolejki. */
final class RunnerReport
{
	/** @var array<string, int> status → liczba zadań */
	public array $outcomes = ['success' => 0, 'retrying' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0];

	public int $recovered = 0;

	public float $seconds = 0.0;

	public function __construct(
		/** Inny proces trzymał blokadę kolejki — nic nie wykonano. */
		public readonly bool $locked = false,
	) {
	}

	public function processed(): int
	{
		return array_sum($this->outcomes);
	}

	public function record(RunStatus $status): void
	{
		$this->outcomes[$status->value] = ($this->outcomes[$status->value] ?? 0) + 1;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return ['locked' => $this->locked, 'processed' => $this->processed(), 'outcomes' => $this->outcomes, 'recovered' => $this->recovered, 'seconds' => round($this->seconds, 2)];
	}
}
