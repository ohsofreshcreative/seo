<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Klucz podstrony do grupowania: adres z GSC bez fragmentu `#…`.
 *
 * GSC raportuje osobno adresy z kotwicą (np. linki do sekcji w wynikach), choć to ta sama podstrona —
 * bez scalenia jedna podstrona wyglądałaby jak „kilka adresów” (fałszywa kanibalizacja).
 * Poza fragmentem adres pozostaje dokładnie taki jak w GSC (parametry, wielkość liter, ukośnik).
 */
final class UrlKey
{
	public static function normalize(string $url): string
	{
		$position = strpos($url, '#');

		return $position === false ? $url : substr($url, 0, $position);
	}

	public static function hash(string $url): string
	{
		return md5(self::normalize($url), true);
	}
}
