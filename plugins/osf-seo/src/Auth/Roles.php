<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

final class Roles
{
	/** Pracownik agencji: pełny dostęp do aplikacji, bez uprawnień administracyjnych WordPressa. */
	public const ADMIN = 'osf_seo_admin';

	/** Klient: widzi wyłącznie przypisane projekty (tylko odczyt). */
	public const CLIENT = 'osf_seo_client';

	/** Rola WordPressa, która dostaje wszystkie uprawnienia aplikacji obok własnych. */
	public const WP_ADMINISTRATOR = 'administrator';

	/**
	 * Definicje ról pluginu — źródło prawdy synchronizowane przez RoleManager.
	 *
	 * @return array<string, array{label: string, capabilities: list<string>}>
	 */
	public static function definitions(): array
	{
		return [
			self::ADMIN => [
				'label' => 'OSF SEO — Administrator',
				'capabilities' => ['read', ...Capabilities::all()],
			],
			self::CLIENT => [
				'label' => 'OSF SEO — Klient',
				'capabilities' => ['read', Capabilities::ACCESS],
			],
		];
	}
}
