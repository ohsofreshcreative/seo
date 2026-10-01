<?php

declare(strict_types=1);

namespace OsfSeo;

/**
 * Autoloader PSR-4 dla klas pluginu.
 *
 * Plugin nie wymaga `composer install` na serwerze — Composer służy wyłącznie narzędziom
 * deweloperskim (PHPUnit), więc wdrożenie nie zależy od dostępności Composera na hostingu.
 */
final class Autoloader
{
	public static function register(string $prefix, string $baseDirectory): void
	{
		spl_autoload_register(static function (string $class) use ($prefix, $baseDirectory): void {
			$file = self::pathFor($class, $prefix, $baseDirectory);

			if ($file !== null && is_file($file)) {
				require $file;
			}
		});
	}

	/**
	 * Ścieżka pliku klasy albo null, gdy klasa nie należy do przestrzeni nazw lub jej nazwa
	 * nie jest poprawnym identyfikatorem PHP (np. zawiera `..` — ochrona przed dołączeniem
	 * dowolnego pliku, gdy nazwa klasy pochodzi z danych wejściowych).
	 */
	public static function pathFor(string $class, string $prefix, string $baseDirectory): ?string
	{
		if (! str_starts_with($class, $prefix)) {
			return null;
		}

		$relative = substr($class, strlen($prefix));

		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relative) !== 1) {
			return null;
		}

		return rtrim($baseDirectory, '/\\') . '/' . str_replace('\\', '/', $relative) . '.php';
	}
}
