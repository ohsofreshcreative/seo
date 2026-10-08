<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

/**
 * Uprawnienia aplikacji. Kod zawsze sprawdza capability (`current_user_can()`), nigdy nazwę roli.
 */
final class Capabilities
{
	/** Wejście do panelu OSF SEO. */
	public const ACCESS = 'osf_seo_access';

	/** Widzi wszystkie projekty, także nieprzypisane do użytkownika. */
	public const VIEW_ALL_PROJECTS = 'osf_seo_view_all_projects';

	/** Tworzy, edytuje i archiwizuje projekty. */
	public const MANAGE_PROJECTS = 'osf_seo_manage_projects';

	/** Łączy konta Google i properties Search Console, uruchamia synchronizację. */
	public const MANAGE_CONNECTIONS = 'osf_seo_manage_connections';

	/** Zarządza klientami i ich przypisaniem do projektów. */
	public const MANAGE_USERS = 'osf_seo_manage_users';

	/** Zmienia ustawienia aplikacji. */
	public const MANAGE_SETTINGS = 'osf_seo_manage_settings';

	/** Prowadzi szanse SEO: status, notatki, data wdrożenia, ręczne przeliczanie. */
	public const MANAGE_OPPORTUNITIES = 'osf_seo_manage_opportunities';

	/** Uruchamia płatną synchronizację danych rynkowych fraz (DataForSEO) i widzi jej koszty. */
	public const MANAGE_MARKET_DATA = 'osf_seo_manage_market_data';

	/** Wyszukiwanie nowych fraz: płatne przebiegi (także wymuszone odświeżenie), koszty, status i notatki fraz, wykluczenia. */
	public const MANAGE_KEYWORD_DISCOVERY = 'osf_seo_manage_keyword_discovery';

	/** Pozycje SERP i konkurenci: konkurenci, monitorowane frazy, ustawienia śledzenia, płatne pomiary, koszty. */
	public const MANAGE_SERP_TRACKING = 'osf_seo_manage_serp_tracking';

	/** Luki SEO: płatny import fraz konkurencji (także wymuszony), koszty, ustawienia, warianty marki, status i notatki luk. */
	public const MANAGE_KEYWORD_GAP = 'osf_seo_manage_keyword_gap';

	/** Strategia (backlog SEO): przeliczenie, wpisy ręczne, praca nad tematami; płatna analiza SERP dodatkowo z MANAGE_SERP_TRACKING. */
	public const MANAGE_STRATEGY = 'osf_seo_manage_strategy';

	/**
	 * Analizy AI (STEP 17): podgląd kontekstu i planu, uruchomienia (także płatne, gdy dostawca jest świadomie skonfigurowany), historia
	 * i jej usuwanie. Osobne od Strategii — wyłącznie administratorzy; dostęp klientów to osobna decyzja produktowa (D86).
	 */
	public const MANAGE_AI = 'osf_seo_manage_ai';

	/**
	 * @return list<string>
	 */
	public static function all(): array
	{
		return [
			self::ACCESS,
			self::VIEW_ALL_PROJECTS,
			self::MANAGE_PROJECTS,
			self::MANAGE_CONNECTIONS,
			self::MANAGE_USERS,
			self::MANAGE_SETTINGS,
			self::MANAGE_OPPORTUNITIES,
			self::MANAGE_MARKET_DATA,
			self::MANAGE_KEYWORD_DISCOVERY,
			self::MANAGE_SERP_TRACKING,
			self::MANAGE_KEYWORD_GAP,
			self::MANAGE_STRATEGY,
			self::MANAGE_AI,
		];
	}
}
