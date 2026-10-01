<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Auth;

use OsfSeo\Auth\RoleManager;
use OsfSeo\Auth\WpAdminAccess;
use OsfSeo\Auth\WpRoleStore;
use OsfSeo\Tests\Integration\IntegrationTestCase;

/**
 * Plugin nie jest bootowany w bootstrapie testów (hook plugins_loaded już minął) —
 * hooki rejestrujemy jawnie; rejestrację przy boot() sprawdza test E2E na harnessie.
 */
final class WpAdminAccessTest extends IntegrationTestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		// Role pluginu muszą istnieć niezależnie od kolejności testów (świeża baza w CI).
		(new RoleManager(new WpRoleStore()))->sync();
		WpAdminAccess::register();
	}

	protected function tearDown(): void
	{
		remove_action('admin_init', [WpAdminAccess::class, 'redirectFromAdmin']);
		remove_filter('show_admin_bar', [WpAdminAccess::class, 'filterAdminBar']);
		remove_filter('login_redirect', [WpAdminAccess::class, 'filterLoginRedirect']);

		parent::tearDown();
	}

	public function test_register_adds_the_hooks(): void
	{
		self::assertNotFalse(has_action('admin_init', [WpAdminAccess::class, 'redirectFromAdmin']));
		self::assertNotFalse(has_filter('show_admin_bar', [WpAdminAccess::class, 'filterAdminBar']));
		self::assertNotFalse(has_filter('login_redirect', [WpAdminAccess::class, 'filterLoginRedirect']));
	}

	public function test_panel_only_users_are_app_users_without_manage_options(): void
	{
		self::assertTrue(WpAdminAccess::isPanelOnlyUser($this->createUser('osf_seo_client')));
		self::assertTrue(WpAdminAccess::isPanelOnlyUser($this->createUser('osf_seo_admin')));
		self::assertFalse(WpAdminAccess::isPanelOnlyUser($this->createUser('administrator')));
		self::assertFalse(WpAdminAccess::isPanelOnlyUser($this->createUser('subscriber')));
		self::assertFalse(WpAdminAccess::isPanelOnlyUser(0));
	}

	public function test_admin_bar_is_hidden_only_for_panel_users(): void
	{
		wp_set_current_user($this->createUser('osf_seo_client'));
		self::assertFalse(apply_filters('show_admin_bar', true));

		wp_set_current_user($this->createUser('administrator'));
		self::assertTrue(apply_filters('show_admin_bar', true));
	}

	public function test_login_redirect_sends_panel_users_to_the_panel(): void
	{
		$client = get_userdata($this->createUser('osf_seo_client'));
		$admin = get_userdata($this->createUser('administrator'));
		$requested = admin_url('options-general.php');

		self::assertSame(home_url('/'), apply_filters('login_redirect', $requested, $requested, $client));
		self::assertSame($requested, apply_filters('login_redirect', $requested, $requested, $admin));
		self::assertSame($requested, apply_filters('login_redirect', $requested, $requested, new \WP_Error('x')));
	}
}
