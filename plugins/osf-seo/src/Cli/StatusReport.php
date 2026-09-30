<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\Roles;
use OsfSeo\Plugin;

/**
 * Buduje raport `wp osf-seo status` z zebranych faktów. Czysta logika — testowana
 * jednostkowo; zbieranie faktów z WordPressa jest w StatusCommand.
 */
final class StatusReport
{
	public const OK = 'ok';

	public const FAIL = 'fail';

	public const INFO = 'info';

	/**
	 * @param array{
	 *     plugin_active: bool,
	 *     plugin_version: string,
	 *     installed_version: string|null,
	 *     php_version: string,
	 *     wp_version: string,
	 *     environment: string,
	 *     role_problems: list<string>,
	 *     log_level: string,
	 * } $facts
	 * @return array{ok: bool, rows: list<array{check: string, value: string, status: string}>}
	 */
	public static function build(array $facts): array
	{
		$rows = [
			self::check('plugin_active', $facts['plugin_active'] ? 'yes' : 'no', $facts['plugin_active']),
			self::check('plugin_version', $facts['plugin_version'], true),
			self::check(
				'installed_version',
				$facts['installed_version'] ?? 'none',
				$facts['installed_version'] === $facts['plugin_version'],
			),
			self::check(
				'php_version',
				sprintf('%s (min %s)', $facts['php_version'], Plugin::MIN_PHP),
				version_compare($facts['php_version'], Plugin::MIN_PHP, '>='),
			),
			self::check(
				'wordpress_version',
				sprintf('%s (min %s)', $facts['wp_version'], Plugin::MIN_WP),
				version_compare($facts['wp_version'], Plugin::MIN_WP, '>='),
			),
			self::check(
				'roles_and_capabilities',
				$facts['role_problems'] === []
					? implode(', ', [...array_keys(Roles::definitions()), Roles::WP_ADMINISTRATOR])
					: implode('; ', $facts['role_problems']),
				$facts['role_problems'] === [],
			),
			['check' => 'environment', 'value' => $facts['environment'], 'status' => self::INFO],
			['check' => 'log_level', 'value' => $facts['log_level'], 'status' => self::INFO],
		];

		return [
			'ok' => ! in_array(self::FAIL, array_column($rows, 'status'), true),
			'rows' => $rows,
		];
	}

	/**
	 * @return array{check: string, value: string, status: string}
	 */
	private static function check(string $check, string $value, bool $passed): array
	{
		return ['check' => $check, 'value' => $value, 'status' => $passed ? self::OK : self::FAIL];
	}
}
