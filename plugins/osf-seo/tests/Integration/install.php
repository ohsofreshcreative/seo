<?php

/**
 * Instaluje WordPress w testowej bazie, jeśli nie jest zainstalowany.
 * Uruchamiane w osobnym procesie (WP_INSTALLING zmienia zachowanie WordPressa na cały proces).
 */

declare(strict_types=1);

define('WP_INSTALLING', true);

require __DIR__ . '/wp-tests-config.php';
require ABSPATH . 'wp-settings.php';

if (is_blog_installed()) {
	exit(0);
}

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

add_filter('pre_wp_mail', '__return_true');

wp_install('OSF SEO tests', 'osf-test-admin', 'admin@osf-seo.test', false, '', wp_generate_password(32));

exit(is_blog_installed() ? 0 : 1);
