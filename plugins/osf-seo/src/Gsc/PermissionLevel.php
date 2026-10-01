<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

/**
 * Poziom uprawnień konta do property (`permissionLevel` z sites.list).
 * Dane Search Analytics są dostępne dla właściciela, pełnego i ograniczonego użytkownika;
 * `siteUnverifiedUser` (property dodana, ale niezweryfikowana) danych nie udostępnia.
 */
enum PermissionLevel: string
{
	case Owner = 'siteOwner';
	case FullUser = 'siteFullUser';
	case RestrictedUser = 'siteRestrictedUser';
	case UnverifiedUser = 'siteUnverifiedUser';

	public function allowsSearchAnalytics(): bool
	{
		return $this !== self::UnverifiedUser;
	}

	public function label(): string
	{
		return match ($this) {
			self::Owner => 'Właściciel',
			self::FullUser => 'Pełny dostęp',
			self::RestrictedUser => 'Ograniczony dostęp',
			self::UnverifiedUser => 'Niezweryfikowana (brak dostępu do danych)',
		};
	}

	/** Etykieta dla wartości zapisanej w bazie (także nieznanej, np. nowej wartości z API). */
	public static function labelFor(?string $value): string
	{
		if ($value === null || $value === '') {
			return '—';
		}

		return self::tryFrom($value)?->label() ?? $value;
	}
}
