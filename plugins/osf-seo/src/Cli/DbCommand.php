<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Database\MigrationLocked;
use OsfSeo\Database\Migrator;
use OsfSeo\Database\Schema;
use OsfSeo\Database\SchemaInspector;
use OsfSeo\Plugin;
use Throwable;
use WP_CLI;

/**
 * `wp osf-seo db:migrate` i `wp osf-seo db:status`.
 */
final class DbCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);

		WP_CLI::add_command('osf-seo db:migrate', [$command, 'migrate'], [
			'shortdesc' => 'Run pending OSF SEO database migrations (idempotent, never deletes data).',
		]);

		WP_CLI::add_command('osf-seo db:status', [$command, 'status'], [
			'shortdesc' => 'Show OSF SEO database schema version and table state.',
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
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function migrate(array $args, array $assocArgs): void
	{
		$migrator = $this->plugin->get(Migrator::class);
		$applied = [];

		try {
			$applied = $migrator->migrate();
		} catch (MigrationLocked $exception) {
			WP_CLI::error('Another process is running OSF SEO migrations. Try again in a moment.');
		} catch (Throwable $exception) {
			WP_CLI::error('Migration failed: ' . $exception->getMessage());
		}

		foreach ($applied as $migration) {
			WP_CLI::log('Applied: ' . $migration);
		}

		if ($applied === []) {
			WP_CLI::log('Nothing to migrate.');
		}

		$problems = $this->plugin->get(SchemaInspector::class)->problems();

		if ($problems !== []) {
			WP_CLI::error("Schema does not match the specification:\n- " . implode("\n- ", $problems));
		}

		WP_CLI::success(sprintf('Database schema is at version %d.', $migrator->currentVersion()));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function status(array $args, array $assocArgs): void
	{
		$migrator = $this->plugin->get(Migrator::class);
		$inspector = $this->plugin->get(SchemaInspector::class);
		$state = $inspector->inspect();
		$problems = $inspector->problems();

		$tables = [];

		foreach (array_keys(Schema::tables()) as $table) {
			$tableProblems = array_values(array_filter(
				$problems,
				static fn (string $problem): bool => str_contains($problem, ' ' . $table . ' ') || str_contains($problem, ' ' . $table . '.'),
			));

			$tables[] = [
				'table' => $table,
				'exists' => $state[$table]['exists'] ? 'yes' : 'no',
				'engine' => (string) ($state[$table]['engine'] ?? '-'),
				'collation' => (string) ($state[$table]['collation'] ?? '-'),
				'rows_estimate' => $state[$table]['rows'] === null ? '-' : (string) $state[$table]['rows'],
				'status' => $tableProblems === [] ? 'ok' : implode('; ', $tableProblems),
			];
		}

		$pending = array_map(
			static fn ($migration): string => sprintf('%04d %s', $migration->version(), $migration->name()),
			$migrator->pending(),
		);

		$ok = $problems === [] && $pending === [];

		if (($assocArgs['format'] ?? 'table') === 'json') {
			WP_CLI::line((string) wp_json_encode([
				'version' => $migrator->currentVersion(),
				'latest' => $migrator->latestVersion(),
				'pending' => $pending,
				'ok' => $ok,
				'tables' => $tables,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			if (! $ok) {
				WP_CLI::halt(1);
			}

			return;
		}

		WP_CLI::log(sprintf('Schema version: %d (latest: %d)', $migrator->currentVersion(), $migrator->latestVersion()));
		WP_CLI::log('Pending migrations: ' . ($pending === [] ? 'none' : implode(', ', $pending)));
		\WP_CLI\Utils\format_items('table', $tables, ['table', 'exists', 'engine', 'collation', 'rows_estimate', 'status']);

		if (! $ok) {
			WP_CLI::error('Database schema is not up to date. Run: wp osf-seo db:migrate');
		}

		WP_CLI::success('Database schema is up to date.');
	}
}
