<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleConfig;
use OsfSeo\Google\TokenVault;
use OsfSeo\Plugin;
use WP_CLI;

/**
 * `wp osf-seo google:status` i `wp osf-seo google:generate-key`.
 * Żadna komenda nie wypisuje skonfigurowanych sekretów — tylko nazwy stałych i ich stan.
 */
final class GoogleCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);

		WP_CLI::add_command('osf-seo google:status', [$command, 'status'], [
			'shortdesc' => 'Show Google OAuth configuration state (never secret values), redirect URI and connections.',
			'synopsis' => [
				[
					'type' => 'assoc',
					'name' => 'format',
					'description' => 'Output format.',
					'optional' => true,
					'default' => 'table',
					'options' => ['table', 'json'],
				],
			],
		]);

		WP_CLI::add_command('osf-seo google:generate-key', [$command, 'generateKey'], [
			'shortdesc' => 'Generate a new random OSF_SEO_ENCRYPTION_KEY for wp-config.php (not stored anywhere).',
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function status(array $args, array $assocArgs): void
	{
		$config = $this->plugin->get(GoogleConfig::class);
		$problems = $config->problems();
		$rows = [];

		foreach ([GoogleConfig::CLIENT_ID, GoogleConfig::CLIENT_SECRET, GoogleConfig::ENCRYPTION_KEY] as $name) {
			$rows[] = ['check' => $name, 'value' => in_array($name, $config->missing(), true) ? 'missing' : 'set', 'status' => in_array($name, $config->missing(), true) ? 'fail' : 'ok'];
		}

		foreach ($config->errors() as $error) {
			$rows[] = ['check' => 'configuration', 'value' => $error, 'status' => 'fail'];
		}

		$rows[] = ['check' => 'redirect_uri', 'value' => $config->redirectUri(), 'status' => 'info'];
		$rows[] = ['check' => 'scopes', 'value' => implode(' ', GoogleConfig::SCOPES), 'status' => 'info'];

		foreach ($this->plugin->get(ConnectionRepository::class)->statusCounts() as $status => $count) {
			$rows[] = ['check' => 'connections_' . $status, 'value' => (string) $count, 'status' => 'info'];
		}

		\WP_CLI\Utils\format_items($assocArgs['format'] ?? 'table', $rows, ['check', 'value', 'status']);

		if ($problems !== []) {
			WP_CLI::halt(1);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function generateKey(array $args, array $assocArgs): void
	{
		WP_CLI::line(sprintf("define('%s', '%s');", GoogleConfig::ENCRYPTION_KEY, TokenVault::generateKey()));
		WP_CLI::warning('Store the key only in wp-config.php (never in the repository or database) and keep a secure backup. Changing it later requires reconnecting all Google accounts.');
	}
}
