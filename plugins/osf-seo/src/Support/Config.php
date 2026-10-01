<?php

declare(strict_types=1);

namespace OsfSeo\Support;

/**
 * Odczyt konfiguracji środowiska: stała z wp-config.php ma pierwszeństwo przed zmienną
 * środowiskową o tej samej nazwie. Sekrety (np. OSF_SEO_GOOGLE_CLIENT_SECRET) konfigurujemy
 * wyłącznie w ten sposób — nigdy w repozytorium ani w bazie danych.
 */
final class Config
{
	public function get(string $name, ?string $default = null): ?string
	{
		if (defined($name)) {
			$value = constant($name);

			return is_scalar($value) ? (string) $value : $default;
		}

		$value = getenv($name);

		return is_string($value) && $value !== '' ? $value : $default;
	}
}
