<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Google;

use OsfSeo\Google\GoogleConfig;
use OsfSeo\Google\GoogleNotConfigured;
use OsfSeo\Support\Config;
use OsfSeo\Tests\Support\GoogleEnv;
use PHPUnit\Framework\TestCase;

final class GoogleConfigTest extends TestCase
{
	use GoogleEnv;

	protected function setUp(): void
	{
		$this->clearGoogleEnv();
	}

	protected function tearDown(): void
	{
		$this->clearGoogleEnv();
	}

	public function test_unconfigured_lists_missing_names_only(): void
	{
		$config = new GoogleConfig(new Config(), 'https://seo.example.test/oauth/google/callback');

		self::assertFalse($config->isConfigured());
		self::assertSame([GoogleConfig::CLIENT_ID, GoogleConfig::CLIENT_SECRET, GoogleConfig::ENCRYPTION_KEY], $config->missing());
		self::assertSame([], $config->errors());

		$this->expectException(GoogleNotConfigured::class);
		$config->clientSecret();
	}

	public function test_configured(): void
	{
		$config = $this->configureGoogle();

		self::assertTrue($config->isConfigured());
		self::assertSame([], $config->problems());
		self::assertSame('https://seo.example.test/oauth/google/callback', $config->redirectUri());
		self::assertSame($this->clientSecret, $config->clientSecret());
	}

	public function test_invalid_key_is_an_error_without_its_value(): void
	{
		$config = $this->configureGoogle(true, 'not-a-valid-key');

		self::assertFalse($config->isConfigured());
		self::assertSame([], $config->missing());
		self::assertCount(1, $config->errors());
		self::assertStringContainsString(GoogleConfig::ENCRYPTION_KEY, $config->errors()[0]);
		self::assertStringNotContainsString('not-a-valid-key', implode(' ', $config->problems()));
	}

	public function test_scopes_are_read_only_search_console_plus_identity(): void
	{
		self::assertSame(['https://www.googleapis.com/auth/webmasters.readonly', 'openid', 'email'], GoogleConfig::SCOPES);
		self::assertSame('/oauth/google/callback', GoogleConfig::CALLBACK_PATH);
	}
}
