<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\RoleManager;
use OsfSeo\Plugin;
use OsfSeo\Setup\Installer;
use WP_CLI;

/**
 * `wp osf-seo status` — stan pluginu i środowiska. Kod wyjścia 1, gdy któraś kontrola nie przejdzie.
 */
final class StatusCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		WP_CLI::add_command('osf-seo status', new self($plugin), [
			'shortdesc' => 'Show OSF SEO plugin status and environment checks.',
			'synopsis' => [
				[
					'type' => 'assoc',
					'name' => 'format',
					'description' => 'Output format.',
					'optional' => true,
					'default' => 'table',
					'options' => ['table', 'json', 'yaml', 'csv'],
				],
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function __invoke(array $args, array $assocArgs): void
	{
		$format = $assocArgs['format'] ?? 'table';
		$report = StatusReport::build($this->facts());

		\WP_CLI\Utils\format_items($format, $report['rows'], ['check', 'value', 'status']);

		// Komunikaty tylko dla tabeli — formaty maszynowe (json/yaml/csv) muszą pozostać parsowalne.
		if (! $report['ok']) {
			if ($format === 'table') {
				WP_CLI::error('Some OSF SEO checks failed.');
			}

			WP_CLI::halt(1);
		}

		if ($format === 'table') {
			WP_CLI::success('All OSF SEO checks passed.');
		}
	}

	/**
	 * @return array{
	 *     plugin_active: bool,
	 *     plugin_version: string,
	 *     installed_version: string|null,
	 *     php_version: string,
	 *     wp_version: string,
	 *     environment: string,
	 *     role_problems: list<string>,
	 *     log_level: string,
	 * }
	 */
	private function facts(): array
	{
		if (! function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return [
			'plugin_active' => is_plugin_active(plugin_basename($this->plugin->file())),
			'plugin_version' => Plugin::VERSION,
			'installed_version' => $this->plugin->get(Installer::class)->installedVersion(),
			'php_version' => PHP_VERSION,
			'wp_version' => (string) get_bloginfo('version'),
			'environment' => wp_get_environment_type(),
			'role_problems' => $this->plugin->get(RoleManager::class)->problems(),
			'log_level' => $this->plugin->logger()->level(),
		];
	}
}
