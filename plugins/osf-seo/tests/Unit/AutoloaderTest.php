<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit;

use OsfSeo\Autoloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase
{
	public function test_maps_namespaced_class_to_file(): void
	{
		self::assertSame(
			'/plugin/src/Auth/RoleManager.php',
			Autoloader::pathFor('OsfSeo\\Auth\\RoleManager', 'OsfSeo\\', '/plugin/src/'),
		);
	}

	public function test_ignores_classes_from_other_namespaces(): void
	{
		self::assertNull(Autoloader::pathFor('App\\Blocks\\Hero', 'OsfSeo\\', '/plugin/src'));
		self::assertNull(Autoloader::pathFor('OsfSeoExtra\\Thing', 'OsfSeo\\', '/plugin/src'));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidClassNames(): iterable
	{
		yield 'path traversal' => ['OsfSeo\\..\\..\\wp-config'];
		yield 'slash' => ['OsfSeo\\Auth/../../x'];
		yield 'empty remainder' => ['OsfSeo\\'];
		yield 'leading digit' => ['OsfSeo\\1Class'];
		yield 'null byte' => ["OsfSeo\\Class\0"];
	}

	#[DataProvider('invalidClassNames')]
	public function test_rejects_names_that_are_not_valid_identifiers(string $class): void
	{
		self::assertNull(Autoloader::pathFor($class, 'OsfSeo\\', '/plugin/src'));
	}

	public function test_registered_autoloader_loads_class_from_directory(): void
	{
		$directory = sys_get_temp_dir() . '/osf-seo-autoload-' . bin2hex(random_bytes(4));
		mkdir($directory . '/Sub', 0700, true);
		file_put_contents(
			$directory . '/Sub/Sample.php',
			"<?php\nnamespace OsfSeoAutoloadFixture\\Sub;\nfinal class Sample {}\n",
		);

		try {
			Autoloader::register('OsfSeoAutoloadFixture\\', $directory);

			self::assertTrue(class_exists('OsfSeoAutoloadFixture\\Sub\\Sample'));
			self::assertFalse(class_exists('OsfSeoAutoloadFixture\\Sub\\Missing'));
		} finally {
			unlink($directory . '/Sub/Sample.php');
			rmdir($directory . '/Sub');
			rmdir($directory);
		}
	}
}
