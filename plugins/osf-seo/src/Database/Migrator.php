<?php

declare(strict_types=1);

namespace OsfSeo\Database;

use InvalidArgumentException;
use OsfSeo\Database\Migrations\M0001CreateCoreTables;
use OsfSeo\Database\Migrations\M0002AddGscDataProperty;
use OsfSeo\Database\Migrations\M0003CreateImportStaging;
use OsfSeo\Database\Migrations\M0004ExtendSyncQueue;
use OsfSeo\Support\Logger;

/**
 * Wersjonowane migracje schematu. Wersja zapisana w opcji `osf_seo_db_version`
 * jest podbijana po każdej udanej migracji, więc przerwana seria wznawia się od miejsca awarii.
 *
 * Równoległe uruchomienia (np. kilka żądań tuż po wdrożeniu) serializuje blokada GET_LOCK.
 */
final class Migrator
{
	public const OPTION_VERSION = 'osf_seo_db_version';

	private const DEFAULT_LOCK_TIMEOUT = 10;

	/** @var list<Migration> */
	private array $migrations;

	/**
	 * @param list<Migration>|null $migrations
	 */
	public function __construct(
		private readonly Connection $db,
		private readonly Logger $logger,
		?array $migrations = null,
	) {
		$migrations ??= self::defaultMigrations();
		self::assertOrdered($migrations);
		$this->migrations = $migrations;
	}

	/**
	 * @return list<Migration>
	 */
	public static function defaultMigrations(): array
	{
		return [
			new M0001CreateCoreTables(),
			new M0002AddGscDataProperty(),
			new M0003CreateImportStaging(),
			new M0004ExtendSyncQueue(),
		];
	}

	/**
	 * @param list<mixed> $migrations
	 */
	public static function assertOrdered(array $migrations): void
	{
		$previous = 0;

		foreach ($migrations as $migration) {
			if (! $migration instanceof Migration) {
				throw new InvalidArgumentException('Every migration must implement ' . Migration::class . '.');
			}

			if ($migration->version() <= $previous) {
				throw new InvalidArgumentException(sprintf(
					'Migration versions must be unique and ascending; %d follows %d.',
					$migration->version(),
					$previous,
				));
			}

			$previous = $migration->version();
		}
	}

	public function currentVersion(): int
	{
		return (int) get_option(self::OPTION_VERSION, 0);
	}

	public function latestVersion(): int
	{
		$last = end($this->migrations);

		return $last === false ? 0 : $last->version();
	}

	/**
	 * @return list<Migration>
	 */
	public function pending(): array
	{
		return $this->pendingAfter($this->currentVersion());
	}

	/**
	 * Wykonuje oczekujące migracje.
	 *
	 * @return list<string> zastosowane migracje, np. "0001 create_core_tables"
	 * @throws MigrationLocked gdy inny proces trzyma blokadę dłużej niż $lockTimeout sekund
	 */
	public function migrate(int $lockTimeout = self::DEFAULT_LOCK_TIMEOUT): array
	{
		if ($this->pending() === []) {
			return [];
		}

		$lock = $this->lockName();

		if ($this->db->fetchValue('SELECT GET_LOCK(%s, %d)', [$lock, $lockTimeout]) !== '1') {
			throw new MigrationLocked('Another process is running OSF SEO database migrations.');
		}

		try {
			$applied = [];

			// Po uzyskaniu blokady wersję czytamy z bazy, nie z cache opcji — inny proces mógł ją właśnie podbić.
			foreach ($this->pendingAfter($this->storedVersion()) as $migration) {
				$migration->up($this->db);
				update_option(self::OPTION_VERSION, (string) $migration->version(), true);

				$label = sprintf('%04d %s', $migration->version(), $migration->name());
				$applied[] = $label;
				$this->logger->info('Applied database migration {migration}.', ['migration' => $label]);
			}

			return $applied;
		} finally {
			$this->db->fetchValue('SELECT RELEASE_LOCK(%s)', [$lock]);
		}
	}

	/** Nazwa blokady — unikalna dla bazy i prefiksu (współdzielony serwer MySQL). */
	public function lockName(): string
	{
		return 'osf_seo_migrate_' . substr(md5($this->db->databaseName() . '|' . $this->db->prefix()), 0, 16);
	}

	/**
	 * @return list<Migration>
	 */
	private function pendingAfter(int $version): array
	{
		return array_values(array_filter(
			$this->migrations,
			static fn (Migration $migration): bool => $migration->version() > $version,
		));
	}

	private function storedVersion(): int
	{
		wp_cache_delete(self::OPTION_VERSION, 'options');
		wp_cache_delete('alloptions', 'options');

		return (int) $this->db->fetchValue(
			"SELECT option_value FROM {$this->db->optionsTable()} WHERE option_name = %s",
			[self::OPTION_VERSION],
		);
	}
}
