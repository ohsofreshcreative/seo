<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Gsc\Dictionary;

/**
 * Formatowanie liczb w tekstach szans (konwencje polskie, jak App\Panel\Format w motywie —
 * plugin nie zależy od motywu). Teksty są zwykłym tekstem — escapuje je widok.
 */
final class Text
{
	private const NBSP = "\u{00A0}";

	public static function number(int|float|null $value, int $decimals = 0): string
	{
		return $value === null ? '—' : number_format((float) $value, $decimals, ',', self::NBSP);
	}

	public static function percent(?float $ratio, int $decimals = 1): string
	{
		return $ratio === null ? '—' : self::number($ratio * 100, $decimals) . self::NBSP . '%';
	}

	/** Zmiana względna ze znakiem, np. −35,0 %. */
	public static function relative(int|float $previous, int|float $current): string
	{
		if ($previous <= 0) {
			return '—';
		}

		$change = ($current - $previous) / $previous * 100;
		$formatted = self::number(abs($change), 1) . self::NBSP . '%';

		return match (true) {
			round($change, 1) > 0 => '+' . $formatted,
			round($change, 1) < 0 => '−' . $formatted,
			default => $formatted,
		};
	}

	public static function position(?float $position): string
	{
		return $position === null ? '—' : self::number($position, 1);
	}

	public static function date(string $date): string
	{
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $date;
	}

	/**
	 * @param array{0: string, 1: string} $range
	 */
	public static function range(array $range): string
	{
		return self::date($range[0]) . '–' . self::date($range[1]);
	}

	/** Ścieżka adresu do wyświetlenia (z parametrami, bez domeny). */
	public static function path(string $url): string
	{
		$path = Dictionary::path($url);

		return $path === '' ? $url : $path;
	}

	/** Odmiana: 1 fraza, 2–4 frazy, 5+ fraz (także 12–14 → fraz). */
	public static function plural(int $count, string $one, string $few, string $many): string
	{
		$mod10 = $count % 10;
		$mod100 = $count % 100;

		return match (true) {
			$count === 1 => $one,
			$mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => $few,
			default => $many,
		};
	}

	public static function keywords(int $count): string
	{
		return self::number($count) . self::NBSP . self::plural($count, 'fraza', 'frazy', 'fraz');
	}
}
