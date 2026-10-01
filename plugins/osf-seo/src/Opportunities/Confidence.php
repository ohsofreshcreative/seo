<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Pewność sygnału — osobno od priorytetu. Wynika z punktów (0–4): wielkość próby (0–2),
 * pełny poprzedni okres do porównania (0–1), spójność sygnału (0–1). To prosta reguła, nie test statystyczny.
 */
enum Confidence: int
{
	case Low = 1;
	case Medium = 2;
	case High = 3;

	public const MAX_POINTS = 4;

	public static function fromPoints(int $points): self
	{
		return match (true) {
			$points >= 4 => self::High,
			$points >= 2 => self::Medium,
			default => self::Low,
		};
	}

	public function label(): string
	{
		return match ($this) {
			self::Low => 'Niska',
			self::Medium => 'Średnia',
			self::High => 'Wysoka',
		};
	}

	public static function tryFromValue(mixed $value): ?self
	{
		return is_numeric($value) ? self::tryFrom((int) $value) : null;
	}
}
