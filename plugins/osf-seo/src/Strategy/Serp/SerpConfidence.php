<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

/**
 * Poziomy pewności interpretacji SERP (kształt wyniku, kształt strony wyników, sygnał intencji) — z obniżeniem dla
 * nieaktualnego pomiaru (31–90 dni).
 */
final class SerpConfidence
{
	public const HIGH = 'high';

	public const MEDIUM = 'medium';

	public const LOW = 'low';

	private const ORDER = [self::LOW => 0, self::MEDIUM => 1, self::HIGH => 2];

	/** Wartość liczbowa do uśredniania (wysoka 1,0, średnia 0,6, niska 0,3). */
	public static function weight(string $level): float
	{
		return match ($level) {
			self::HIGH => 1.0,
			self::MEDIUM => 0.6,
			default => 0.3,
		};
	}

	public static function lower(string $level): string
	{
		return match ($level) {
			self::HIGH => self::MEDIUM,
			default => self::LOW,
		};
	}

	/** Niższy z dwóch poziomów. */
	public static function min(string $a, string $b): string
	{
		return (self::ORDER[$a] ?? 0) <= (self::ORDER[$b] ?? 0) ? $a : $b;
	}

	/** Pewność z uwzględnieniem świeżości pomiaru (nieaktualny — o poziom niżej; wygasły — brak interpretacji). */
	public static function forFreshness(string $level, ?string $freshness): ?string
	{
		return match ($freshness) {
			SerpFreshness::FRESH => $level,
			SerpFreshness::STALE => self::lower($level),
			default => null,
		};
	}

	public static function label(?string $level): string
	{
		return match ($level) {
			self::HIGH => 'wysoka',
			self::MEDIUM => 'średnia',
			self::LOW => 'niska',
			default => '—',
		};
	}
}
