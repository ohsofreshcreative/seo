<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\Roles;
use OsfSeo\Database\Schema;
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

	/** Ile rozbieżności schematu pokazać w jednym wierszu (reszta: `wp osf-seo db:status`). */
	private const MAX_LISTED_PROBLEMS = 3;

	/**
	 * @param array{
	 *     plugin_active: bool,
	 *     plugin_version: string,
	 *     installed_version: string|null,
	 *     php_version: string,
	 *     wp_version: string,
	 *     db_version: int,
	 *     db_latest: int,
	 *     db_problems: list<string>,
	 *     environment: string,
	 *     role_problems: list<string>,
	 *     log_level: string,
	 * } $facts
	 * @return array{ok: bool, rows: list<array{check: string, value: string, status: string}>}
	 */
	public static function build(array $facts): array
	{
		$tables = count(Schema::tables());

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
				'db_schema_version',
				sprintf('%d (latest %d)', $facts['db_version'], $facts['db_latest']),
				$facts['db_version'] === $facts['db_latest'],
			),
			self::check(
				'db_tables',
				$facts['db_problems'] === []
					? sprintf('%d/%d tables match the schema', $tables, $tables)
					: self::summarize($facts['db_problems']),
				$facts['db_problems'] === [],
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
	 * @param list<string> $problems
	 */
	private static function summarize(array $problems): string
	{
		$listed = array_slice($problems, 0, self::MAX_LISTED_PROBLEMS);
		$more = count($problems) - count($listed);

		return implode('; ', $listed) . ($more > 0 ? sprintf(' (+%d more, see wp osf-seo db:status)', $more) : '');
	}

	/**
	 * @return array{check: string, value: string, status: string}
	 */
	private static function check(string $check, string $value, bool $passed): array
	{
		return ['check' => $check, 'value' => $value, 'status' => $passed ? self::OK : self::FAIL];
	}
}
