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
		self::assertSame(self::allMigrationLabels(), $migrator->migrate());
		self::assertSame($migrator->latestVersion(), $migrator->currentVersion());
		self::assertSame(count(Migrator::defaultMigrations()), $migrator->latestVersion());
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
		self::assertSame($migrator->latestVersion(), $migrator->currentVersion());
		self::assertSame($before, $this->dataSnapshot());
	}

	public function test_lost_version_option_reruns_idempotent_migration_without_data_loss(): void
	{
		$migrator = $this->migrator();
		$migrator->migrate();
		$this->seedData();
		$before = $this->dataSnapshot();

		delete_option(Migrator::OPTION_VERSION);

		self::assertSame(self::allMigrationLabels(), $migrator->migrate());
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
				return 99;
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

		$latest = count(Migrator::defaultMigrations());
		self::assertSame($latest, $migrator->currentVersion());
		self::assertSame(99, $migrator->latestVersion());
		self::assertSame(['0099 test_add_project_note'], $migrator->migrate());
		self::assertSame(99, $migrator->currentVersion());
		self::assertSame([], $migrator->migrate());

		$db = self::db();
		self::assertSame('none', $db->fetchValue("SELECT test_note FROM `{$db->table('projects')}` LIMIT 1"));
		self::assertSame($before, $this->dataSnapshot());
		self::assertContains('[osf-seo] INFO: Applied database migration 0099 test_add_project_note.', array_map(
			static fn (string $line): string => explode(' {', $line)[0],
			$this->logLines,
		));
	}

	public function test_upgrade_from_schema_4_adds_opportunity_tables_and_keeps_data(): void
	{
		// Stan stagingu przed STEP 11: schemat 4 z prawdziwymi danymi.
		$schema4 = array_slice(Migrator::defaultMigrations(), 0, 4);
		$this->migrator($schema4)->migrate();
		$this->seedData();
		$before = $this->dataSnapshot();
		$inspector = new SchemaInspector(self::db());

		self::assertSame(4, $this->migrator()->currentVersion());
		self::assertFalse($inspector->inspect()['opportunities']['exists']);

		$schema5 = array_slice(Migrator::defaultMigrations(), 0, 5);
		self::assertSame(['0005 create_opportunities'], $this->migrator($schema5)->migrate());
		self::assertSame(5, $this->migrator()->currentVersion());
		self::assertSame($before, $this->dataSnapshot(), 'Migracja 5 nie zmienia istniejących danych.');
		self::assertSame([
			'column keywords.market_key is missing',
			'index keywords.project_market_key is missing',
			'table market_keywords is missing',
			'table market_keyword_monthly is missing',
			'table market_tasks is missing',
			'table market_sync_state is missing',
			...array_slice(self::schema7Objects(), 2),
		], $inspector->problems(), 'Po migracji 5 brakuje wyłącznie obiektów schematów 6 i 7.');

		$indexes = $inspector->inspect()['opportunities']['indexes'];
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'fingerprint']], $indexes['project_fingerprint']);
		self::assertSame(['unique' => true, 'columns' => ['public_id']], $indexes['public_id']);
		self::assertSame(['unique' => true, 'columns' => ['opportunity_id', 'period_days']], $inspector->inspect()['opportunity_detections']['indexes']['PRIMARY']);

		// Ponowne uruchomienie migracji 5 (np. utracona wersja schematu) jest bezpieczne.
		update_option(Migrator::OPTION_VERSION, '4');
		self::assertSame(['0005 create_opportunities', '0006 create_market_data', '0007 create_keyword_discovery'], $this->migrator()->migrate());
		self::assertSame([], $inspector->problems());
	}

	public function test_upgrade_from_schema_5_adds_market_data_and_keeps_gsc_and_opportunity_data(): void
	{
		// Stan stagingu po STEP 11: schemat 5 z danymi GSC, szansą SEO i połączeniem Google.
		$schema5 = array_slice(Migrator::defaultMigrations(), 0, 5);
		$this->migrator($schema5)->migrate();
		$this->seedData();
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');
		$db->insert($db->table('opportunities'), [
			'public_id' => '01J0000000000000000000OPP1',
			'project_id' => 1,
			'fingerprint' => md5('opp', true),
			'type' => 'near_top',
			'property' => 'sc-domain:example.test',
			'status' => 'planned',
			'note' => 'Notatka zespołu',
			'first_detected_at' => $now,
			'last_detected_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$before = $this->dataSnapshot();
		$opportunityBefore = $db->fetchRow("SELECT * FROM `{$db->table('opportunities')}`");
		$inspector = new SchemaInspector(self::db());
		self::assertFalse($inspector->inspect()['market_keywords']['exists']);

		$schema6 = array_slice(Migrator::defaultMigrations(), 0, 6);
		self::assertSame(['0006 create_market_data'], $this->migrator($schema6)->migrate());
		self::assertSame(6, $this->migrator()->currentVersion());
		self::assertSame(self::schema7Objects(), $inspector->problems(), 'Schemat 6 zgodny ze specyfikacją (brakuje tylko obiektów schematu 7).');
		self::assertSame($before, $this->dataSnapshot(), 'Projekty, słownik i fakty GSC bez zmian.');
		self::assertSame($opportunityBefore, $db->fetchRow("SELECT * FROM `{$db->table('opportunities')}`"), 'Szansa i stan pracy bez zmian.');
		self::assertNull($db->fetchValue("SELECT market_key FROM `{$db->table('keywords')}` LIMIT 1"), 'Klucz rynkowy wyliczany później w tle; hash GSC bez zmian.');
		self::assertSame(md5('strony internetowe', true), $db->fetchValue("SELECT keyword_hash FROM `{$db->table('keywords')}` LIMIT 1"));

		$indexes = $inspector->inspect();
		self::assertSame(['unique' => true, 'columns' => ['provider', 'location_code', 'language_code', 'keyword_key']], $indexes['market_keywords']['indexes']['market_keyword']);
		self::assertSame(['unique' => false, 'columns' => ['project_id', 'market_key']], $indexes['keywords']['indexes']['project_market_key']);
		self::assertSame(['unique' => true, 'columns' => ['market_keyword_id', 'month']], $indexes['market_keyword_monthly']['indexes']['PRIMARY']);

		// Ponowne uruchomienie migracji 6 (utracona wersja schematu) jest bezpieczne.
		update_option(Migrator::OPTION_VERSION, '5');
		self::assertSame(['0006 create_market_data', '0007 create_keyword_discovery'], $this->migrator()->migrate());
		self::assertSame([], $inspector->problems());
		self::assertSame($before, $this->dataSnapshot());
	}

	public function test_upgrade_from_schema_6_adds_keyword_discovery_and_keeps_market_gsc_and_opportunity_data(): void
	{
		// Stan stagingu po STEP 12: schemat 6 z danymi GSC, szansą SEO, metrykami rynkowymi i rejestrem kosztów.
		$schema6 = array_slice(Migrator::defaultMigrations(), 0, 6);
		$this->migrator($schema6)->migrate();
		$this->seedData();
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');
		$db->insert($db->table('opportunities'), [
			'public_id' => '01J0000000000000000000OPP2',
			'project_id' => 1,
			'fingerprint' => md5('opp2', true),
			'type' => 'low_ctr',
			'property' => 'sc-domain:example.test',
			'status' => 'review',
			'first_detected_at' => $now,
			'last_detected_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$db->execute(
			"INSERT INTO `{$db->table('market_keywords')}` (provider, location_code, language_code, keyword_key, keyword, search_volume, keyword_difficulty, volume_fetched_at, created_at, updated_at)
			VALUES ('dataforseo', 2616, 'pl', UNHEX(%s), 'strony internetowe', 2400, 35, %s, %s, %s)",
			[md5('strony internetowe'), $now, $now, $now],
		);
		$db->insert($db->table('market_tasks'), [
			'provider' => 'dataforseo', 'endpoint' => 'google_ads_search_volume', 'mode' => 'standard', 'trigger_type' => 'manual',
			'project_id' => 1, 'location_code' => 2616, 'language_code' => 'pl', 'status' => 'completed', 'keywords_count' => 1,
			'estimated_cost' => 0.06, 'cost' => 0.06, 'created_at' => $now, 'updated_at' => $now,
		]);
		$before = $this->dataSnapshot();
		$rows = static fn (string $table): array => $db->fetchAll("SELECT * FROM `{$db->table($table)}` ORDER BY 1");
		$opportunities = $rows('opportunities');
		$tasks = $rows('market_tasks');
		$market = $rows('market_keywords');
		$inspector = new SchemaInspector(self::db());
		self::assertSame(self::schema7Objects(), $inspector->problems());

		self::assertSame(['0007 create_keyword_discovery'], $this->migrator()->migrate());
		self::assertSame(7, $this->migrator()->currentVersion());
		self::assertSame([], $inspector->problems(), 'Schemat 7 zgodny ze specyfikacją.');
		self::assertSame($before, $this->dataSnapshot(), 'Projekty, słownik i fakty GSC bez zmian.');
		self::assertSame($opportunities, $rows('opportunities'), 'Szanse SEO bez zmian.');
		self::assertSame($tasks, $rows('market_tasks'), 'Rejestr kosztów bez zmian.');
		self::assertSame(
			array_map(static fn (array $row): array => $row + ['search_intent' => null, 'intent_fetched_at' => null], $market),
			$rows('market_keywords'),
			'Metryki rynkowe bez zmian — nowe kolumny intencji są puste.',
		);

		$state = $inspector->inspect();
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'market_keyword_id']], $state['discovery_candidates']['indexes']['project_market_keyword']);
		self::assertSame(['unique' => true, 'columns' => ['candidate_id', 'seed_key', 'method']], $state['discovery_candidate_sources']['indexes']['PRIMARY']);
		self::assertSame(['unique' => true, 'columns' => ['run_id', 'seed_key']], $state['discovery_run_seeds']['indexes']['PRIMARY']);

		// Ponowne uruchomienie migracji 7 (utracona wersja schematu) jest bezpieczne.
		update_option(Migrator::OPTION_VERSION, '6');
		self::assertSame(['0007 create_keyword_discovery'], $this->migrator()->migrate());
		self::assertSame([], $inspector->problems());
		self::assertSame($before, $this->dataSnapshot());
		self::assertSame($tasks, $rows('market_tasks'));
	}

	/**
	 * @return list<string>
	 */
	private static function schema7Objects(): array
	{
		return [
			'column market_keywords.search_intent is missing',
			'column market_keywords.intent_fetched_at is missing',
			'table discovery_runs is missing',
			'table discovery_run_seeds is missing',
			'table discovery_candidates is missing',
			'table discovery_candidate_sources is missing',
			'table discovery_settings is missing',
		];
	}

	public function test_failed_migration_does_not_bump_version_and_keeps_earlier_ones(): void
	{
		$broken = new class implements Migration {
			public function version(): int
			{
				return 99;
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

		self::assertSame(count(Migrator::defaultMigrations()), $migrator->currentVersion(), 'Migracje produkcyjne zapisane, zepsuta nie.');
		self::assertSame([99], array_map(static fn (Migration $m): int => $m->version(), $migrator->pending()));
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

	public function test_upgrade_from_step5_schema_keeps_projects_and_google_connection(): void
	{
		// Stan stagingu po STEP 5: tylko migracja 0001, projekt z połączeniem Google i zaszyfrowanym tokenem.
		$v1 = $this->migrator([new \OsfSeo\Database\Migrations\M0001CreateCoreTables()]);
		$v1->migrate();
		self::assertSame(1, $v1->currentVersion());
		$this->seedData();
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');
		$envelope = 'v1.k1.' . str_repeat('A', 120);
		$connectionId = $db->insert($db->table('connections'), [
			'owner_user_id' => 1,
			'google_sub' => '1000777',
			'email' => 'owner@example.test',
			'refresh_token_enc' => $envelope,
			'scopes' => 'https://www.googleapis.com/auth/webmasters.readonly openid email',
			'status' => 'active',
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$db->execute("UPDATE `{$db->table('projects')}` SET connection_id = %d", [$connectionId]);
		$before = $this->dataSnapshot();
		$connectionBefore = $db->fetchRow("SELECT * FROM `{$db->table('connections')}` WHERE id = %d", [$connectionId]);

		$migrator = $this->migrator();
		self::assertSame(array_slice(self::allMigrationLabels(), 1), $migrator->migrate());

		self::assertSame($migrator->latestVersion(), $migrator->currentVersion());
		self::assertSame([], (new SchemaInspector(self::db()))->problems(), 'Po aktualizacji schemat zgodny ze specyfikacją.');
		self::assertSame($connectionBefore, $db->fetchRow("SELECT * FROM `{$db->table('connections')}` WHERE id = %d", [$connectionId]), 'Połączenie i szyfrogram bez zmian.');

		$after = $this->dataSnapshot();
		$after['projects'] = array_map(static fn (array $row): array => array_diff_key($row, ['gsc_data_property' => true]), $after['projects']);
		self::assertSame($before, $after, 'Dane projektów i faktów bez zmian (poza nowymi kolumnami).');
		self::assertNull($db->fetchValue("SELECT gsc_data_property FROM `{$db->table('projects')}` LIMIT 1"));
	}

	/**
	 * @return list<string>
	 */
	private static function allMigrationLabels(): array
	{
		return array_map(static fn (Migration $m): string => sprintf('%04d %s', $m->version(), $m->name()), Migrator::defaultMigrations());
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
				// Kolumny dodane przez późniejsze migracje (np. keywords.market_key) nie zmieniają istniejących danych.
				static fn (array $row): array => array_diff_key($row, ['test_note' => true, 'market_key' => true]),
				$db->fetchAll("SELECT * FROM `{$db->table($table)}` ORDER BY 1"),
			);
		}

		return $snapshot;
	}
}
