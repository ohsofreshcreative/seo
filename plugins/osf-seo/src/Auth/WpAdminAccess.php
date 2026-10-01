<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

/**
 * Panel OSF SEO jest jedynym interfejsem dla użytkowników aplikacji:
 * użytkownicy z `osf_seo_access`, ale bez `manage_options` (klienci, osf_seo_admin),
 * nie wchodzą do wp-admin i nie widzą paska administracyjnego; po logowaniu przez
 * wp-login.php trafiają do panelu. Administratorzy WordPressa — bez zmian.
 */
final class WpAdminAccess
{
	public static function register(): void
	{
		add_action('admin_init', [self::class, 'redirectFromAdmin']);
		add_filter('show_admin_bar', [self::class, 'filterAdminBar']);
		add_filter('login_redirect', [self::class, 'filterLoginRedirect'], 10, 3);
	}

	public static function isPanelOnlyUser(int $userId): bool
	{
		return $userId > 0 && user_can($userId, Capabilities::ACCESS) && ! user_can($userId, 'manage_options');
	}

	public static function redirectFromAdmin(): void
	{
		if (wp_doing_ajax() || (defined('WP_CLI') && WP_CLI) || ! self::isPanelOnlyUser(get_current_user_id())) {
			return;
		}

		// admin-post.php pozostaje dostępny (obsługa formularzy przez hooki WordPressa).
		if (str_ends_with((string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH), '/admin-post.php')) {
			return;
		}

		wp_safe_redirect(home_url('/'));
		exit;
	}

	public static function filterAdminBar(bool $show): bool
	{
		return self::isPanelOnlyUser(get_current_user_id()) ? false : $show;
	}

	/**
	 * @param \WP_User|\WP_Error $user
	 */
	public static function filterLoginRedirect(string $redirectTo, string $requested, $user): string
	{
		if ($user instanceof \WP_User && self::isPanelOnlyUser($user->ID)) {
			return home_url('/');
		}

		return $redirectTo;
	}
}
