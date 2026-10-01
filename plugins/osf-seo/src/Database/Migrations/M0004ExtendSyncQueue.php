<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migration;

/**
 * Kolejka synchronizacji na `sync_runs` + kursory w `sync_state` (STEP 9).
 *
 * - sync_runs.status: + `retrying` (czeka na ponowienie), `cancelled` (anulowane, np. zmiana property),
 * - sync_runs: `priority`, `available_at` (najwcześniejsze uruchomienie — backoff), `locked_until` (lease),
 *   `property` (property, dla której zaplanowano zadanie — kontrolowana przy zatwierdzaniu importu),
 * - sync_state.status: + `retrying`; `refresh_cursor` (postęp odświeżania okna kroczącego),
 *   `last_refresh_at`, `retry_after` (przerwa po trwałym błędzie).
 *
 * Tylko dodawanie kolumn, indeksów i wartości ENUM (istniejące wartości zostają) — bez utraty danych; idempotentna.
 */
final class M0004ExtendSyncQueue implements Migration
{
	public const RUN_STATUSES = "'queued','running','success','failed','skipped','retrying','cancelled'";

	public const STATE_STATUSES = "'idle','queued','running','failed','retrying'";

	public function version(): int
	{
		return 4;
	}

	public function name(): string
	{
		return 'extend_sync_queue';
	}

	public function up(Connection $db): void
	{
		$runs = $db->table('sync_runs');
		$state = $db->table('sync_state');

		if (MigrationHelpers::columnType($db, 'sync_runs', 'status') !== 'enum(' . self::RUN_STATUSES . ')') {
			$db->execute("ALTER TABLE `{$runs}` MODIFY COLUMN `status` ENUM(" . self::RUN_STATUSES . ") NOT NULL DEFAULT 'queued'");
		}

		$columns = [
			'priority' => "TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER `status`",
			'available_at' => 'DATETIME NULL DEFAULT NULL AFTER `queued_at`',
			'locked_until' => 'DATETIME NULL DEFAULT NULL AFTER `finished_at`',
			'property' => 'VARCHAR(255) NULL DEFAULT NULL AFTER `locked_until`',
		];

		foreach ($columns as $column => $definition) {
			if (! MigrationHelpers::columnExists($db, 'sync_runs', $column)) {
				$db->execute("ALTER TABLE `{$runs}` ADD COLUMN `{$column}` {$definition}");
			}
		}

		$db->execute("UPDATE `{$runs}` SET available_at = queued_at WHERE available_at IS NULL");

		if (! MigrationHelpers::indexExists($db, 'sync_runs', 'queue')) {
			$db->execute("ALTER TABLE `{$runs}` ADD KEY `queue` (`status`, `priority`, `available_at`)");
		}

		if (! MigrationHelpers::indexExists($db, 'sync_runs', 'project_dataset_status')) {
			$db->execute("ALTER TABLE `{$runs}` ADD KEY `project_dataset_status` (`project_id`, `dataset`, `status`)");
		}

		if (MigrationHelpers::columnType($db, 'sync_state', 'status') !== 'enum(' . self::STATE_STATUSES . ')') {
			$db->execute("ALTER TABLE `{$state}` MODIFY COLUMN `status` ENUM(" . self::STATE_STATUSES . ") NOT NULL DEFAULT 'idle'");
		}

		$stateColumns = [
			'refresh_cursor' => 'DATE NULL DEFAULT NULL AFTER `oldest_date`',
			'last_refresh_at' => 'DATETIME NULL DEFAULT NULL AFTER `last_attempt_at`',
			'retry_after' => 'DATETIME NULL DEFAULT NULL AFTER `last_refresh_at`',
		];

		foreach ($stateColumns as $column => $definition) {
			if (! MigrationHelpers::columnExists($db, 'sync_state', $column)) {
				$db->execute("ALTER TABLE `{$state}` ADD COLUMN `{$column}` {$definition}");
			}
		}
	}
}
