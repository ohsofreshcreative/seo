<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Database;

use InvalidArgumentException;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;
use OsfSeo\Database\Migrator;
use OsfSeo\Database\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
	public function test_specification_covers_all_mvp_tables(): void
	{
		self::assertEqualsCanonicalizing(
			['projects', 'project_users', 'connections', 'keywords', 'pages', 'gsc_site_daily', 'gsc_query_daily', 'gsc_query_page_daily', 'gsc_import_staging', 'visibility_daily', 'sync_state', 'sync_runs', 'opportunities', 'opportunity_detections', 'opportunity_analyses', 'market_keywords', 'market_keyword_monthly', 'market_tasks', 'market_sync_state', 'discovery_runs', 'discovery_run_seeds', 'discovery_candidates', 'discovery_candidate_sources', 'discovery_settings', 'serp_contexts', 'serp_settings', 'serp_competitors', 'serp_tracked_keywords', 'serp_runs', 'serp_snapshots', 'serp_results', 'serp_domains', 'serp_urls', 'serp_snippets'],
			array_keys(Schema::tables()),
		);
	}

	public function test_every_table_has_primary_key_and_indexes_reference_existing_columns(): void
	{
		foreach (Schema::tables() as $table => $definition) {
			self::assertNotEmpty($definition['primary'], $table);

			$indexed = [$definition['primary'], ...array_values($definition['unique']), ...array_values($definition['indexes'])];

			foreach ($indexed as $columns) {
				foreach ($columns as $column) {
					self::assertArrayHasKey($column, $definition['columns'], sprintf('%s: indeks na nieistniejącej kolumnie %s', $table, $column));
				}
			}
		}
	}

	public function test_fact_tables_are_clustered_by_project_and_date(): void
	{
		foreach (['gsc_site_daily', 'gsc_query_daily', 'gsc_query_page_daily', 'visibility_daily'] as $table) {
			self::assertSame(['project_id', 'date'], array_slice(Schema::tables()[$table]['primary'], 0, 2), $table);
		}
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function columnTypes(): iterable
	{
		yield 'MariaDB int' => ['int(10) unsigned', 'int unsigned'];
		yield 'MariaDB bigint' => ['bigint(20) unsigned', 'bigint unsigned'];
		yield 'MariaDB tinyint' => ['TINYINT(3) UNSIGNED', 'tinyint unsigned'];
		yield 'MySQL 8 int' => ['int unsigned', 'int unsigned'];
		yield 'varchar keeps length' => ['varchar(190)', 'varchar(190)'];
		yield 'binary keeps length' => ['binary(16)', 'binary(16)'];
		yield 'enum' => ["enum('active','paused')", "enum('active','paused')"];
	}

	#[DataProvider('columnTypes')]
	public function test_column_types_are_normalized_across_database_servers(string $raw, string $normalized): void
	{
		self::assertSame($normalized, Schema::normalizeType($raw));
	}

	public function test_default_migrations_are_ordered(): void
	{
		Migrator::assertOrdered(Migrator::defaultMigrations());

		self::assertSame(range(1, count(Migrator::defaultMigrations())), array_map(
			static fn (Migration $migration): int => $migration->version(),
			Migrator::defaultMigrations(),
		), 'Wersje migracji produkcyjnych są kolejne (bez luk).');
	}

	public function test_duplicate_or_descending_migration_versions_are_rejected(): void
	{
		$migration = static fn (int $version): Migration => new class ($version) implements Migration {
			public function __construct(private readonly int $version)
			{
			}

			public function version(): int
			{
				return $this->version;
			}

			public function name(): string
			{
				return 'test';
			}

			public function up(Connection $db): void
			{
			}
		};

		$this->expectException(InvalidArgumentException::class);

		Migrator::assertOrdered([$migration(1), $migration(3), $migration(2)]);
	}
}
