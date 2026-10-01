<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit;

use OsfSeo\Auth\RoleManager;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migrator;
use OsfSeo\Database\SchemaInspector;
use OsfSeo\Plugin;
use OsfSeo\Setup\Installer;
use OsfSeo\Support\Config;
use OsfSeo\Support\Logger;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
	private const MAIN_FILE = __DIR__ . '/../../osf-seo.php';

	/**
	 * Odczyt nagłówka pluginu tak, jak robi to WordPress (get_file_data): `Nazwa: wartość`.
	 */
	private static function header(string $name): ?string
	{
		$contents = (string) file_get_contents(self::MAIN_FILE);

		if (preg_match('/^[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $contents, $match) !== 1) {
			return null;
		}

		return trim($match[1]);
	}

	public function test_header_version_matches_plugin_constant(): void
	{
		self::assertSame(Plugin::VERSION, self::header('Version'));
	}

	public function test_header_requirements_match_plugin_constants(): void
	{
		self::assertSame(Plugin::MIN_PHP, self::header('Requires PHP'));
		self::assertSame(Plugin::MIN_WP, self::header('Requires at least'));
	}

	public function test_php_guard_in_main_file_matches_minimum_version(): void
	{
		$contents = (string) file_get_contents(self::MAIN_FILE);

		self::assertMatchesRegularExpression(
			'/version_compare\(PHP_VERSION, \'' . preg_quote(Plugin::MIN_PHP, '/') . '\', \'<\'\)/',
			$contents,
		);
	}

	public function test_header_disables_updates_from_wordpress_org(): void
	{
		// Chroni przed nadpisaniem pluginu przez ewentualny plugin o tym samym slugu z WordPress.org.
		self::assertSame('false', self::header('Update URI'));
		self::assertSame('osf-seo', self::header('Text Domain'));
	}

	public function test_container_resolves_core_services_as_singletons(): void
	{
		$container = Plugin::createContainer();

		// Usługi niezależne od bazy; pełny graf (z $wpdb) sprawdzają testy integracyjne.
		foreach ([Config::class, Logger::class, RoleManager::class] as $service) {
			$instance = $container->get($service);

			self::assertInstanceOf($service, $instance);
			self::assertSame($instance, $container->get($service));
		}
	}

	public function test_container_registers_database_services_lazily(): void
	{
		$container = Plugin::createContainer();

		foreach ([Connection::class, Migrator::class, SchemaInspector::class, Installer::class] as $service) {
			self::assertTrue($container->has($service), $service);
		}
	}
}
