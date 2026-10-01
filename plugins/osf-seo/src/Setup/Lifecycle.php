<?php

declare(strict_types=1);

namespace OsfSeo\Setup;

use OsfSeo\Sync\SyncScheduler;

/**
 * Hooki aktywacji i dezaktywacji.
 *
 * Dezaktywacja niczego nie usuwa: role, uprawnienia i opcje (a w przyszłości tabele
 * i połączenia Google) zostają nietknięte, więc ponowna aktywacja przywraca pełny stan.
 */
final class Lifecycle
{
	public static function activate(): void
	{
		osf_seo()->get(Installer::class)->install();
	}

	public static function deactivate(): void
	{
		// Zatrzymanie harmonogramu synchronizacji (zadania w kolejce zostają i wznowią się po aktywacji).
		SyncScheduler::unschedule();
		osf_seo()->logger()->info('OSF SEO deactivated; roles, options and data were preserved.');
	}
}
