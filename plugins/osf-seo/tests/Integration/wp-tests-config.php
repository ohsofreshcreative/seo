<?php

/**
 * Konfiguracja WordPressa dla testów integracyjnych — wyłącznie ze zmiennych środowiskowych.
 *
 * Testy działają na OSOBNEJ bazie (tabele są czyszczone i usuwane). Nigdy nie wskazuj tu bazy
 * strony. Domyślne wartości odpowiadają kontenerowi bazy w CI (root bez hasła, 127.0.0.1).
 *
 *   OSF_SEO_TEST_DB_NAME      (domyślnie osf_seo_test)
 *   OSF_SEO_TEST_DB_USER      (domyślnie root)
 *   OSF_SEO_TEST_DB_PASSWORD  (domyślnie pusty)
 *   OSF_SEO_TEST_DB_HOST      (domyślnie 127.0.0.1; socket: localhost:/ścieżka/mysqld.sock)
 *   OSF_SEO_TEST_WP_DIR       (domyślnie vendor/johnpbloch/wordpress-core)
 */

declare(strict_types=1);

$osfSeoTestEnv = static function (string $name, string $default): string {
	$value = getenv($name);

	return is_string($value) && $value !== '' ? $value : $default;
};

$osfSeoTestWpDir = rtrim($osfSeoTestEnv('OSF_SEO_TEST_WP_DIR', dirname(__DIR__, 2) . '/vendor/johnpbloch/wordpress-core'), '/');

if (! is_file($osfSeoTestWpDir . '/wp-settings.php')) {
	fwrite(STDERR, "WordPress core not found in {$osfSeoTestWpDir}. Run `composer install` or set OSF_SEO_TEST_WP_DIR.\n");
	exit(1);
}

define('ABSPATH', $osfSeoTestWpDir . '/');
define('DB_NAME', $osfSeoTestEnv('OSF_SEO_TEST_DB_NAME', 'osf_seo_test'));
define('DB_USER', $osfSeoTestEnv('OSF_SEO_TEST_DB_USER', 'root'));
define('DB_PASSWORD', $osfSeoTestEnv('OSF_SEO_TEST_DB_PASSWORD', ''));
define('DB_HOST', $osfSeoTestEnv('OSF_SEO_TEST_DB_HOST', '127.0.0.1'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Sole tylko dla procesu testów — losowe przy każdym uruchomieniu.
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $osfSeoTestSalt) {
	define($osfSeoTestSalt, bin2hex(random_bytes(32)));
}

// wp-settings.php kopiuje LOKALNĄ zmienną $table_prefix do $GLOBALS — PHPUnit dołącza bootstrap
// w zakresie metody, więc ustawiamy obie.
$table_prefix = 'osftest_';
$GLOBALS['table_prefix'] = $table_prefix;

define('WP_HOME', 'http://osf-seo.test');
define('WP_SITEURL', 'http://osf-seo.test');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', true);
define('WP_DEBUG_LOG', false);
define('WP_ENVIRONMENT_TYPE', 'local');
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
// Logger pluginu z kontenera — tylko błędy (testy logowania używają własnego loggera).
define('OSF_SEO_LOG_LEVEL', 'error');

$_SERVER['HTTP_HOST'] = 'osf-seo.test';
$_SERVER['SERVER_NAME'] = 'osf-seo.test';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

unset($osfSeoTestEnv, $osfSeoTestWpDir, $osfSeoTestSalt);
