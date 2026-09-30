<?php

/**
 * Bootstrap testów integracyjnych: prawdziwy WordPress + prawdziwa baza MySQL/MariaDB.
 * Konfiguracja: tests/Integration/wp-tests-config.php (zmienne środowiskowe).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/install.php'), $osfSeoInstallCode);

if ($osfSeoInstallCode !== 0) {
	fwrite(STDERR, "WordPress test installation failed (see output above). Check OSF_SEO_TEST_DB_* variables.\n");
	exit(1);
}

require __DIR__ . '/wp-tests-config.php';

// Nieprawidłowe użycie API WordPressa (_doing_it_wrong, przestarzałe funkcje) oblewa test —
// IntegrationTestCase sprawdza tę listę po każdym teście.
$GLOBALS['osf_seo_test_wp_notices'] = [];

$GLOBALS['wp_filter']['doing_it_wrong_run'][10][] = [
	'function' => static function (string $function, string $message): void {
		$GLOBALS['osf_seo_test_wp_notices'][] = "_doing_it_wrong: {$function}: {$message}";
	},
	'accepted_args' => 2,
];
$GLOBALS['wp_filter']['deprecated_function_run'][10][] = [
	'function' => static function (string $function): void {
		$GLOBALS['osf_seo_test_wp_notices'][] = "deprecated function: {$function}";
	},
	'accepted_args' => 1,
];

require ABSPATH . 'wp-settings.php';

// Plugin ładujemy ręcznie (nie jest aktywny w testowej instalacji) — hook plugins_loaded już minął,
// więc testy wywołują potrzebne kroki (boot, migracje) jawnie.
require dirname(__DIR__, 2) . '/osf-seo.php';

require_once ABSPATH . 'wp-admin/includes/user.php';
