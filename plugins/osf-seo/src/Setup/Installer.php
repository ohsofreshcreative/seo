<?php

declare(strict_types=1);

namespace OsfSeo\Setup;

use OsfSeo\Auth\RoleManager;
use OsfSeo\Plugin;
use OsfSeo\Support\Logger;

/**
 * Instalacja i aktualizacja pluginu. Idempotentna — bezpieczna przy ponownej aktywacji
 * i przy wielu równoległych żądaniach tuż po wdrożeniu nowej wersji.
 */
final class Installer
{
	public const OPTION_VERSION = 'osf_seo_version';

	public const OPTION_INSTALLED_AT = 'osf_seo_installed_at';

	public function __construct(
		private readonly RoleManager $roles,
		private readonly Logger $logger,
	) {
	}

	public function install(): void
	{
		$changes = $this->roles->sync();

		add_option(self::OPTION_INSTALLED_AT, gmdate('Y-m-d\TH:i:s\Z'), '', false);
		update_option(self::OPTION_VERSION, Plugin::VERSION, true);

		$this->logger->info('OSF SEO {version} installed or upgraded.', [
			'version' => Plugin::VERSION,
			'role_changes' => $changes,
		]);
	}

	/**
	 * WordPress nie uruchamia hooka aktywacji przy podmianie plików pluginu (deploy),
	 * dlatego zmianę wersji wykrywamy przy starcie — kosztem jednego odczytu opcji autoload.
	 */
	public function maybeUpgrade(): void
	{
		if ($this->installedVersion() !== Plugin::VERSION) {
			$this->install();
		}
	}

	public function installedVersion(): ?string
	{
		$version = get_option(self::OPTION_VERSION);

		return is_string($version) && $version !== '' ? $version : null;
	}
}
