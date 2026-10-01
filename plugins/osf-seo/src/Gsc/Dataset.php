<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

/**
 * Zestawy danych importowane z Search Analytics. Wartość = `sync_state.dataset` / `sync_runs.dataset`.
 *
 * - site: `[date]` — prawdziwe sumy property (zawierają zapytania zanonimizowane), `gsc_site_daily` z device = 0,
 * - query: `[date, query]` — `gsc_query_daily`,
 * - query_page: `[date, query, page]` — `gsc_query_page_daily` (agregacja Google per strona).
 */
enum Dataset: string
{
	case Site = 'site';
	case Query = 'query';
	case QueryPage = 'query_page';

	/** `gsc_site_daily.device` dla sum ze wszystkich urządzeń (wymiar device w MVP nieużywany). */
	public const DEVICE_ALL = 0;

	/**
	 * @return list<string>
	 */
	public function dimensions(): array
	{
		return match ($this) {
			self::Site => ['date'],
			self::Query => ['date', 'query'],
			self::QueryPage => ['date', 'query', 'page'],
		};
	}

	public function factTable(): string
	{
		return match ($this) {
			self::Site => 'gsc_site_daily',
			self::Query => 'gsc_query_daily',
			self::QueryPage => 'gsc_query_page_daily',
		};
	}

	public function hasKeywords(): bool
	{
		return $this !== self::Site;
	}

	public function hasPages(): bool
	{
		return $this === self::QueryPage;
	}

	public function label(): string
	{
		return match ($this) {
			self::Site => 'Sumy witryny',
			self::Query => 'Frazy',
			self::QueryPage => 'Frazy i strony',
		};
	}
}
