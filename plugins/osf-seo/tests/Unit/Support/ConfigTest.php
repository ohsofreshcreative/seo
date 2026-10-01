<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Support;

use OsfSeo\Support\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
	/** @var list<string> */
	private array $envNames = [];

	protected function tearDown(): void
	{
		foreach ($this->envNames as $name) {
			putenv($name);
		}
	}

	private function setEnv(string $name, string $value): void
	{
		$this->envNames[] = $name;
		putenv($name . '=' . $value);
	}

	public function test_constant_takes_precedence_over_environment(): void
	{
		define('OSF_SEO_TEST_CONFIG_PRECEDENCE', 'from-constant');
		$this->setEnv('OSF_SEO_TEST_CONFIG_PRECEDENCE', 'from-env');

		self::assertSame('from-constant', (new Config())->get('OSF_SEO_TEST_CONFIG_PRECEDENCE'));
	}

	public function test_environment_is_used_when_constant_is_missing(): void
	{
		$this->setEnv('OSF_SEO_TEST_CONFIG_ENV_ONLY', 'from-env');

		self::assertSame('from-env', (new Config())->get('OSF_SEO_TEST_CONFIG_ENV_ONLY'));
	}

	public function test_default_is_returned_for_missing_or_empty_values(): void
	{
		$this->setEnv('OSF_SEO_TEST_CONFIG_EMPTY', '');

		self::assertSame('fallback', (new Config())->get('OSF_SEO_TEST_CONFIG_MISSING', 'fallback'));
		self::assertSame('fallback', (new Config())->get('OSF_SEO_TEST_CONFIG_EMPTY', 'fallback'));
		self::assertNull((new Config())->get('OSF_SEO_TEST_CONFIG_MISSING'));
	}

	public function test_scalar_constants_are_cast_and_non_scalars_ignored(): void
	{
		define('OSF_SEO_TEST_CONFIG_INT', 42);
		define('OSF_SEO_TEST_CONFIG_ARRAY', ['not', 'scalar']);

		self::assertSame('42', (new Config())->get('OSF_SEO_TEST_CONFIG_INT'));
		self::assertSame('fallback', (new Config())->get('OSF_SEO_TEST_CONFIG_ARRAY', 'fallback'));
	}
}
