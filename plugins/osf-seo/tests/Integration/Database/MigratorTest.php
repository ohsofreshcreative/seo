<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Database;

use mysqli;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;
use OsfSeo\Database\MigrationLocked;
use OsfSeo\Database\Migrator;
use OsfSeo\Database\Schema;
use OsfSeo\Database\SchemaInspector;
use OsfSeo\Tests\Integration\IntegrationTestCase;
use RuntimeException;

final class MigratorTest extends IntegrationTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		self::dropPluginTables();
	}

	public static function tearDownAfterClass(): void
	{
		// Zostawiamy aktualny schemat dla kolejnych klas testów.
		self::dropPluginTables();
		self::freshTables();
	}

	private function migrator(?array $migrations = null): Migrator
	{
		return new Migrator(self::db(), $this->captureLogger(), $migrations);
	}

	public function test_fresh_install_creates_all_tables_and_sets_schema_version(): void
	{
		$migrator = $this->migrator();

		self::assertSame(0, $migrator->currentVersion());
		self::assertSame(['0001 create_core_tables'], $migrator->migrate());
		self::assertSame(1, $migrator->currentVersion());
		self::assertSame(1, $migrator->latestVersion());
		self::assertSame([], $migrator->pending());

		$state = (new SchemaInspector(self::db()))->inspect();

		foreach (array_keys(Schema::tables()) as $table) {
			self::assertTrue($state[$table]['exists'], "Brak tabeli {$table}");
		}
	}

	public function test_fresh_install_matches_schema_specification_exactly(): void
	{
		$this->migrator()->migrate();

		self::assertSame([], (new SchemaInspector(self::db()))->problems());
	}

	public function test_tables_use_innodb_and_utf8mb4(): void
	{
		$this->migrator()->migrate();

		foreach ((new SchemaInspector(self::db()))->inspect() as $table => $state) {
			self::assertSame('innodb', strtolower((string) $state['engine']), $table);
			self::assertStringStartsWith('utf8mb4', (string) $state['collation'], $table);
		}
	}

	public function test_critical_keys_exist_in_database(): void
	{
		$this->migrator()->migrate();
		$indexes = static fn (string $table): array => (new SchemaInspector(self::db()))->inspect()[$table]['indexes'];

		// Klucze, na których opiera się bezpieczeństwo (unikalny public_id) i wydajność (klastrowe PK faktów).
		self::assertSame(['unique' => true, 'columns' => ['public_id']], $indexes('projects')['public_id']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'user_id']], $indexes('project_users')['PRIMARY']);
		self::assertSame(['unique' => false, 'columns' => ['user_id', 'project_id']], $indexes('project_users')['user_project']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'keyword_hash']], $indexes('keywords')['project_keyword_hash']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'url_hash']], $indexes('pages')['project_url_hash']);
		self::assertSame(['unique' => true, 'columns' => ['provider', 'google_sub', 'owner_user_id']], $indexes('connections')['provider_account_owner']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'date', 'device']], $indexes('gsc_site_daily')['PRIMARY']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'date', 'keyword_id']], $indexes('gsc_query_daily')['PRIMARY']);
		self::assertSame(['unique' => false, 'columns' => ['project_id', 'keyword_id', 'date']], $indexes('gsc_query_daily')['project_keyword_date']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'date', 'keyword_id', 'page_id']], $indexes('gsc_query_page_daily')['PRIMARY']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'dataset']], $indexes('sync_state')['PRIMARY']);
	}

	public function test_unique_keys_are_enforced(): void
	{
		$this->migrator()->migrate();
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');

		$db->insert($db->table('keywords'), ['project_id' => 1, 'keyword' => 'agencja wordpress', 'keyword_hash' => md5('agencja wordpress', true), 'created_at' => $now]);

		$this->expectException(\OsfSeo\Database\DatabaseException::class);
		$this->expectExceptionMessageMatches('/Duplicate entry/i');

		$db->insert($db->table('keywords'), ['project_id' => 1, 'keyword' => 'AGENCJA WORDPRESS', 'keyword_hash' => md5('agencja wordpress', true), 'created_at' => $now]);
	}

	public function test_rerunning_migrations_is_a_no_op_and_keeps_data(): void
	{
		$migrator = $this->migrator();
		$migrator->migrate();
		$this->seedData();
		$before = $this->dataSnapshot();

		self::assertSame([], $migrator->migrate());
		self::assertSame(1, $migrator->currentVersion());
		self::assertSame($before, $this->dataSnapshot());
	}

	public function test_lost_version_option_reruns_idempotent_migration_without_data_loss(): void
	{
		$migrator = $this->migrator();
		$migrator->migrate();
		$this->seedData();
		$before = $this->dataSnapshot();

		delete_option(Migrator::OPTION_VERSION);

		self::assertSame(['0001 create_core_tables'], $migrator->migrate());
		self::assertSame($before, $this->dataSnapshot());
		self::assertSame([], (new SchemaInspector(self::db()))->problems());
	}

	public function test_upgrade_applies_only_new_migration_and_keeps_data(): void
	{
		$this->migrator()->migrate();
		$this->seedData();
		$before = $this->dataSnapshot();

		$upgrade = new class implements Migration {
			public function version(): int
			{
				return 2;
			}

			public function name(): string
			{
				return 'test_add_project_note';
			}

			public function up(Connection $db): void
			{
				$exists = $db->fetchValue(
					'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					[$db->table('projects'), 'test_note'],
				);

				if ($exists === '0') {
					$db->execute("ALTER TABLE `{$db->table('projects')}` ADD COLUMN `test_note` VARCHAR(50) NOT NULL DEFAULT 'none'");
				}
			}
		};

		$migrator = $this->migrator([...Migrator::defaultMigrations(), $upgrade]);

		self::assertSame(1, $migrator->currentVersion());
		self::assertSame(2, $migrator->latestVersion());
		self::assertSame(['0002 test_add_project_note'], $migrator->migrate());
		self::assertSame(2, $migrator->currentVersion());
		self::assertSame([], $migrator->migrate());

		$db = self::db();
		self::assertSame('none', $db->fetchValue("SELECT test_note FROM `{$db->table('projects')}` LIMIT 1"));
		self::assertSame($before, $this->dataSnapshot());
		self::assertContains('[osf-seo] INFO: Applied database migration 0002 test_add_project_note.', array_map(
			static fn (string $line): string => explode(' {', $line)[0],
			$this->logLines,
		));
	}

	public function test_failed_migration_does_not_bump_version_and_keeps_earlier_ones(): void
	{
		$broken = new class implements Migration {
			public function version(): int
			{
				return 2;
			}

			public function name(): string
			{
				return 'broken';
			}

			public function up(Connection $db): void
			{
				$db->execute('ALTER TABLE `table_that_does_not_exist_osf` ADD COLUMN x INT');
			}
		};

		$migrator = $this->migrator([...Migrator::defaultMigrations(), $broken]);

		try {
			$migrator->migrate();
			self::fail('Oczekiwano wyjątku z zepsutej migracji.');
		} catch (\OsfSeo\Database\DatabaseException) {
		}

		self::assertSame(1, $migrator->currentVersion(), 'Migracja 0001 zapisana, 0002 nie.');
		self::assertSame([2], array_map(static fn (Migration $m): int => $m->version(), $migrator->pending()));
		self::assertSame('1', self::db()->fetchValue('SELECT IS_FREE_LOCK(%s)', [$migrator->lockName()]), 'Blokada zwolniona po błędzie.');
	}

	public function test_concurrent_migration_is_blocked_by_lock(): void
	{
		$migrator = $this->migrator();
		$other = $this->secondConnection();

		self::assertSame('1', $other->query(sprintf("SELECT GET_LOCK('%s', 0)", $other->real_escape_string($migrator->lockName())))->fetch_row()[0]);

		try {
			$this->expectException(MigrationLocked::class);
			$migrator->migrate(0);
		} finally {
			$other->query(sprintf("SELECT RELEASE_LOCK('%s')", $other->real_escape_string($migrator->lockName())));
			$other->close();
			self::assertSame(0, $migrator->currentVersion(), 'Zablokowana migracja niczego nie zmieniła.');
		}
	}

	private function secondConnection(): mysqli
	{
		$host = DB_HOST;
		$socket = null;
		$port = null;

		if (str_contains($host, ':')) {
			[$host, $suffix] = explode(':', $host, 2);
			is_numeric($suffix) ? $port = (int) $suffix : $socket = $suffix;
		}

		$connection = new mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket);

		if ($connection->connect_errno !== 0) {
			throw new RuntimeException('Second test connection failed.');
		}

		return $connection;
	}

	private function seedData(): void
	{
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');

		$db->insert($db->table('projects'), [
			'public_id' => '01J0000000000000000000TEST',
			'name' => 'Projekt testowy',
			'domain' => 'example.test',
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$db->insert($db->table('keywords'), ['project_id' => 1, 'keyword' => 'strony internetowe', 'keyword_hash' => md5('strony internetowe', true), 'created_at' => $now]);
		$db->insert($db->table('gsc_query_daily'), ['project_id' => 1, 'date' => '2026-09-01', 'keyword_id' => 1, 'clicks' => 12, 'impressions' => 340, 'position_sum' => 2890.5]);
	}

	/**
	 * @return array<string, list<array<string, string|null>>>
	 */
	private function dataSnapshot(): array
	{
		$db = self::db();
		$snapshot = [];

		foreach (['projects', 'keywords', 'gsc_query_daily'] as $table) {
			$snapshot[$table] = array_map(
				static fn (array $row): array => array_diff_key($row, ['test_note' => true]),
				$db->fetchAll("SELECT * FROM `{$db->table($table)}` ORDER BY 1"),
			);
		}

		return $snapshot;
	}
}
