<?php

declare(strict_types=1);

namespace OsfSeo\Database;

/**
 * Odczytuje rzeczywisty stan tabel pluginu z information_schema i porównuje go z Schema::tables().
 */
final class SchemaInspector
{
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * @return array<string, array{
	 *     exists: bool,
	 *     engine: string|null,
	 *     collation: string|null,
	 *     rows: int|null,
	 *     columns: array<string, string>,
	 *     indexes: array<string, array{unique: bool, columns: list<string>}>,
	 * }>
	 */
	public function inspect(): array
	{
		$names = [];

		foreach (array_keys(Schema::tables()) as $table) {
			$names[$this->db->table($table)] = $table;
		}

		$in = Connection::placeholders(array_keys($names));
		$params = array_keys($names);

		$result = [];

		foreach ($names as $table) {
			$result[$table] = ['exists' => false, 'engine' => null, 'collation' => null, 'rows' => null, 'columns' => [], 'indexes' => []];
		}

		$tables = $this->db->fetchAll(
			"SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})",
			$params,
		);

		foreach ($tables as $row) {
			$table = $names[$row['TABLE_NAME']] ?? null;

			if ($table !== null) {
				$result[$table]['exists'] = true;
				$result[$table]['engine'] = $row['ENGINE'];
				$result[$table]['collation'] = $row['TABLE_COLLATION'];
				$result[$table]['rows'] = $row['TABLE_ROWS'] === null ? null : (int) $row['TABLE_ROWS'];
			}
		}

		$columns = $this->db->fetchAll(
			"SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in}) ORDER BY TABLE_NAME, ORDINAL_POSITION",
			$params,
		);

		foreach ($columns as $row) {
			$table = $names[$row['TABLE_NAME']] ?? null;

			if ($table !== null) {
				$result[$table]['columns'][(string) $row['COLUMN_NAME']] = Schema::normalizeType((string) $row['COLUMN_TYPE']);
			}
		}

		$indexes = $this->db->fetchAll(
			"SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in}) ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
			$params,
		);

		foreach ($indexes as $row) {
			$table = $names[$row['TABLE_NAME']] ?? null;

			if ($table === null) {
				continue;
			}

			$index = (string) $row['INDEX_NAME'];
			$result[$table]['indexes'][$index]['unique'] = (string) $row['NON_UNIQUE'] === '0';
			$result[$table]['indexes'][$index]['columns'][] = (string) $row['COLUMN_NAME'];
		}

		return $result;
	}

	/**
	 * Rozbieżności względem specyfikacji (pusta lista = schemat zgodny).
	 *
	 * @return list<string>
	 */
	public function problems(): array
	{
		$actual = $this->inspect();
		$problems = [];

		foreach (Schema::tables() as $table => $expected) {
			$state = $actual[$table];

			if (! $state['exists']) {
				$problems[] = sprintf('table %s is missing', $table);

				continue;
			}

			if (strtolower((string) $state['engine']) !== 'innodb') {
				$problems[] = sprintf('table %s uses engine %s instead of InnoDB', $table, (string) $state['engine']);
			}

			if (! str_starts_with((string) $state['collation'], 'utf8mb4')) {
				$problems[] = sprintf('table %s uses collation %s instead of utf8mb4', $table, (string) $state['collation']);
			}

			foreach ($expected['columns'] as $column => $type) {
				if (! isset($state['columns'][$column])) {
					$problems[] = sprintf('column %s.%s is missing', $table, $column);
				} elseif ($state['columns'][$column] !== $type) {
					$problems[] = sprintf('column %s.%s has type %s instead of %s', $table, $column, $state['columns'][$column], $type);
				}
			}

			$expectedIndexes = ['PRIMARY' => ['unique' => true, 'columns' => $expected['primary']]];

			foreach ($expected['unique'] as $name => $indexColumns) {
				$expectedIndexes[$name] = ['unique' => true, 'columns' => $indexColumns];
			}

			foreach ($expected['indexes'] as $name => $indexColumns) {
				$expectedIndexes[$name] = ['unique' => false, 'columns' => $indexColumns];
			}

			foreach ($expectedIndexes as $name => $index) {
				$actualIndex = $state['indexes'][$name] ?? null;

				if ($actualIndex === null) {
					$problems[] = sprintf('index %s.%s is missing', $table, $name);
				} elseif ($actualIndex !== $index) {
					$problems[] = sprintf('index %s.%s differs from the specification', $table, $name);
				}
			}
		}

		return $problems;
	}
}
