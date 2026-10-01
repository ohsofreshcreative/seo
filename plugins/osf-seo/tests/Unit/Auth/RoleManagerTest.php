<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Auth;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\RoleManager;
use OsfSeo\Auth\Roles;
use OsfSeo\Tests\Support\InMemoryRoleStore;
use PHPUnit\Framework\TestCase;

final class RoleManagerTest extends TestCase
{
	/** Stan świeżej instalacji WordPressa (fragment uprawnień administratora). */
	private static function freshWordPress(): InMemoryRoleStore
	{
		return new InMemoryRoleStore([
			'administrator' => ['read' => true, 'manage_options' => true, 'edit_posts' => true],
			'subscriber' => ['read' => true],
		]);
	}

	public function test_sync_creates_plugin_roles_and_grants_capabilities_to_administrator(): void
	{
		$store = self::freshWordPress();

		$changes = (new RoleManager($store))->sync();

		self::assertEqualsCanonicalizing([Roles::ADMIN, Roles::CLIENT], $changes['created']);
		self::assertSame('OSF SEO — Klient', $store->roles[Roles::CLIENT]['label']);
		self::assertEqualsCanonicalizing(
			['read', Capabilities::ACCESS],
			array_keys($store->roles[Roles::CLIENT]['capabilities']),
		);

		foreach (Capabilities::all() as $capability) {
			self::assertTrue($store->roles['administrator']['capabilities'][$capability]);
		}
	}

	public function test_sync_keeps_other_administrator_capabilities_and_other_roles(): void
	{
		$store = self::freshWordPress();

		(new RoleManager($store))->sync();

		self::assertTrue($store->roles['administrator']['capabilities']['manage_options']);
		self::assertTrue($store->roles['administrator']['capabilities']['edit_posts']);
		self::assertSame(['read' => true], $store->roles['subscriber']['capabilities']);
	}

	public function test_sync_is_idempotent(): void
	{
		$store = self::freshWordPress();
		$manager = new RoleManager($store);

		$manager->sync();
		$snapshot = $store->roles;
		$changes = $manager->sync();

		self::assertSame(['created' => [], 'granted' => [], 'revoked' => []], $changes);
		self::assertSame($snapshot, $store->roles);
	}

	public function test_sync_enforces_exact_capabilities_of_plugin_roles(): void
	{
		$store = self::freshWordPress();
		$store->roles[Roles::CLIENT] = [
			'label' => 'OSF SEO — Klient',
			'capabilities' => ['read' => true, 'edit_posts' => true, Capabilities::ACCESS => false],
		];

		$changes = (new RoleManager($store))->sync();

		self::assertSame(['read' => true, Capabilities::ACCESS => true], $store->roles[Roles::CLIENT]['capabilities']);
		self::assertContains(Roles::CLIENT . ':edit_posts', $changes['revoked']);
		self::assertContains(Roles::CLIENT . ':' . Capabilities::ACCESS, $changes['granted']);
	}

	public function test_sync_works_without_administrator_role(): void
	{
		$store = new InMemoryRoleStore();

		(new RoleManager($store))->sync();

		self::assertArrayNotHasKey('administrator', $store->roles);
		self::assertArrayHasKey(Roles::ADMIN, $store->roles);
	}

	public function test_problems_describe_every_discrepancy(): void
	{
		$store = self::freshWordPress();
		$store->roles[Roles::CLIENT] = ['label' => 'x', 'capabilities' => ['read' => true, 'upload_files' => true]];

		$problems = (new RoleManager($store))->problems();

		self::assertContains('missing role ' . Roles::ADMIN, $problems);
		self::assertContains('role ' . Roles::CLIENT . ' lacks ' . Capabilities::ACCESS, $problems);
		self::assertContains('role ' . Roles::CLIENT . ' has unexpected upload_files', $problems);
		self::assertContains('role administrator lacks ' . Capabilities::MANAGE_PROJECTS, $problems);
	}

	public function test_problems_are_empty_after_sync(): void
	{
		$store = self::freshWordPress();
		$manager = new RoleManager($store);

		$manager->sync();

		self::assertSame([], $manager->problems());
	}
}
