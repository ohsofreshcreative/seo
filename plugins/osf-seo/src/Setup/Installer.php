<?php

declare(strict_types=1);

namespace OsfSeo\Setup;

use OsfSeo\Auth\RoleManager;
use OsfSeo\Database\MigrationLocked;
use OsfSeo\Database\Migrator;
use OsfSeo\Plugin;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Instalacja i aktualizacja pluginu: migracje schematu + synchronizacja ról.
 * Idempotentna — bezpieczna przy ponownej aktywacji i przy wielu równoległych żądaniach
 * tuż po wdrożeniu nowej wersji. Niczego nie usuwa.
 */
final class Installer
{
	public const OPTION_VERSION = 'osf_seo_version';

	public const OPTION_INSTALLED_AT = 'osf_seo_installed_at';

	/** Po nieudanej automatycznej aktualizacji kolejna próba najwcześniej po tym czasie. */
	private const BACKOFF_TRANSIENT = 'osf_seo_upgrade_backoff';

	private const BACKOFF_SECONDS = 300;

	public function __construct(
		private readonly RoleManager $roles,
		private readonly Migrator $migrator,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Aktywacja i wywołania jawne (CLI): błąd migracji przerywa operację (wyjątek).
	 */
	public function install(): void
	{
		$applied = $this->migrator->migrate();
		$changes = $this->roles->sync();

		add_option(self::OPTION_INSTALLED_AT, gmdate('Y-m-d\TH:i:s\Z'), '', false);
		update_option(self::OPTION_VERSION, Plugin::VERSION, true);
		delete_transient(self::BACKOFF_TRANSIENT);

		$this->logger->info('OSF SEO {version} installed or upgraded.', [
			'version' => Plugin::VERSION,
			'migrations' => $applied,
			'role_changes' => $changes,
		]);
	}

	public function needsUpgrade(): bool
	{
		return $this->installedVersion() !== Plugin::VERSION
			|| $this->migrator->currentVersion() < $this->migrator->latestVersion();
	}

	/**
	 * Wywoływane przy starcie pluginu. WordPress nie uruchamia hooka aktywacji przy podmianie
	 * plików (deploy), dlatego zmianę wersji pluginu lub schematu wykrywamy tutaj — kosztem
	 * odczytu dwóch opcji autoload. Błąd nie może zatrzymać obsługi żądania: zapisujemy go
	 * w logu i ponawiamy dopiero po czasie BACKOFF_SECONDS.
	 */
	public function maybeUpgrade(): void
	{
		if (! $this->needsUpgrade() || get_transient(self::BACKOFF_TRANSIENT) !== false) {
			return;
		}

		try {
			$this->install();
		} catch (MigrationLocked) {
			// Inny proces właśnie aktualizuje — dokończy pracę.
		} catch (Throwable $exception) {
			set_transient(self::BACKOFF_TRANSIENT, '1', self::BACKOFF_SECONDS);
			$this->logger->error('Automatic OSF SEO upgrade failed: {message}', [
				'message' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}
	}

	public function installedVersion(): ?string
	{
		$version = get_option(self::OPTION_VERSION);

		return is_string($version) && $version !== '' ? $version : null;
	}
}
