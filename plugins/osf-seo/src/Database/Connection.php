<?php

declare(strict_types=1);

namespace OsfSeo\Database;

use Throwable;
use wpdb;

/**
 * Cienka warstwa nad $wpdb: zapytania zawsze przez prepare(), błędy SQL jako wyjątki
 * (zamiast cichego `false` i wypisywania HTML przez $wpdb przy WP_DEBUG).
 *
 * Wartości NULL przekazuj przez insert()/update() albo wprost w SQL — $wpdb->prepare()
 * nie obsługuje NULL w parametrach.
 */
final class Connection
{
	/** @var array<int, int> głębokość transakcji per obiekt $wpdb */
	private static array $transactionDepth = [];

	public function __construct(private readonly wpdb $wpdb)
	{
	}

	public static function fromGlobals(): self
	{
		global $wpdb;

		return new self($wpdb);
	}

	/** Pełna nazwa tabeli pluginu, np. `wp_osf_projects`. */
	public function table(string $name): string
	{
		return $this->wpdb->prefix . 'osf_' . $name;
	}

	public function prefix(): string
	{
		return $this->wpdb->prefix;
	}

	public function optionsTable(): string
	{
		return $this->wpdb->options;
	}

	public function databaseName(): string
	{
		return (string) $this->wpdb->dbname;
	}

	/**
	 * @param list<int|float|string> $params
	 * @return int liczba zmienionych wierszy
	 */
	public function execute(string $sql, array $params = []): int
	{
		return (int) $this->run(fn () => $this->wpdb->query($this->prepare($sql, $params)));
	}

	/**
	 * @param list<int|float|string> $params
	 * @return list<array<string, string|null>>
	 */
	public function fetchAll(string $sql, array $params = []): array
	{
		$rows = $this->run(fn () => $this->wpdb->get_results($this->prepare($sql, $params), ARRAY_A));

		return is_array($rows) ? array_values($rows) : [];
	}

	/**
	 * @param list<int|float|string> $params
	 * @return array<string, string|null>|null
	 */
	public function fetchRow(string $sql, array $params = []): ?array
	{
		$row = $this->run(fn () => $this->wpdb->get_row($this->prepare($sql, $params), ARRAY_A));

		return is_array($row) ? $row : null;
	}

	/**
	 * @param list<int|float|string> $params
	 */
	public function fetchValue(string $sql, array $params = []): ?string
	{
		$value = $this->run(fn () => $this->wpdb->get_var($this->prepare($sql, $params)));

		return $value === null ? null : (string) $value;
	}

	/**
	 * @param array<string, int|float|string|null> $row
	 * @return int identyfikator AUTO_INCREMENT (0, gdy tabela go nie ma)
	 */
	public function insert(string $table, array $row): int
	{
		$this->run(fn () => $this->wpdb->insert($table, $row, $this->formats($row)));

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<string, int|float|string|null> $data
	 * @param array<string, int|float|string> $where
	 */
	public function update(string $table, array $data, array $where): int
	{
		return (int) $this->run(fn () => $this->wpdb->update($table, $data, $where, $this->formats($data), $this->formats($where)));
	}

	/**
	 * @param array<string, int|float|string> $where
	 */
	public function delete(string $table, array $where): int
	{
		return (int) $this->run(fn () => $this->wpdb->delete($table, $where, $this->formats($where)));
	}

	/**
	 * Transakcja. Wywołanie zagnieżdżone dołącza do zewnętrznej transakcji (w MySQL
	 * `START TRANSACTION` wewnątrz transakcji niejawnie zatwierdziłby poprzednie zmiany).
	 * Głębokość liczymy per połączenie $wpdb, bo usługi mogą mieć własne instancje Connection.
	 *
	 * @template T
	 * @param callable(self): T $callback
	 * @return T
	 */
	public function transaction(callable $callback): mixed
	{
		$key = spl_object_id($this->wpdb);

		if ((self::$transactionDepth[$key] ?? 0) > 0) {
			self::$transactionDepth[$key]++;

			try {
				return $callback($this);
			} finally {
				self::$transactionDepth[$key]--;
			}
		}

		$this->execute('START TRANSACTION');
		self::$transactionDepth[$key] = 1;

		try {
			$result = $callback($this);
			$this->execute('COMMIT');

			return $result;
		} catch (Throwable $exception) {
			$this->wpdb->query('ROLLBACK');

			throw $exception;
		} finally {
			unset(self::$transactionDepth[$key]);
		}
	}

	public function inTransaction(): bool
	{
		return (self::$transactionDepth[spl_object_id($this->wpdb)] ?? 0) > 0;
	}

	/**
	 * Blokada nazwana MySQL (GET_LOCK) — trzymana przez połączenie do zwolnienia albo rozłączenia
	 * (np. śmierci procesu PHP). Nazwę zawężamy do bazy i prefiksu (współdzielony serwer MySQL).
	 */
	public function acquireLock(string $name, int $timeout = 0): bool
	{
		return $this->fetchValue('SELECT GET_LOCK(%s, %d)', [$this->lockName($name), $timeout]) === '1';
	}

	/** Czy blokadę nazwaną trzyma teraz jakiekolwiek połączenie (bez jej przejmowania). */
	public function lockInUse(string $name): bool
	{
		return $this->fetchValue('SELECT IS_USED_LOCK(%s)', [$this->lockName($name)]) !== null;
	}

	public function releaseLock(string $name): void
	{
		$this->fetchValue('SELECT RELEASE_LOCK(%s)', [$this->lockName($name)]);
	}

	public function lockName(string $name): string
	{
		return 'osf_seo_' . $name . '_' . substr(md5($this->databaseName() . '|' . $this->prefix()), 0, 12);
	}

	/** Escapowanie `%` i `_` w wartości LIKE (wildcardy dokleja wywołujący). */
	public function escapeLike(string $value): string
	{
		return $this->wpdb->esc_like($value);
	}

	/**
	 * Placeholdery `%s` dla listy wartości, np. do `IN (...)`.
	 *
	 * @param list<mixed> $values
	 */
	public static function placeholders(array $values, string $placeholder = '%s'): string
	{
		return implode(', ', array_fill(0, max(1, count($values)), $placeholder));
	}

	/**
	 * @param list<int|float|string> $params
	 */
	private function prepare(string $sql, array $params): string
	{
		if ($params === []) {
			return $sql;
		}

		$prepared = $this->wpdb->prepare($sql, ...$params);

		if (! is_string($prepared) || $prepared === '') {
			throw new DatabaseException('Could not prepare SQL statement.');
		}

		return $prepared;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return list<string>
	 */
	private function formats(array $row): array
	{
		return array_values(array_map(static fn (mixed $value): string => match (true) {
			is_int($value) => '%d',
			is_float($value) => '%f',
			default => '%s',
		}, $row));
	}

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	private function run(callable $operation): mixed
	{
		$suppressed = $this->wpdb->suppress_errors(true);
		$this->wpdb->last_error = '';

		try {
			$result = $operation();
		} finally {
			$this->wpdb->suppress_errors($suppressed);
		}

		if ($this->wpdb->last_error !== '') {
			throw new DatabaseException($this->wpdb->last_error);
		}

		if ($result === false) {
			throw new DatabaseException('Database query failed.');
		}

		return $result;
	}
}
