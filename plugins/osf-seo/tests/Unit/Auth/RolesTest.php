<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Auth;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\Roles;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
	public function test_capabilities_are_prefixed_and_unique(): void
	{
		$capabilities = Capabilities::all();

		self::assertNotEmpty($capabilities);
		self::assertSame($capabilities, array_values(array_unique($capabilities)));

		foreach ($capabilities as $capability) {
			self::assertStringStartsWith('osf_seo_', $capability);
		}
	}

	public function test_role_slugs_are_prefixed(): void
	{
		foreach (array_keys(Roles::definitions()) as $role) {
			self::assertStringStartsWith('osf_seo_', $role);
		}
	}

	public function test_admin_role_has_all_application_capabilities(): void
	{
		$capabilities = Roles::definitions()[Roles::ADMIN]['capabilities'];

		self::assertEqualsCanonicalizing(['read', ...Capabilities::all()], $capabilities);
	}

	public function test_client_role_has_only_panel_access(): void
	{
		$capabilities = Roles::definitions()[Roles::CLIENT]['capabilities'];

		self::assertEqualsCanonicalizing(['read', Capabilities::ACCESS], $capabilities);
	}

	public function test_plugin_roles_never_receive_wordpress_administrative_capabilities(): void
	{
		// Least privilege: role pluginu dostają wyłącznie `read` i uprawnienia aplikacji.
		$allowed = ['read', ...Capabilities::all()];

		foreach (Roles::definitions() as $role => $definition) {
			self::assertSame([], array_values(array_diff($definition['capabilities'], $allowed)), $role);
		}
	}
}
