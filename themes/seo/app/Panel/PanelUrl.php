<?php

namespace App\Panel;

/**
 * Adresy panelu — zawsze względem home_url(), bez twardo wpisanych domen.
 */
final class PanelUrl
{
	public static function to(string $path = ''): string
	{
		return home_url('/' . ltrim($path, '/'));
	}

	public static function project(string $publicId, string $section = ''): string
	{
		return self::to('projects/' . rawurlencode($publicId) . ($section !== '' ? '/' . $section : ''));
	}

	/**
	 * Strona logowania z bezpiecznym adresem powrotu (tylko ścieżki tej aplikacji).
	 */
	public static function login(?string $returnTo = null): string
	{
		$returnTo = self::safeReturnPath($returnTo);

		return $returnTo === null ? self::to('login') : add_query_arg('redirect_to', rawurlencode($returnTo), self::to('login'));
	}

	/**
	 * Adres powrotu po zalogowaniu: tylko lokalna ścieżka (bez hosta i schematu),
	 * inaczej strona główna panelu. Chroni przed open redirect.
	 */
	public static function safeReturnPath(?string $path): ?string
	{
		if ($path === null || $path === '' || $path === '/') {
			return null;
		}

		if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path) === 1) {
			return null;
		}

		return $path;
	}

	public static function afterLogin(?string $returnPath): string
	{
		$path = self::safeReturnPath($returnPath);

		// Ścieżka pochodzi z REQUEST_URI (zawiera ewentualny podkatalog instalacji) — doklejamy ją do originu, nie do home_url().
		return $path === null ? self::to('/') : wp_validate_redirect(self::origin(home_url('/')) . $path, self::to('/'));
	}

	/**
	 * `scheme://host[:port]` — do porównań Origin/Referer.
	 */
	public static function origin(string $url): string
	{
		$parts = wp_parse_url($url);

		if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
			return '';
		}

		return strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
	}
}
