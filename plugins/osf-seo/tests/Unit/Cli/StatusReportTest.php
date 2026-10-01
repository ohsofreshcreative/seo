<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Cli;

use OsfSeo\Cli\StatusReport;
use OsfSeo\Plugin;
use PHPUnit\Framework\TestCase;

final class StatusReportTest extends TestCase
{
	/**
	 * @param array<string, mixed> $overrides
	 * @return array{ok: bool, rows: list<array{check: string, value: string, status: string}>}
	 */
	private static function report(array $overrides = []): array
	{
		return StatusReport::build($overrides + [
			'plugin_active' => true,
			'plugin_version' => Plugin::VERSION,
			'installed_version' => Plugin::VERSION,
			'php_version' => '8.2.20',
			'wp_version' => '6.9.9',
			'db_version' => 1,
			'db_latest' => 1,
			'db_problems' => [],
			'environment' => 'local',
			'role_problems' => [],
			'log_level' => 'warning',
			'google_missing' => [],
			'google_errors' => [],
			'google_redirect_uri' => 'https://seo.example.test/oauth/google/callback',
			'google_connections' => ['active' => 2, 'needs_reauth' => 1, 'revoked' => 0],
			'sync_heartbeat' => '2026-10-01 05:00:00',
			'sync_pending' => 3,
			'dataforseo_missing' => [],
		]);
	}

	public function test_dataforseo_configuration_is_reported_without_values(): void
	{
		self::assertSame('info', self::row(self::report(), 'dataforseo')['status']);
		self::assertStringStartsWith('configured', self::row(self::report(), 'dataforseo')['value']);
		$missing = self::row(self::report(['dataforseo_missing' => ['OSF_SEO_DATAFORSEO_LOGIN', 'OSF_SEO_DATAFORSEO_PASSWORD']]), 'dataforseo');
		self::assertSame(['check' => 'dataforseo', 'value' => 'not configured (missing: OSF_SEO_DATAFORSEO_LOGIN, OSF_SEO_DATAFORSEO_PASSWORD)', 'status' => 'info'], $missing);
		self::assertTrue(self::report(['dataforseo_missing' => ['OSF_SEO_DATAFORSEO_LOGIN']])['ok'], 'Brak DataForSEO nie jest błędem instalacji.');
	}

	public function test_sync_queue_heartbeat_is_reported(): void
	{
		self::assertSame(['check' => 'sync_queue', 'value' => 'last run 2026-10-01 05:00:00 UTC, 3 pending job(s)', 'status' => 'info'], self::row(self::report(), 'sync_queue'));
		self::assertStringStartsWith('never ran', self::row(self::report(['sync_heartbeat' => null, 'sync_pending' => null]), 'sync_queue')['value']);
	}

	/**
	 * @param array{rows: list<array{check: string, value: string, status: string}>} $report
	 * @return array{check: string, value: string, status: string}
	 */
	private static function row(array $report, string $check): array
	{
		foreach ($report['rows'] as $row) {
			if ($row['check'] === $check) {
				return $row;
			}
		}

		self::fail(sprintf('Row "%s" not found.', $check));
	}

	public function test_healthy_installation_passes_all_checks(): void
	{
		$report = self::report();

		self::assertTrue($report['ok']);
		self::assertSame(
			[
				'plugin_active', 'plugin_version', 'installed_version', 'php_version', 'wordpress_version', 'db_schema_version',
				'db_tables', 'roles_and_capabilities', 'google_oauth', 'google_redirect_uri', 'google_connections', 'sync_queue', 'dataforseo', 'environment', 'log_level',
			],
			array_column($report['rows'], 'check'),
		);
		self::assertSame('1 (latest 1)', self::row($report, 'db_schema_version')['value']);
		self::assertSame(sprintf('%1$d/%1$d tables match the schema', count(\OsfSeo\Database\Schema::tables())), self::row($report, 'db_tables')['value']);
		self::assertSame('yes', self::row($report, 'plugin_active')['value']);
		self::assertSame('8.2.20 (min ' . Plugin::MIN_PHP . ')', self::row($report, 'php_version')['value']);
	}

	public function test_inactive_plugin_fails(): void
	{
		$report = self::report(['plugin_active' => false]);

		self::assertFalse($report['ok']);
		self::assertSame(StatusReport::FAIL, self::row($report, 'plugin_active')['status']);
	}

	public function test_pending_upgrade_fails(): void
	{
		$report = self::report(['installed_version' => '0.0.1']);

		self::assertFalse($report['ok']);
		self::assertSame(StatusReport::FAIL, self::row($report, 'installed_version')['status']);
	}

	public function test_missing_installation_marker_fails(): void
	{
		$report = self::report(['installed_version' => null]);

		self::assertSame('none', self::row($report, 'installed_version')['value']);
		self::assertFalse($report['ok']);
	}

	public function test_too_old_php_or_wordpress_fails(): void
	{
		self::assertFalse(self::report(['php_version' => '8.1.29'])['ok']);
		self::assertFalse(self::report(['wp_version' => '6.5.5'])['ok']);
		self::assertTrue(self::report(['wp_version' => Plugin::MIN_WP])['ok']);
	}

	public function test_role_problems_fail_and_are_listed(): void
	{
		$report = self::report(['role_problems' => ['missing role osf_seo_client', 'role administrator lacks osf_seo_access']]);

		self::assertFalse($report['ok']);
		self::assertSame(
			'missing role osf_seo_client; role administrator lacks osf_seo_access',
			self::row($report, 'roles_and_capabilities')['value'],
		);
	}

	public function test_pending_schema_migration_fails(): void
	{
		$report = self::report(['db_version' => 0, 'db_latest' => 1]);

		self::assertFalse($report['ok']);
		self::assertSame('0 (latest 1)', self::row($report, 'db_schema_version')['value']);
	}

	public function test_schema_problems_fail_and_long_lists_are_truncated(): void
	{
		$report = self::report(['db_problems' => ['table projects is missing', 'table keywords is missing', 'table pages is missing', 'table sync_runs is missing']]);

		self::assertFalse($report['ok']);
		self::assertSame(
			'table projects is missing; table keywords is missing; table pages is missing (+1 more, see wp osf-seo db:status)',
			self::row($report, 'db_tables')['value'],
		);
	}

	public function test_informational_rows_never_fail(): void
	{
		$report = self::report(['environment' => 'production', 'log_level' => 'debug']);

		self::assertSame(StatusReport::INFO, self::row($report, 'environment')['status']);
		self::assertSame(StatusReport::INFO, self::row($report, 'log_level')['status']);
		self::assertTrue($report['ok']);
	}

	public function test_configured_google_oauth_passes(): void
	{
		$report = self::report();

		self::assertSame(['check' => 'google_oauth', 'value' => 'configured', 'status' => StatusReport::OK], self::row($report, 'google_oauth'));
		self::assertSame('active: 2, needs_reauth: 1, revoked: 0', self::row($report, 'google_connections')['value']);
		self::assertSame('https://seo.example.test/oauth/google/callback', self::row($report, 'google_redirect_uri')['value']);
	}

	public function test_missing_google_configuration_is_informational(): void
	{
		$report = self::report(['google_missing' => ['OSF_SEO_GOOGLE_CLIENT_ID', 'OSF_SEO_ENCRYPTION_KEY']]);

		self::assertTrue($report['ok']);
		self::assertSame(StatusReport::INFO, self::row($report, 'google_oauth')['status']);
		self::assertSame('not configured (missing: OSF_SEO_GOOGLE_CLIENT_ID, OSF_SEO_ENCRYPTION_KEY)', self::row($report, 'google_oauth')['value']);
	}

	public function test_invalid_google_configuration_fails(): void
	{
		$report = self::report(['google_missing' => ['OSF_SEO_GOOGLE_CLIENT_ID'], 'google_errors' => ['OSF_SEO_ENCRYPTION_KEY must be 32 random bytes']]);

		self::assertFalse($report['ok']);
		self::assertSame(StatusReport::FAIL, self::row($report, 'google_oauth')['status']);
		self::assertSame('OSF_SEO_ENCRYPTION_KEY must be 32 random bytes', self::row($report, 'google_oauth')['value']);
	}

	public function test_unavailable_connections_table_is_reported(): void
	{
		$report = self::report(['google_connections' => null]);

		self::assertSame('unavailable (see db_tables)', self::row($report, 'google_connections')['value']);
		self::assertSame(StatusReport::INFO, self::row($report, 'google_connections')['status']);
	}
}
