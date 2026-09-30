<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Support;

use InvalidArgumentException;
use OsfSeo\Support\Config;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Redactor;
use OsfSeo\Tests\Support\SecretSamples;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
	/** @var list<string> */
	private array $lines = [];

	protected function tearDown(): void
	{
		putenv('OSF_SEO_LOG_LEVEL');
	}

	private function logger(string $level): Logger
	{
		return new Logger($level, function (string $line): void {
			$this->lines[] = $line;
		});
	}

	public function test_messages_below_configured_level_are_skipped(): void
	{
		$logger = $this->logger(Logger::WARNING);

		$logger->debug('debug');
		$logger->info('info');
		$logger->warning('warning');
		$logger->error('error');

		self::assertSame(['[osf-seo] WARNING: warning', '[osf-seo] ERROR: error'], $this->lines);
	}

	public function test_placeholders_are_interpolated_and_context_is_appended_as_json(): void
	{
		$this->logger(Logger::DEBUG)->info('Imported {rows} rows for {project}', ['rows' => 120, 'project' => 'ohsofresh.pl']);

		self::assertSame(
			'[osf-seo] INFO: Imported 120 rows for ohsofresh.pl {"rows":120,"project":"ohsofresh.pl"}',
			$this->lines[0],
		);
	}

	public function test_secrets_never_reach_the_log(): void
	{
		$accessToken = SecretSamples::googleAccessToken();
		$refreshToken = SecretSamples::googleRefreshToken();
		$clientSecret = SecretSamples::googleClientSecret();

		$this->logger(Logger::DEBUG)->error('Token refresh failed for {access_token}: ' . $clientSecret, [
			'access_token' => $accessToken,
			'response' => ['refresh_token' => $refreshToken, 'error' => 'invalid_grant'],
		]);

		$line = $this->lines[0];

		foreach ([$accessToken, $refreshToken, $clientSecret] as $secret) {
			self::assertStringNotContainsString($secret, $line);
		}

		self::assertStringContainsString('failed for ' . Redactor::MASK, $line);
		self::assertStringContainsString('"error":"invalid_grant"', $line);
	}

	public function test_unknown_level_is_rejected(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->logger(Logger::DEBUG)->log('verbose', 'message');
	}

	public function test_constructor_rejects_unknown_level(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Logger('loud');
	}

	public function test_level_is_read_from_configuration(): void
	{
		putenv('OSF_SEO_LOG_LEVEL=ERROR');

		self::assertSame(Logger::ERROR, Logger::fromConfig(new Config())->level());
	}

	public function test_invalid_configured_level_falls_back_to_default(): void
	{
		putenv('OSF_SEO_LOG_LEVEL=verbose');

		// W testach WP_DEBUG nie jest zdefiniowane, więc domyślnym poziomem jest warning.
		self::assertSame(Logger::WARNING, Logger::fromConfig(new Config())->level());
	}
}
