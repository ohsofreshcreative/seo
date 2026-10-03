<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Decision;

/**
 * Pewność tematu: punkty, poziom, czynniki (kod + punkty) i zastosowane limity.
 */
final class ConfidenceResult
{
	/**
	 * @param list<array{code: string, points: int}> $factors
	 * @param list<string> $caps
	 */
	public function __construct(
		public readonly int $points,
		public readonly string $level,
		public readonly array $factors,
		public readonly array $caps,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'points' => $this->points,
			'level' => $this->level,
			'positive' => array_values(array_filter($this->factors, static fn (array $factor): bool => $factor['points'] > 0)),
			'negative' => array_values(array_filter($this->factors, static fn (array $factor): bool => $factor['points'] < 0)),
			'neutral' => array_values(array_filter($this->factors, static fn (array $factor): bool => $factor['points'] === 0)),
			'caps' => $this->caps,
		];
	}

	public static function levelLabel(?string $level): string
	{
		return match ($level) {
			'high' => 'Wysoka',
			'medium' => 'Średnia',
			'low' => 'Niska',
			default => '—',
		};
	}
}
