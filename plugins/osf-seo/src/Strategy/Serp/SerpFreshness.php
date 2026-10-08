<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Polityka świeżości pomiaru SERP w Strategii (docs/ARCHITECTURE.md, sekcja 15.12, D55):
 *
 * - **świeży** (≤ 30 dni) — pełne użycie: kształt i kompozycja, Pozycja SERP projektu, silny overlap,
 * - **nieaktualny** (31–90 dni) — kształt i kompozycja z obniżoną pewnością, **bez Pozycji SERP projektu**, overlap najwyżej umiarkowany,
 * - **wygasły** (> 90 dni) — nie do klasyfikacji ani overlapu (fraza kwalifikuje się do nowego pomiaru).
 */
final class SerpFreshness
{
	public const FRESH = 'fresh';

	public const STALE = 'stale';

	public const EXPIRED = 'expired';

	public const FRESH_DAYS = 30;

	public const STALE_DAYS = 90;

	/** Stan pomiaru z chwilą `checked_at` (UTC) albo null — nigdy niesprawdzony. */
	public static function of(?string $checkedAt, DateTimeImmutable $now): ?string
	{
		if ($checkedAt === null || $checkedAt === '') {
			return null;
		}

		$age = $now->getTimestamp() - (new DateTimeImmutable($checkedAt, new DateTimeZone('UTC')))->getTimestamp();

		return match (true) {
			$age <= self::FRESH_DAYS * 86400 => self::FRESH,
			$age <= self::STALE_DAYS * 86400 => self::STALE,
			default => self::EXPIRED,
		};
	}

	/** Najstarszy pomiar danej klasy (UTC) — do zapytań. */
	public static function since(DateTimeImmutable $now, int $days): string
	{
		return $now->setTimezone(new DateTimeZone('UTC'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
	}

	public static function usableForClassification(?string $freshness): bool
	{
		return $freshness === self::FRESH || $freshness === self::STALE;
	}

	public static function allowsProjectRank(?string $freshness): bool
	{
		return $freshness === self::FRESH;
	}

	public static function label(?string $freshness): string
	{
		return match ($freshness) {
			self::FRESH => 'aktualny (≤ 30 dni)',
			self::STALE => 'nieaktualny (31–90 dni)',
			self::EXPIRED => 'wygasły (> 90 dni)',
			default => 'brak pomiaru',
		};
	}
}
