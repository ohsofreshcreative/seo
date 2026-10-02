<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Ustawienia śledzenia pozycji projektu. Domyślnie wyłączone — płatne pomiary włącza się jawnie.
 * Lokalizacja i język pochodzą z rynku projektu; tu: częstotliwość, urządzenie i głębokość.
 */
final class SerpSettings
{
	public function __construct(
		public readonly int $projectId,
		public readonly bool $enabled = false,
		public readonly SerpFrequency $frequency = SerpFrequency::Weekly,
		public readonly SerpDevice $device = SerpDevice::Desktop,
		public readonly int $depth = SerpConfig::DEFAULT_DEPTH,
		public readonly ?string $enabledAt = null,
		public readonly ?int $enabledBy = null,
		public readonly ?string $nextRunAt = null,
		public readonly ?string $retryAfter = null,
		public readonly ?string $lastRunAt = null,
		public readonly ?string $lastSkipReason = null,
		public readonly ?string $lastSkipAt = null,
		public readonly bool $stored = false,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			(int) $row['project_id'],
			(int) $row['enabled'] === 1,
			SerpFrequency::tryFrom((string) $row['frequency']) ?? SerpFrequency::Weekly,
			SerpDevice::tryFrom((string) $row['device']) ?? SerpDevice::Desktop,
			SerpConfig::validDepth((int) $row['depth']) ? (int) $row['depth'] : SerpConfig::DEFAULT_DEPTH,
			$row['enabled_at'],
			$row['enabled_by'] === null ? null : (int) $row['enabled_by'],
			$row['next_run_at'],
			$row['retry_after'],
			$row['last_run_at'],
			$row['last_skip_reason'],
			$row['last_skip_at'],
			true,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'enabled' => $this->enabled,
			'frequency' => $this->frequency->value,
			'frequency_label' => $this->frequency->label(),
			'device' => $this->device->value,
			'depth' => $this->depth,
			'enabled_at' => $this->enabledAt,
			'next_run_at' => $this->nextRunAt,
			'retry_after' => $this->retryAfter,
			'last_run_at' => $this->lastRunAt,
			'last_skip_reason' => $this->lastSkipReason,
			'last_skip_at' => $this->lastSkipAt,
		];
	}
}
