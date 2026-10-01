<?php

declare(strict_types=1);

namespace OsfSeo\Database;

/**
 * Oczekiwany (najnowszy) stan schematu — źródło prawdy dla `wp osf-seo db:status` i testów.
 *
 * Celowo niezależny od DDL migracji: test integracyjny sprawdza, że świeża instalacja
 * przez migracje daje dokładnie ten stan (wykrywa rozjazd migracji i specyfikacji).
 * Przy każdej nowej migracji zmieniającej schemat zaktualizuj także ten opis.
 *
 * Typy kolumn w postaci znormalizowanej (małe litery, bez szerokości wyświetlania intów).
 */
final class Schema
{
	/**
	 * @return array<string, array{
	 *     columns: array<string, string>,
	 *     primary: list<string>,
	 *     unique: array<string, list<string>>,
	 *     indexes: array<string, list<string>>,
	 * }>
	 */
	public static function tables(): array
	{
		$metrics = [
			'clicks' => 'int unsigned',
			'impressions' => 'int unsigned',
			'position_sum' => 'double',
		];

		return [
			'projects' => [
				'columns' => [
					'id' => 'int unsigned',
					'public_id' => 'char(26)',
					'name' => 'varchar(190)',
					'domain' => 'varchar(190)',
					'country' => 'char(2)',
					'language' => 'varchar(10)',
					'status' => "enum('active','paused','archived')",
					'connection_id' => 'int unsigned',
					'gsc_property' => 'varchar(255)',
					'gsc_permission' => 'varchar(32)',
					'gsc_data_property' => 'varchar(255)',
					'settings' => 'longtext',
					'last_synced_at' => 'datetime',
					'created_by' => 'bigint unsigned',
					'created_at' => 'datetime',
					'updated_at' => 'datetime',
				],
				'primary' => ['id'],
				'unique' => ['public_id' => ['public_id']],
				'indexes' => ['status' => ['status'], 'domain' => ['domain'], 'connection_id' => ['connection_id']],
			],
			'project_users' => [
				'columns' => [
					'project_id' => 'int unsigned',
					'user_id' => 'bigint unsigned',
					'role' => "enum('manager','viewer')",
					'created_at' => 'datetime',
				],
				'primary' => ['project_id', 'user_id'],
				'unique' => [],
				'indexes' => ['user_project' => ['user_id', 'project_id']],
			],
			'connections' => [
				'columns' => [
					'id' => 'int unsigned',
					'owner_user_id' => 'bigint unsigned',
					'provider' => 'varchar(20)',
					'google_sub' => 'varchar(255)',
					'email' => 'varchar(190)',
					'refresh_token_enc' => 'text',
					'scopes' => 'text',
					'status' => "enum('active','needs_reauth','revoked')",
					'last_error' => 'varchar(255)',
					'last_refreshed_at' => 'datetime',
					'created_at' => 'datetime',
					'updated_at' => 'datetime',
				],
				'primary' => ['id'],
				'unique' => ['provider_account_owner' => ['provider', 'google_sub', 'owner_user_id']],
				'indexes' => ['owner_user_id' => ['owner_user_id']],
			],
			'keywords' => [
				'columns' => [
					'id' => 'int unsigned',
					'project_id' => 'int unsigned',
					'keyword' => 'varchar(500)',
					'keyword_hash' => 'binary(16)',
					'first_seen' => 'date',
					'last_seen' => 'date',
					'created_at' => 'datetime',
					'market_key' => 'binary(16)',
				],
				'primary' => ['id'],
				'unique' => ['project_keyword_hash' => ['project_id', 'keyword_hash']],
				'indexes' => ['project_last_seen' => ['project_id', 'last_seen'], 'project_market_key' => ['project_id', 'market_key']],
			],
			'pages' => [
				'columns' => [
					'id' => 'int unsigned',
					'project_id' => 'int unsigned',
					'url' => 'varchar(2048)',
					'url_hash' => 'binary(16)',
					'path' => 'varchar(2048)',
					'first_seen' => 'date',
					'last_seen' => 'date',
					'created_at' => 'datetime',
				],
				'primary' => ['id'],
				'unique' => ['project_url_hash' => ['project_id', 'url_hash']],
				'indexes' => [],
			],
			'gsc_site_daily' => [
				'columns' => ['project_id' => 'int unsigned', 'date' => 'date', 'device' => 'tinyint unsigned'] + $metrics,
				'primary' => ['project_id', 'date', 'device'],
				'unique' => [],
				'indexes' => [],
			],
			'gsc_query_daily' => [
				'columns' => ['project_id' => 'int unsigned', 'date' => 'date', 'keyword_id' => 'int unsigned'] + $metrics,
				'primary' => ['project_id', 'date', 'keyword_id'],
				'unique' => [],
				'indexes' => ['project_keyword_date' => ['project_id', 'keyword_id', 'date']],
			],
			'gsc_query_page_daily' => [
				'columns' => [
					'project_id' => 'int unsigned',
					'date' => 'date',
					'keyword_id' => 'int unsigned',
					'page_id' => 'int unsigned',
				] + $metrics,
				'primary' => ['project_id', 'date', 'keyword_id', 'page_id'],
				'unique' => [],
				'indexes' => [
					'project_keyword_date_page' => ['project_id', 'keyword_id', 'date', 'page_id'],
					'project_page_date_keyword' => ['project_id', 'page_id', 'date', 'keyword_id'],
				],
			],
			'gsc_import_staging' => [
				'columns' => [
					'run_id' => 'bigint unsigned',
					'date' => 'date',
					'keyword_id' => 'int unsigned',
					'page_id' => 'int unsigned',
				] + $metrics,
				'primary' => ['run_id', 'date', 'keyword_id', 'page_id'],
				'unique' => [],
				'indexes' => [],
			],
			'visibility_daily' => [
				'columns' => [
					'project_id' => 'int unsigned',
					'date' => 'date',
					'top3' => 'int unsigned',
					'top10' => 'int unsigned',
					'top20' => 'int unsigned',
					'top50' => 'int unsigned',
					'top100' => 'int unsigned',
					'keywords_total' => 'int unsigned',
				],
				'primary' => ['project_id', 'date'],
				'unique' => [],
				'indexes' => [],
			],
			'sync_state' => [
				'columns' => [
					'project_id' => 'int unsigned',
					'dataset' => 'varchar(32)',
					'status' => "enum('idle','queued','running','failed','retrying')",
					'newest_date' => 'date',
					'oldest_date' => 'date',
					'refresh_cursor' => 'date',
					'consecutive_failures' => 'smallint unsigned',
					'last_success_at' => 'datetime',
					'last_attempt_at' => 'datetime',
					'last_refresh_at' => 'datetime',
					'retry_after' => 'datetime',
					'last_error' => 'varchar(500)',
					'updated_at' => 'datetime',
				],
				'primary' => ['project_id', 'dataset'],
				'unique' => [],
				'indexes' => [],
			],
			'sync_runs' => [
				'columns' => [
					'id' => 'bigint unsigned',
					'project_id' => 'int unsigned',
					'dataset' => 'varchar(32)',
					'trigger_type' => "enum('schedule','manual','backfill','connect')",
					'window_start' => 'date',
					'window_end' => 'date',
					'status' => "enum('queued','running','success','failed','skipped','retrying','cancelled')",
					'priority' => 'tinyint unsigned',
					'attempt' => 'smallint unsigned',
					'rows_fetched' => 'int unsigned',
					'rows_written' => 'int unsigned',
					'api_requests' => 'smallint unsigned',
					'error_code' => 'varchar(64)',
					'error_message' => 'text',
					'queued_at' => 'datetime',
					'available_at' => 'datetime',
					'started_at' => 'datetime',
					'finished_at' => 'datetime',
					'locked_until' => 'datetime',
					'property' => 'varchar(255)',
				],
				'primary' => ['id'],
				'unique' => [],
				'indexes' => [
					'project_run' => ['project_id', 'id'],
					'status_queued' => ['status', 'queued_at'],
					'queue' => ['status', 'priority', 'available_at'],
					'project_dataset_status' => ['project_id', 'dataset', 'status'],
				],
			],
			'opportunities' => [
				'columns' => [
					'id' => 'int unsigned',
					'public_id' => 'char(26)',
					'project_id' => 'int unsigned',
					'fingerprint' => 'binary(16)',
					'type' => 'varchar(32)',
					'property' => 'varchar(255)',
					'page_url' => 'varchar(2048)',
					'page_hash' => 'binary(16)',
					'keyword' => 'varchar(500)',
					'state' => "enum('active','inactive','archived')",
					'status' => "enum('new','review','planned','in_progress','completed','dismissed')",
					'note' => 'text',
					'completed_on' => 'date',
					'baseline' => 'longtext',
					'last_priority' => 'tinyint unsigned',
					'last_confidence' => 'tinyint unsigned',
					'last_period_days' => 'tinyint unsigned',
					'last_latest_date' => 'date',
					'last_evidence' => 'longtext',
					'first_detected_at' => 'datetime',
					'last_detected_at' => 'datetime',
					'inactive_since' => 'datetime',
					'status_changed_at' => 'datetime',
					'status_changed_by' => 'bigint unsigned',
					'created_at' => 'datetime',
					'updated_at' => 'datetime',
				],
				'primary' => ['id'],
				'unique' => ['public_id' => ['public_id'], 'project_fingerprint' => ['project_id', 'fingerprint']],
				'indexes' => ['project_state_status' => ['project_id', 'state', 'status'], 'project_page' => ['project_id', 'page_hash']],
			],
			'opportunity_detections' => [
				'columns' => [
					'opportunity_id' => 'int unsigned',
					'period_days' => 'tinyint unsigned',
					'project_id' => 'int unsigned',
					'priority' => 'tinyint unsigned',
					'confidence' => 'tinyint unsigned',
					'impressions' => 'int unsigned',
					'clicks' => 'int unsigned',
					'latest_date' => 'date',
					'search_text' => 'text',
					'evidence' => 'longtext',
					'analyzed_at' => 'datetime',
				],
				'primary' => ['opportunity_id', 'period_days'],
				'unique' => [],
				'indexes' => ['project_period_priority' => ['project_id', 'period_days', 'priority']],
			],
			'opportunity_analyses' => [
				'columns' => [
					'project_id' => 'int unsigned',
					'period_days' => 'tinyint unsigned',
					'status' => "enum('success','skipped','failed')",
					'trigger_type' => 'varchar(16)',
					'property' => 'varchar(255)',
					'data_key' => 'char(32)',
					'latest_date' => 'date',
					'opportunities' => 'int unsigned',
					'duration_ms' => 'int unsigned',
					'message' => 'varchar(255)',
					'analyzed_at' => 'datetime',
				],
				'primary' => ['project_id', 'period_days'],
				'unique' => [],
				'indexes' => [],
			],
			'market_keywords' => [
				'columns' => [
					'id' => 'int unsigned',
					'provider' => 'varchar(20)',
					'location_code' => 'int unsigned',
					'language_code' => 'varchar(10)',
					'keyword_key' => 'binary(16)',
					'keyword' => 'varchar(255)',
					'search_volume' => 'int unsigned',
					'cpc' => 'decimal(12,4)',
					'competition_level' => 'varchar(16)',
					'competition_index' => 'tinyint unsigned',
					'low_top_of_page_bid' => 'decimal(12,4)',
					'high_top_of_page_bid' => 'decimal(12,4)',
					'keyword_difficulty' => 'tinyint unsigned',
					'volume_fetched_at' => 'datetime',
					'volume_stale_after' => 'datetime',
					'volume_pending_until' => 'datetime',
					'volume_task_id' => 'bigint unsigned',
					'difficulty_fetched_at' => 'datetime',
					'difficulty_stale_after' => 'datetime',
					'difficulty_task_id' => 'bigint unsigned',
					'created_at' => 'datetime',
					'updated_at' => 'datetime',
				],
				'primary' => ['id'],
				'unique' => ['market_keyword' => ['provider', 'location_code', 'language_code', 'keyword_key']],
				'indexes' => [],
			],
			'market_keyword_monthly' => [
				'columns' => [
					'market_keyword_id' => 'int unsigned',
					'month' => 'date',
					'search_volume' => 'int unsigned',
					'updated_at' => 'datetime',
				],
				'primary' => ['market_keyword_id', 'month'],
				'unique' => [],
				'indexes' => [],
			],
			'market_tasks' => [
				'columns' => [
					'id' => 'bigint unsigned',
					'provider' => 'varchar(20)',
					'endpoint' => 'varchar(64)',
					'mode' => "enum('standard','live')",
					'trigger_type' => 'varchar(16)',
					'project_id' => 'int unsigned',
					'location_code' => 'int unsigned',
					'language_code' => 'varchar(10)',
					'provider_task_id' => 'varchar(64)',
					'status' => "enum('pending','completed','failed','expired')",
					'keywords_count' => 'int unsigned',
					'results_count' => 'int unsigned',
					'keywords' => 'longtext',
					'estimated_cost' => 'decimal(12,6)',
					'cost' => 'decimal(12,6)',
					'attempts' => 'smallint unsigned',
					'error_code' => 'varchar(64)',
					'error_message' => 'varchar(255)',
					'created_at' => 'datetime',
					'next_check_at' => 'datetime',
					'completed_at' => 'datetime',
					'updated_at' => 'datetime',
				],
				'primary' => ['id'],
				'unique' => [],
				'indexes' => [
					'status_check' => ['status', 'next_check_at'],
					'created' => ['created_at'],
					'project_task' => ['project_id', 'id'],
				],
			],
			'market_sync_state' => [
				'columns' => [
					'project_id' => 'int unsigned',
					'provider' => 'varchar(20)',
					'enabled_at' => 'datetime',
					'enabled_by' => 'bigint unsigned',
					'last_run_at' => 'datetime',
					'last_success_at' => 'datetime',
					'last_error' => 'varchar(64)',
					'last_error_at' => 'datetime',
					'next_auto_at' => 'datetime',
					'updated_at' => 'datetime',
				],
				'primary' => ['project_id', 'provider'],
				'unique' => [],
				'indexes' => [],
			],
		];
	}

	/**
	 * Normalizacja typu z information_schema: MariaDB/MySQL 5.7 zwracają `int(10) unsigned`,
	 * MySQL 8 — `int unsigned`.
	 */
	public static function normalizeType(string $type): string
	{
		$type = strtolower(trim($type));

		return (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type);
	}
}
