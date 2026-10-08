<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration;

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migrator;
use OsfSeo\Database\Schema;
use OsfSeo\Support\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Baza testów na prawdziwym WordPressie i prawdziwej bazie (osobnej, testowej).
 */
abstract class IntegrationTestCase extends TestCase
{
	/** @var list<int> */
	private array $createdUsers = [];

	/** @var list<string> */
	protected array $logLines = [];

	protected function setUp(): void
	{
		parent::setUp();

		$GLOBALS['osf_seo_test_wp_notices'] = [];
		wp_set_current_user(0);
	}

	protected function tearDown(): void
	{
		foreach ($this->createdUsers as $userId) {
			wp_delete_user($userId);
		}

		$this->createdUsers = [];
		wp_set_current_user(0);

		$notices = $GLOBALS['osf_seo_test_wp_notices'];
		$GLOBALS['osf_seo_test_wp_notices'] = [];

		self::assertSame([], $notices, 'WordPress zgłosił nieprawidłowe użycie API.');

		parent::tearDown();
	}

	protected static function db(): Connection
	{
		return Connection::fromGlobals();
	}

	/** Drugie, niezależne połączenie z bazą testową (blokady GET_LOCK innego procesu). */
	protected function secondConnection(): \mysqli
	{
		$host = DB_HOST;
		$socket = null;
		$port = null;

		if (str_contains($host, ':')) {
			[$host, $suffix] = explode(':', $host, 2);
			is_numeric($suffix) ? $port = (int) $suffix : $socket = $suffix;
		}

		$connection = new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket);

		if ($connection->connect_errno !== 0) {
			throw new \RuntimeException('Second test connection failed.');
		}

		return $connection;
	}

	/** Logger zbierający wpisy w pamięci — do asercji, że sekrety nie trafiają do logów. */
	protected function captureLogger(string $level = Logger::DEBUG): Logger
	{
		return new Logger($level, function (string $line): void {
			$this->logLines[] = $line;
		});
	}

	/**
	 * Tworzy użytkownika o podanej roli (usuwany automatycznie po teście).
	 */
	protected function createUser(string $role, ?string $login = null): int
	{
		$login ??= $role . '-' . bin2hex(random_bytes(4));

		$userId = wp_insert_user([
			'user_login' => $login,
			'user_email' => $login . '@osf-seo.test',
			'user_pass' => wp_generate_password(24),
			'role' => $role,
		]);

		self::assertIsInt($userId, 'Nie udało się utworzyć użytkownika testowego.');
		$this->createdUsers[] = $userId;

		return $userId;
	}

	/** Usuwa wszystkie tabele pluginu i wersję schematu — stan „przed instalacją”. */
	protected static function dropPluginTables(): void
	{
		$db = self::db();

		foreach (array_keys(Schema::tables()) as $table) {
			$db->execute("DROP TABLE IF EXISTS `{$db->table($table)}`");
		}

		delete_option(Migrator::OPTION_VERSION);
	}

	/** Zapewnia aktualny schemat i puste tabele wskazane w argumentach. */
	protected static function freshTables(string ...$tables): void
	{
		(new Migrator(self::db(), new Logger(Logger::ERROR, static function (): void {
		})))->migrate();

		$db = self::db();

		foreach ($tables as $table) {
			$db->execute("DELETE FROM `{$db->table($table)}`");
		}
	}
}
