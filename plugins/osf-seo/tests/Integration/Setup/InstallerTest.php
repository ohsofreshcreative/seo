<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Setup;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\RoleManager;
use OsfSeo\Auth\Roles;
use OsfSeo\Auth\WpRoleStore;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;
use OsfSeo\Database\Migrator;
use OsfSeo\Database\SchemaInspector;
use OsfSeo\Plugin;
use OsfSeo\Setup\Installer;
use OsfSeo\Tests\Integration\IntegrationTestCase;

final class InstallerTest extends IntegrationTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		self::dropPluginTables();
		delete_option(Installer::OPTION_VERSION);
		delete_transient('osf_seo_upgrade_backoff');
	}

	protected function tearDown(): void
	{
		delete_transient('osf_seo_upgrade_backoff');
		self::freshTables();
		parent::tearDown();
	}

	/**
	 * @param list<Migration>|null $migrations
	 */
	private function installer(?array $migrations = null): Installer
	{
		$logger = $this->captureLogger();

		return new Installer(new RoleManager(new WpRoleStore()), new Migrator(self::db(), $logger, $migrations), $logger);
	}

	public function test_boot_upgrade_installs_schema_roles_and_version(): void
	{
		$installer = $this->installer();

		self::assertTrue($installer->needsUpgrade());

		$installer->maybeUpgrade();

		self::assertFalse($installer->needsUpgrade());
		self::assertSame(Plugin::VERSION, $installer->installedVersion());
		self::assertSame([], (new SchemaInspector(self::db()))->problems());
		self::assertNotNull(get_role(Roles::CLIENT));
		self::assertTrue(get_role('administrator')->has_cap(Capabilities::MANAGE_PROJECTS));
	}

	public function test_pending_schema_triggers_upgrade_even_when_plugin_version_is_current(): void
	{
		update_option(Installer::OPTION_VERSION, Plugin::VERSION);
		$installer = $this->installer();

		self::assertTrue($installer->needsUpgrade(), 'Nowa migracja bez podbicia wersji pluginu też musi się wykonać.');

		$installer->maybeUpgrade();

		self::assertSame(1, (int) get_option(Migrator::OPTION_VERSION));
	}

	public function test_failed_boot_upgrade_is_logged_backed_off_and_does_not_throw(): void
	{
		$broken = new class implements Migration {
			public function version(): int
			{
				return 1;
			}

			public function name(): string
			{
				return 'broken';
			}

			public function up(Connection $db): void
			{
				$db->execute('ALTER TABLE `table_that_does_not_exist_osf` ADD COLUMN x INT');
			}
		};

		$installer = $this->installer([$broken]);

		$installer->maybeUpgrade();
		$installer->maybeUpgrade();

		$errors = array_values(array_filter($this->logLines, static fn (string $line): bool => str_contains($line, 'ERROR: Automatic OSF SEO upgrade failed')));

		self::assertCount(1, $errors, 'Druga próba wstrzymana przez backoff — jeden wpis w logu.');
		self::assertNotFalse(get_transient('osf_seo_upgrade_backoff'));
		self::assertNull($installer->installedVersion(), 'Wersja pluginu nie może zostać zapisana po nieudanej migracji.');
	}
}
