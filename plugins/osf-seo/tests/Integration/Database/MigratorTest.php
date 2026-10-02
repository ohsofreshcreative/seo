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
			...self::schema8Objects(),
			...self::schema9Tables(),
			...self::schema10Tables(),
			...self::schema11Tables(),
		], $inspector->problems(), 'Po migracji 5 brakuje wyłącznie obiektów schematów 6–11.');

		$indexes = $inspector->inspect()['opportunities']['indexes'];
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'fingerprint']], $indexes['project_fingerprint']);
		self::assertSame(['unique' => true, 'columns' => ['public_id']], $indexes['public_id']);
		self::assertSame(['unique' => true, 'columns' => ['opportunity_id', 'period_days']], $inspector->inspect()['opportunity_detections']['indexes']['PRIMARY']);

		// Ponowne uruchomienie migracji 5 (np. utracona wersja schematu) jest bezpieczne.
		update_option(Migrator::OPTION_VERSION, '4');
		self::assertSame(['0005 create_opportunities', '0006 create_market_data', '0007 create_keyword_discovery', '0008 create_serp_tracking', '0009 create_keyword_gap', '0010 create_strategy', '0011 serp_intelligence'], $this->migrator()->migrate());
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
		self::assertSame(self::schema6Problems(), $inspector->problems(), 'Schemat 6 zgodny ze specyfikacją (brakuje tylko obiektów schematów 7–10).');
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
		self::assertSame(['0006 create_market_data', '0007 create_keyword_discovery', '0008 create_serp_tracking', '0009 create_keyword_gap', '0010 create_strategy', '0011 serp_intelligence'], $this->migrator()->migrate());
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
		self::assertSame(self::schema6Problems(), $inspector->problems());

		$schema7 = array_slice(Migrator::defaultMigrations(), 0, 7);
		self::assertSame(['0007 create_keyword_discovery'], $this->migrator($schema7)->migrate());
		self::assertSame(7, $this->migrator()->currentVersion());
		self::assertSame(self::schema7Problems(), $inspector->problems(), 'Schemat 7 zgodny ze specyfikacją (brakuje tylko obiektów schematów 8 i 9).');
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
		self::assertSame(['0007 create_keyword_discovery', '0008 create_serp_tracking', '0009 create_keyword_gap', '0010 create_strategy', '0011 serp_intelligence'], $this->migrator()->migrate());
		self::assertSame([], $inspector->problems());
		self::assertSame($before, $this->dataSnapshot());
		self::assertSame($tasks, $rows('market_tasks'));
	}

	public function test_upgrade_from_schema_7_adds_serp_tracking_and_keeps_all_existing_data(): void
	{
		// Stan stagingu po STEP 13: schemat 7 z danymi GSC, szansą SEO, metrykami rynkowymi, kosztami i kandydatami.
		$schema7 = array_slice(Migrator::defaultMigrations(), 0, 7);
		$this->migrator($schema7)->migrate();
		$this->seedData();
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');
		$db->insert($db->table('opportunities'), [
			'public_id' => '01J0000000000000000000OPP3',
			'project_id' => 1,
			'fingerprint' => md5('opp3', true),
			'type' => 'near_top',
			'property' => 'sc-domain:example.test',
			'status' => 'planned',
			'first_detected_at' => $now,
			'last_detected_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$db->execute(
			"INSERT INTO `{$db->table('market_keywords')}` (provider, location_code, language_code, keyword_key, keyword, search_volume, keyword_difficulty, search_intent, volume_fetched_at, created_at, updated_at)
			VALUES ('dataforseo', 2616, 'pl', UNHEX(%s), 'strony internetowe', 2400, 35, 'commercial', %s, %s, %s)",
			[md5('strony internetowe'), $now, $now, $now],
		);
		$db->insert($db->table('market_tasks'), [
			'provider' => 'dataforseo', 'endpoint' => 'labs_related_keywords', 'mode' => 'live', 'trigger_type' => 'discovery',
			'project_id' => 1, 'location_code' => 2616, 'language_code' => 'pl', 'status' => 'completed', 'keywords_count' => 1,
			'estimated_cost' => 0.02, 'cost' => 0.02, 'created_at' => $now, 'updated_at' => $now,
		]);
		$db->insert($db->table('discovery_runs'), [
			'public_id' => '01J0000000000000000000RUN1', 'project_id' => 1, 'provider' => 'dataforseo', 'location_code' => 2616,
			'language_code' => 'pl', 'method' => 'related', 'status' => 'completed', 'trigger_type' => 'manual',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$db->insert($db->table('discovery_candidates'), [
			'public_id' => '01J0000000000000000000CAN1', 'project_id' => 1, 'market_keyword_id' => 1, 'status' => 'accepted',
			'note' => 'Notatka', 'first_run_id' => 1, 'last_run_id' => 1, 'discovered_at' => $now, 'last_seen_at' => $now,
			'created_at' => $now, 'updated_at' => $now,
		]);
		$before = $this->dataSnapshot();
		$rows = static fn (string $table): array => $db->fetchAll("SELECT * FROM `{$db->table($table)}` ORDER BY 1");
		$existing = [];

		foreach (['opportunities', 'market_keywords', 'market_tasks', 'discovery_runs', 'discovery_candidates'] as $table) {
			$existing[$table] = $rows($table);
		}

		$inspector = new SchemaInspector(self::db());
		self::assertSame(self::schema7Problems(), $inspector->problems());

		$schema8 = array_slice(Migrator::defaultMigrations(), 0, 8);
		self::assertSame(['0008 create_serp_tracking'], $this->migrator($schema8)->migrate());
		self::assertSame(8, $this->migrator()->currentVersion());
		self::assertSame(self::schema8Problems(), $inspector->problems(), 'Schemat 8 zgodny ze specyfikacją (brakuje tylko obiektów schematów 9 i 10).');
		self::assertSame($before, $this->dataSnapshot(), 'Projekty, słownik i fakty GSC bez zmian.');

		foreach ($existing as $table => $data) {
			self::assertSame($data, $rows($table), "{$table} bez zmian.");
		}

		$state = $inspector->inspect();
		self::assertSame(['unique' => true, 'columns' => ['snapshot_id', 'item_index']], $state['serp_results']['indexes']['PRIMARY']);
		self::assertSame(['unique' => false, 'columns' => ['domain_id', 'snapshot_id', 'rank_group']], $state['serp_results']['indexes']['domain_snapshot']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'market_keyword_id']], $state['serp_tracked_keywords']['indexes']['project_market_keyword']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'slot_key']], $state['serp_runs']['indexes']['project_slot']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'domain_key']], $state['serp_competitors']['indexes']['project_domain']);
		self::assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('serp_settings')}`"), 'Śledzenie pozycji nie włącza się samo.');

		// Ponowne uruchomienie migracji 8 (utracona wersja schematu) jest bezpieczne.
		update_option(Migrator::OPTION_VERSION, '7');
		self::assertSame(['0008 create_serp_tracking', '0009 create_keyword_gap', '0010 create_strategy', '0011 serp_intelligence'], $this->migrator()->migrate());
		self::assertSame([], $inspector->problems());
		self::assertSame($before, $this->dataSnapshot());
	}

	public function test_upgrade_from_schema_8_adds_keyword_gap_and_keeps_all_existing_data(): void
	{
		// Stan stagingu po STEP 14: schemat 8 z danymi GSC, metrykami, kosztami, kandydatem, konkurentem i monitorowaną frazą.
		$schema8 = array_slice(Migrator::defaultMigrations(), 0, 8);
		$this->migrator($schema8)->migrate();
		$this->seedData();
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');
		$db->execute(
			"INSERT INTO `{$db->table('market_keywords')}` (provider, location_code, language_code, keyword_key, keyword, search_volume, keyword_difficulty, search_intent, volume_fetched_at, created_at, updated_at)
			VALUES ('dataforseo', 2616, 'pl', UNHEX(%s), 'strony internetowe', 2400, 35, 'commercial', %s, %s, %s)",
			[md5('strony internetowe'), $now, $now, $now],
		);
		$db->insert($db->table('market_tasks'), [
			'provider' => 'dataforseo', 'endpoint' => 'google_organic_serp', 'mode' => 'standard', 'trigger_type' => 'manual',
			'project_id' => 1, 'location_code' => 2616, 'language_code' => 'pl', 'status' => 'completed', 'keywords_count' => 1,
			'estimated_cost' => 0.00465, 'cost' => 0.00465, 'created_at' => $now, 'updated_at' => $now,
		]);
		$db->insert($db->table('discovery_candidates'), [
			'public_id' => '01J0000000000000000000CAN2', 'project_id' => 1, 'market_keyword_id' => 1, 'status' => 'review',
			'first_run_id' => 1, 'last_run_id' => 1, 'discovered_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
		]);
		$db->insert($db->table('serp_competitors'), [
			'public_id' => '01J0000000000000000000COM1', 'project_id' => 1, 'name' => 'Konkurent Łódź', 'domain' => 'konkurent.example',
			'domain_key' => md5('konkurent.example', true), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
		]);
		$db->insert($db->table('serp_tracked_keywords'), [
			'public_id' => '01J0000000000000000000TRK1', 'project_id' => 1, 'market_keyword_id' => 1, 'source' => 'discovery',
			'status' => 'active', 'added_at' => $now, 'last_rank' => 7, 'updated_at' => $now,
		]);
		$before = $this->dataSnapshot();
		$rows = static fn (string $table): array => $db->fetchAll("SELECT * FROM `{$db->table($table)}` ORDER BY 1");
		$existing = [];

		foreach (['market_tasks', 'discovery_candidates', 'serp_tracked_keywords'] as $table) {
			$existing[$table] = $rows($table);
		}

		$market = $rows('market_keywords');
		$competitors = $rows('serp_competitors');
		$inspector = new SchemaInspector(self::db());
		self::assertSame(self::schema8Problems(), $inspector->problems());

		$schema9 = array_slice(Migrator::defaultMigrations(), 0, 9);
		self::assertSame(['0009 create_keyword_gap'], $this->migrator($schema9)->migrate());
		self::assertSame(9, $this->migrator()->currentVersion());
		self::assertSame([...self::schema11Enums("enum('manual','gsc','discovery','gap')"), ...self::schema10Tables(), ...self::schema11Tables()], $inspector->problems(), 'Schemat 9 zgodny ze specyfikacją (brakuje tylko obiektów schematów 10 i 11).');
		self::assertSame($before, $this->dataSnapshot(), 'Projekty, słownik i fakty GSC bez zmian.');

		foreach ($existing as $table => $data) {
			self::assertSame($data, $rows($table), "{$table} bez zmian.");
		}

		self::assertSame(array_map(static fn (array $row): array => $row + ['core_key' => null, 'other_language' => null], $market), $rows('market_keywords'), 'Metryki bez zmian — nowa kolumna pusta.');
		self::assertSame(array_map(static fn (array $row): array => $row + ['brand_terms' => null], $competitors), $rows('serp_competitors'), 'Konkurenci bez zmian — warianty marki puste.');
		self::assertSame('discovery', $db->fetchValue("SELECT source FROM `{$db->table('serp_tracked_keywords')}` LIMIT 1"));
		$db->insert($db->table('serp_tracked_keywords'), [
			'public_id' => '01J0000000000000000000TRK2', 'project_id' => 1, 'market_keyword_id' => 2, 'source' => 'gap',
			'status' => 'active', 'added_at' => $now, 'updated_at' => $now,
		]);
		self::assertSame('gap', $db->fetchValue("SELECT source FROM `{$db->table('serp_tracked_keywords')}` WHERE market_keyword_id = 2"));

		$state = $inspector->inspect();
		self::assertSame(['unique' => true, 'columns' => ['provider', 'location_code', 'language_code', 'domain_key']], $state['gap_domains']['indexes']['market_domain']);
		self::assertSame(['unique' => true, 'columns' => ['domain_id', 'market_keyword_id']], $state['gap_domain_keywords']['indexes']['PRIMARY']);
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'market_keyword_id']], $state['gap_keywords']['indexes']['project_market_keyword']);
		self::assertSame(['unique' => true, 'columns' => ['active_project_id']], $state['gap_runs']['indexes']['active_project']);
		self::assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('gap_settings')}`"), 'Harmonogram luk nie włącza się sam.');
		self::assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('gap_runs')}`"), 'Migracja niczego nie kolejkuje.');

		// Ponowne uruchomienie migracji 9 (utracona wersja schematu) jest bezpieczne.
		update_option(Migrator::OPTION_VERSION, '8');
		self::assertSame(['0009 create_keyword_gap', '0010 create_strategy', '0011 serp_intelligence'], $this->migrator()->migrate());
		self::assertSame([], $inspector->problems());
		self::assertSame($before, $this->dataSnapshot());
		self::assertSame('gap', $db->fetchValue("SELECT source FROM `{$db->table('serp_tracked_keywords')}` WHERE market_keyword_id = 2"));
	}

	public function test_upgrade_from_schema_9_adds_strategy_tables_and_keeps_all_existing_data(): void
	{
		// Stan stagingu po STEP 15: schemat 9 z danymi GSC, metrykami, monitorowaną frazą, luką i grupą luk.
		$schema9 = array_slice(Migrator::defaultMigrations(), 0, 9);
		$this->migrator($schema9)->migrate();
		$this->seedData();
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');
		$db->execute(
			"INSERT INTO `{$db->table('market_keywords')}` (provider, location_code, language_code, keyword_key, keyword, search_volume, keyword_difficulty, search_intent, volume_fetched_at, created_at, updated_at)
			VALUES ('dataforseo', 2616, 'pl', UNHEX(%s), 'strony internetowe', 2400, 35, 'commercial', %s, %s, %s)",
			[md5('strony internetowe'), $now, $now, $now],
		);
		$db->insert($db->table('serp_tracked_keywords'), [
			'public_id' => '01J0000000000000000000TRK3', 'project_id' => 1, 'market_keyword_id' => 1, 'source' => 'gap',
			'status' => 'active', 'added_at' => $now, 'last_rank' => 12, 'updated_at' => $now,
		]);
		$db->insert($db->table('gap_keywords'), [
			'public_id' => '01J0000000000000000000GAP1', 'project_id' => 1, 'market_keyword_id' => 1, 'status' => 'accepted', 'note' => 'Notatka Łódź',
			'active' => 1, 'listed' => 1, 'gap_type' => 'weak', 'visibility' => 'low', 'priority' => 64,
			'first_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
		]);
		$before = $this->dataSnapshot();
		$rows = static fn (string $table): array => $db->fetchAll("SELECT * FROM `{$db->table($table)}` ORDER BY 1");
		$existing = [];

		foreach (['market_keywords', 'serp_tracked_keywords', 'gap_keywords'] as $table) {
			$existing[$table] = $rows($table);
		}

		$inspector = new SchemaInspector(self::db());
		self::assertSame([...self::schema11Enums("enum('manual','gsc','discovery','gap')"), ...self::schema10Tables(), ...self::schema11Tables()], $inspector->problems());

		self::assertSame(['0010 create_strategy', '0011 serp_intelligence'], $this->migrator()->migrate());
		self::assertSame(11, $this->migrator()->currentVersion());
		self::assertSame([], $inspector->problems(), 'Schemat 11 zgodny ze specyfikacją.');
		self::assertSame($before, $this->dataSnapshot(), 'Projekty, słownik i fakty GSC bez zmian.');

		foreach ($existing as $table => $data) {
			self::assertSame($data, $rows($table), "{$table} bez zmian.");
		}

		$state = $inspector->inspect();
		self::assertSame(['unique' => true, 'columns' => ['project_id', 'market_keyword_id']], $state['strategy_keywords']['indexes']['project_market_keyword']);
		self::assertSame(['unique' => true, 'columns' => ['public_id']], $state['strategy_keywords']['indexes']['public_id']);
		self::assertSame(['unique' => true, 'columns' => ['project_id']], $state['strategy_settings']['indexes']['PRIMARY']);
		self::assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('strategy_keywords')}`"), 'Migracja niczego nie przelicza.');
		self::assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('strategy_settings')}`"));

		// Ponowne uruchomienie migracji 10 (utracona wersja schematu) jest bezpieczne.
		update_option(Migrator::OPTION_VERSION, '9');
		self::assertSame(['0010 create_strategy', '0011 serp_intelligence'], $this->migrator()->migrate());
		self::assertSame([], $inspector->problems());
		self::assertSame($before, $this->dataSnapshot());
	}

	public function test_upgrade_from_schema_10_adds_serp_intelligence_and_keeps_all_existing_data(): void
	{
		// Stan po fazie A STEP 16: schemat 10 z monitorowaną frazą, przebiegiem, pomiarem i wynikiem SERP oraz kandydatem Strategii.
		$schema10 = array_slice(Migrator::defaultMigrations(), 0, 10);
		$this->migrator($schema10)->migrate();
		$this->seedData();
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');
		$db->execute(
			"INSERT INTO `{$db->table('market_keywords')}` (provider, location_code, language_code, keyword_key, keyword, created_at, updated_at)
			VALUES ('dataforseo', 2616, 'pl', UNHEX(%s), 'pozycjonowanie łódź', %s, %s)",
			[md5('pozycjonowanie łódź'), $now, $now],
		);
		$db->insert($db->table('serp_tracked_keywords'), [
			'public_id' => '01J0000000000000000000TRK4', 'project_id' => 1, 'market_keyword_id' => 1, 'source' => 'gap',
			'status' => 'removed', 'added_at' => $now, 'last_snapshot_id' => 1, 'last_rank' => 4, 'updated_at' => $now,
		]);
		$db->insert($db->table('serp_runs'), [
			'public_id' => '01J0000000000000000000RUN1', 'project_id' => 1, 'context_id' => 1, 'trigger_type' => 'schedule', 'slot_key' => 'auto:2026-01-01 00:00',
			'status' => 'completed', 'keywords_planned' => 1, 'created_at' => $now, 'updated_at' => $now,
		]);
		$db->insert($db->table('serp_snapshots'), [
			'public_id' => '01J0000000000000000000SNP1', 'project_id' => 1, 'tracked_keyword_id' => 1, 'market_keyword_id' => 1, 'context_id' => 1,
			'run_id' => 1, 'provider' => 'dataforseo', 'status' => 'completed', 'trigger_type' => 'manual', 'requested_depth' => 100, 'created_at' => $now,
			'checked_at' => $now, 'project_rank' => 4,
		]);
		$db->insert($db->table('serp_results'), [
			'snapshot_id' => 1, 'item_index' => 0, 'result_type' => 1, 'rank_group' => 1, 'rank_absolute' => 1, 'domain_id' => 1, 'url_id' => 1,
		]);
		$db->insert($db->table('strategy_keywords'), [
			'public_id' => '01J0000000000000000000STR1', 'project_id' => 1, 'market_keyword_id' => 1, 'sources' => 2, 'tier' => 1,
			'evidence' => '{"v":2}', 'first_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
		]);
		$before = $this->dataSnapshot();
		$rows = static fn (string $table): array => $db->fetchAll("SELECT * FROM `{$db->table($table)}` ORDER BY 1");
		$existing = [];

		foreach (['market_keywords', 'serp_tracked_keywords', 'serp_runs', 'serp_snapshots', 'serp_results', 'strategy_keywords'] as $table) {
			$existing[$table] = $rows($table);
		}

		$inspector = new SchemaInspector(self::db());
		self::assertSame([...self::schema11Enums("enum('manual','gsc','discovery','gap')"), ...self::schema11Tables()], $inspector->problems());

		self::assertSame(['0011 serp_intelligence'], $this->migrator()->migrate());
		self::assertSame(11, $this->migrator()->currentVersion());
		self::assertSame([], $inspector->problems(), 'Schemat 11 zgodny ze specyfikacją.');
		self::assertSame($before, $this->dataSnapshot(), 'Projekty, słownik i fakty GSC bez zmian.');

		foreach ($existing as $table => $data) {
			self::assertSame($data, $rows($table), "{$table} bez zmian (wartości dopisane na końcu enum).");
		}

		// Nowe wartości: analiza Strategii jako status i źródło frazy, wyzwalacz przebiegu i pomiaru.
		$db->insert($db->table('serp_tracked_keywords'), [
			'public_id' => '01J0000000000000000000TRK5', 'project_id' => 1, 'market_keyword_id' => 2, 'source' => 'strategy',
			'status' => 'analysis', 'added_at' => $now, 'updated_at' => $now,
		]);
		$db->insert($db->table('serp_runs'), [
			'public_id' => '01J0000000000000000000RUN2', 'project_id' => 1, 'context_id' => 1, 'trigger_type' => 'analysis', 'slot_key' => 'analysis:x',
			'status' => 'queued', 'created_at' => $now, 'updated_at' => $now,
		]);
		self::assertSame(['strategy', 'analysis'], array_values($db->fetchRow("SELECT source, status FROM `{$db->table('serp_tracked_keywords')}` WHERE market_keyword_id = 2") ?? []));
		self::assertSame('analysis', $db->fetchValue("SELECT trigger_type FROM `{$db->table('serp_runs')}` WHERE slot_key = 'analysis:x'"));
		self::assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('serp_snapshot_profiles')}`"), 'Migracja niczego nie przelicza.');
		self::assertSame(['unique' => true, 'columns' => ['snapshot_id']], $inspector->inspect()['serp_snapshot_profiles']['indexes']['PRIMARY']);

		// Ponowne uruchomienie migracji 11 (utracona wersja schematu) jest bezpieczne.
		update_option(Migrator::OPTION_VERSION, '10');
		self::assertSame(['0011 serp_intelligence'], $this->migrator()->migrate());
		self::assertSame([], $inspector->problems());
		self::assertSame($before, $this->dataSnapshot());
		self::assertSame('analysis', $db->fetchValue("SELECT status FROM `{$db->table('serp_tracked_keywords')}` WHERE market_keyword_id = 2"));
	}

	/**
	 * Tabele schematu 10 (Strategia) — braki zgłaszane na końcu listy.
	 *
	 * @return list<string>
	 */
	private static function schema10Tables(): array
	{
		return ['table strategy_settings is missing', 'table strategy_keywords is missing'];
	}

	/**
	 * Tabela schematu 11 (profile pomiarów SERP) — brak zgłaszany na końcu listy.
	 *
	 * @return list<string>
	 */
	private static function schema11Tables(): array
	{
		return ['table serp_snapshot_profiles is missing'];
	}

	/**
	 * Addytywne zmiany enum schematu 11 w tabelach SERP (kolejność kolumn i tabel w specyfikacji).
	 *
	 * @return list<string>
	 */
	private static function schema11Enums(string $source): array
	{
		return [
			"column serp_tracked_keywords.source has type {$source} instead of enum('manual','gsc','discovery','gap','strategy')",
			"column serp_tracked_keywords.status has type enum('active','removed') instead of enum('active','removed','analysis')",
			"column serp_runs.trigger_type has type enum('schedule','manual') instead of enum('schedule','manual','analysis')",
			"column serp_snapshots.trigger_type has type enum('schedule','manual') instead of enum('schedule','manual','analysis')",
		];
	}

	/**
	 * Tabele schematu 9 (luki SEO) — braki zgłaszane na końcu listy (kolejność tabel w specyfikacji).
	 *
	 * @return list<string>
	 */
	private static function schema9Tables(): array
	{
		return array_map(
			static fn (string $table): string => "table {$table} is missing",
			['gap_domains', 'gap_domain_keywords', 'gap_domain_pages', 'gap_domain_events', 'gap_runs', 'gap_run_targets', 'gap_settings', 'gap_keywords', 'gap_clusters', 'gap_competitor_pages'],
		);
	}

	/**
	 * Braki bazy w schemacie 6: obiekty schematów 7, 8 i 9 w kolejności specyfikacji (`core_key` po kolumnach intencji).
	 *
	 * @return list<string>
	 */
	private static function schema6Problems(): array
	{
		$schema7 = self::schema7Objects();

		return [
			$schema7[0],
			$schema7[1],
			'column market_keywords.core_key is missing',
			'column market_keywords.other_language is missing',
			...array_slice($schema7, 2),
			...self::schema8Objects(),
			...self::schema9Tables(),
			...self::schema10Tables(),
			...self::schema11Tables(),
		];
	}

	/**
	 * @return list<string>
	 */
	private static function schema7Problems(): array
	{
		return ['column market_keywords.core_key is missing', 'column market_keywords.other_language is missing', ...self::schema8Objects(), ...self::schema9Tables(), ...self::schema10Tables(), ...self::schema11Tables()];
	}

	/**
	 * Braki bazy w schemacie 8: addytywne zmiany istniejących tabel i nowe tabele schematu 9.
	 *
	 * @return list<string>
	 */
	private static function schema8Problems(): array
	{
		return [
			'column market_keywords.core_key is missing',
			'column market_keywords.other_language is missing',
			'column serp_competitors.brand_terms is missing',
			...self::schema11Enums("enum('manual','gsc','discovery')"),
			...self::schema9Tables(),
			...self::schema10Tables(),
			...self::schema11Tables(),
		];
	}

	/**
	 * @return list<string>
	 */
	private static function schema8Objects(): array
	{
		return array_map(
			static fn (string $table): string => "table {$table} is missing",
			['serp_contexts', 'serp_settings', 'serp_competitors', 'serp_tracked_keywords', 'serp_runs', 'serp_snapshots', 'serp_results', 'serp_domains', 'serp_urls', 'serp_snippets'],
		);
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
