<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Decision;

/**
 * Priorytet Strategii: wynik 0–100, surowa suma składników, mnożnik pewności, współczynnik działania i rozbicie składników.
 */
final class PriorityResult
{
	/**
	 * @param array<string, array<string, mixed>> $components
	 */
	public function __construct(
		public readonly int $value,
		public readonly float $raw,
		public readonly float $multiplier,
		public readonly float $actionFactor,
		public readonly array $components,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'value' => $this->value,
			'band' => PriorityModel::band($this->value),
			'raw' => $this->raw,
			'confidence_multiplier' => $this->multiplier,
			'action_factor' => $this->actionFactor,
			'components' => $this->components,
		];
	}
}
