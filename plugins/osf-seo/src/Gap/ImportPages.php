<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Arytmetyka stron importu: liczba żądań i elementów dla limitu fraz (strona = do 1000 fraz).
 */
final class ImportPages
{
	public const PAGE = 1000;

	/** Liczba żądań dla N fraz (co najmniej jedno — także pusty zbiór kosztuje żądanie). */
	public static function requests(int $rows, int $page = self::PAGE): int
	{
		return max(1, (int) ceil(max(0, $rows) / max(1, $page)));
	}
}
