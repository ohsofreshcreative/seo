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
		];
	}
}
