<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Sygnał członkostwa: źródło uznaje frazę za kandydata Strategii. Tożsamość frazy = klucz rynkowy (hex MD5 postaci
 * znormalizowanej) na rynku projektu; `marketKeywordId` = null tylko dla frazy GSC, która nie ma jeszcze wiersza
 * `market_keywords` (tworzonego przy zapisie, nie w podglądzie).
 */
final class SourceSignal
{
	/** Poziomy źródeł — kolejność wyboru kandydatów przy limicie (niższy = ważniejszy). */
	public const TIER_MANUAL = 0;

	public const TIER_SERP = 1;

	/** Decyzja użytkownika albo analiza modułu: szansa SEO, zaakceptowana Nowa fraza. */
	public const TIER_DECISION = 2;

	public const TIER_GAP = 3;

	public const TIER_CONTENT_GAP = 4;

	public const TIER_DISCOVERY = 5;

	public const TIER_GSC = 6;

	public function __construct(
		public readonly string $keyHex,
		public readonly ?int $marketKeywordId,
		/** Postać znormalizowana frazy (filtry marki i wykluczeń, nowy wiersz rynkowy). */
		public readonly string $keyword,
		public readonly ?string $intent,
		public readonly StrategySource $source,
		public readonly int $tier,
		/** Waga w obrębie poziomu (priorytet modułu, wyświetlenia) — większa = ważniejsza. */
		public readonly float $weight,
	) {
	}
}
