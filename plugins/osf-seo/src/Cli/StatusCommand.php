<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\RoleManager;
use OsfSeo\Database\Migrator;
use OsfSeo\Database\SchemaInspector;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleConfig;
use OsfSeo\Plugin;
use OsfSeo\Setup\Installer;
use OsfSeo\Sync\SyncRunner;
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
	 *     db_version: int,
	 *     db_latest: int,
	 *     db_problems: list<string>,
	 *     environment: string,
	 *     role_problems: list<string>,
	 *     log_level: string,
	 *     google_missing: list<string>,
	 *     google_errors: list<string>,
	 *     google_redirect_uri: string,
	 *     google_connections: array<string, int>|null,
	 * }
	 */
	private function facts(): array
	{
		if (! function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$migrator = $this->plugin->get(Migrator::class);
		$google = $this->plugin->get(GoogleConfig::class);

		return [
			'plugin_active' => is_plugin_active(plugin_basename($this->plugin->file())),
			'plugin_version' => Plugin::VERSION,
			'installed_version' => $this->plugin->get(Installer::class)->installedVersion(),
			'php_version' => PHP_VERSION,
			'wp_version' => (string) get_bloginfo('version'),
			'db_version' => $migrator->currentVersion(),
			'db_latest' => $migrator->latestVersion(),
			'db_problems' => $this->plugin->get(SchemaInspector::class)->problems(),
			'environment' => wp_get_environment_type(),
			'role_problems' => $this->plugin->get(RoleManager::class)->problems(),
			'log_level' => $this->plugin->logger()->level(),
			'google_missing' => $google->missing(),
			'google_errors' => $google->errors(),
			'google_redirect_uri' => $google->redirectUri(),
			'google_connections' => $this->googleConnections(),
			'sync_heartbeat' => is_string($heartbeat = get_option(SyncRunner::HEARTBEAT_OPTION)) && $heartbeat !== '' ? $heartbeat : null,
			'sync_pending' => $this->pendingJobs(),
		];
	}

	private function pendingJobs(): ?int
	{
		try {
			$db = $this->plugin->get(\OsfSeo\Database\Connection::class);

			return (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('sync_runs')}` WHERE status IN ('queued', 'running', 'retrying')");
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return array<string, int>|null null, gdy tabela połączeń jest niedostępna (np. przed migracją)
	 */
	private function googleConnections(): ?array
	{
		try {
			return $this->plugin->get(ConnectionRepository::class)->statusCounts();
		} catch (\Throwable) {
			return null;
		}
	}
}
