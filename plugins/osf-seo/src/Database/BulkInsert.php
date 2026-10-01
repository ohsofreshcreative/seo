<?php

declare(strict_types=1);

namespace OsfSeo\Database;

use InvalidArgumentException;

/**
 * Wsadowy INSERT wielu wierszy w jednym zapytaniu, ograniczony liczbą wierszy i rozmiarem SQL
 * (bezpiecznie poniżej `max_allowed_packet`, którego minimalna typowa wartość to 1–4 MB na hostingu
 * współdzielonym; domyślnie 512 KB na zapytanie). Wartości zawsze przez `$wpdb->prepare`.
 *
 * Placeholdery kolumn: `%d`, `%f`, `%s` albo wyrażenie z jednym placeholderem, np. `UNHEX(%s)`.
 */
final class BulkInsert
{
	public const DEFAULT_MAX_ROWS = 1000;

	public const DEFAULT_MAX_BYTES = 524288;

	/** @var list<int|float|string> */
	private array $params = [];

	private int $rows = 0;

	private int $bytes = 0;

	private int $affected = 0;

	private int $statements = 0;

	private readonly string $rowSql;

	/**
	 * @param list<string> $columns
	 * @param list<string> $placeholders jeden na kolumnę
	 * @param string $suffix np. `ON DUPLICATE KEY UPDATE id = id`
	 */
	public function __construct(
		private readonly Connection $db,
		private readonly string $table,
		private readonly array $columns,
		array $placeholders,
		private readonly string $suffix = '',
		private readonly int $maxRows = self::DEFAULT_MAX_ROWS,
		private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
	) {
		if ($columns === [] || count($columns) !== count($placeholders) || $maxRows < 1 || $maxBytes < 1024) {
			throw new InvalidArgumentException('Invalid bulk insert definition.');
		}

		foreach ($placeholders as $placeholder) {
			if (preg_match_all('/%[dfs]/', $placeholder) !== 1) {
				throw new InvalidArgumentException('Every column needs exactly one placeholder.');
			}
		}

		$this->rowSql = '(' . implode(', ', $placeholders) . ')';
	}

	/**
	 * @param list<int|float|string> $values w kolejności kolumn
	 */
	public function add(array $values): void
	{
		if (count($values) !== count($this->columns)) {
			throw new InvalidArgumentException('Value count does not match columns.');
		}

		$size = 4;

		foreach ($values as $value) {
			$size += is_string($value) ? strlen($value) * 2 + 4 : 24;
		}

		if ($this->rows > 0 && $this->bytes + $size > $this->maxBytes) {
			$this->flush();
		}

		array_push($this->params, ...$values);
		$this->rows++;
		$this->bytes += $size;

		if ($this->rows >= $this->maxRows) {
			$this->flush();
		}
	}

	/** Wysyła zebrane wiersze. Zwraca liczbę zmienionych wierszy tego zapytania. */
	public function flush(): int
	{
		if ($this->rows === 0) {
			return 0;
		}

		$sql = sprintf(
			'INSERT INTO `%s` (`%s`) VALUES %s%s',
			$this->table,
			implode('`, `', $this->columns),
			implode(', ', array_fill(0, $this->rows, $this->rowSql)),
			$this->suffix === '' ? '' : ' ' . $this->suffix,
		);
		$params = $this->params;

		$this->params = [];
		$this->rows = 0;
		$this->bytes = 0;
		$this->statements++;

		$affected = $this->db->execute($sql, $params);
		$this->affected += $affected;

		return $affected;
	}

	public function affectedRows(): int
	{
		return $this->affected;
	}

	public function statements(): int
	{
		return $this->statements;
	}
}
