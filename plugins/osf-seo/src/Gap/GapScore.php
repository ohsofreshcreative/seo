<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Priorytet luki 0–100 z rozbiciem na składniki (do wyjaśnienia w szczegółach frazy).
 */
final class GapScore
{
	/**
	 * @param array<string, float> $components
	 */
	public function __construct(
		public readonly int $priority,
		public readonly array $components,
		public readonly float $multiplier,
	) {
	}

	/**
	 * @return array{priority: int, components: array<string, float>, multiplier: float}
	 */
	public function toArray(): array
	{
		return ['priority' => $this->priority, 'components' => $this->components, 'multiplier' => $this->multiplier];
	}
}
