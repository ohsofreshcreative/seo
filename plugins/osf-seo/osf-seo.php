<?php

/**
 * Plugin Name:       OSF SEO
 * Plugin URI:        https://github.com/ohsofreshcreative/seo
 * Description:       Logika aplikacji OSF SEO: projekty, integracja z Google Search Console, synchronizacja i analityka.
 * Version:           0.7.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            OhSoFresh
 * Author URI:        https://ohsofresh.pl
 * Text Domain:       osf-seo
 * Update URI:        false
 */

// Ten plik musi się parsować także na starszym PHP, żeby zamiast błędu składni pokazać
// czytelny komunikat. Kod wymagający PHP 8.2 ładujemy dopiero po kontroli wersji.

if (! defined('ABSPATH')) {
	exit;
}

if (version_compare(PHP_VERSION, '8.2', '<')) {
	add_action('admin_notices', function () {
		echo '<div class="notice notice-error"><p>OSF SEO wymaga PHP 8.2 lub nowszego. Plugin nie został uruchomiony.</p></div>';
	});

	return;
}

define('OSF_SEO_FILE', __FILE__);

require_once __DIR__ . '/src/Autoloader.php';

OsfSeo\Autoloader::register('OsfSeo\\', __DIR__ . '/src');

require_once __DIR__ . '/src/functions.php';

register_activation_hook(__FILE__, [OsfSeo\Setup\Lifecycle::class, 'activate']);
register_deactivation_hook(__FILE__, [OsfSeo\Setup\Lifecycle::class, 'deactivate']);

add_action('plugins_loaded', function () {
	osf_seo()->boot();
});
