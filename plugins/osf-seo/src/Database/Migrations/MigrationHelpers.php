<?php

declare(strict_types=1);

namespace OsfSeo\Database\Migrations;

use OsfSeo\Database\Connection;

/**
 * Sprawdzenia stanu schematu dla idempotentnych migracji (information_schema bieżącej bazy).
 */
final class MigrationHelpers
{
	public static function columnExists(Connection $db, string $table, string $column): bool
	{
		return $db->fetchValue(
			'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			[$db->table($table), $column],
		) !== '0';
	}

	public static function indexExists(Connection $db, string $table, string $index): bool
	{
		return $db->fetchValue(
			'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
			[$db->table($table), $index],
		) !== '0';
	}

	/** Typ kolumny w postaci z information_schema (np. `enum('a','b')`), null gdy kolumny nie ma. */
	public static function columnType(Connection $db, string $table, string $column): ?string
	{
		return $db->fetchValue(
			'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			[$db->table($table), $column],
		);
	}
}
