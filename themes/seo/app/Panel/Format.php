<?php

namespace App\Panel;

/**
 * Formatowanie liczb i dat w panelu (konwencje polskie). Wyłącznie prezentacja — obliczenia są w pluginie.
 */
final class Format
{
	private const NBSP = "\u{00A0}";

	public static function number(int|float|null $value, int $decimals = 0): string
	{
		return $value === null ? '—' : number_format((float) $value, $decimals, ',', self::NBSP);
	}

	/** Liczba ze znakiem (+/−), np. zmiana kliknięć. */
	public static function signed(int|float|null $value, int $decimals = 0): string
	{
		if ($value === null) {
			return '—';
		}

		$formatted = self::number(abs($value), $decimals);

		return match (true) {
			round((float) $value, $decimals) > 0 => '+' . $formatted,
			round((float) $value, $decimals) < 0 => '−' . $formatted,
			default => $formatted,
		};
	}

	/** Udział (0–1) jako procent, np. CTR. */
	public static function percent(?float $ratio, int $decimals = 2): string
	{
		return $ratio === null ? '—' : self::number($ratio * 100, $decimals) . self::NBSP . '%';
	}

	/** Zmiana udziału w punktach procentowych (np. Δ CTR). */
	public static function points(?float $ratioChange, int $decimals = 2): string
	{
		return $ratioChange === null ? '—' : self::signed($ratioChange * 100, $decimals) . self::NBSP . 'pp';
	}

	/** Średnia pozycja (GSC) — jedno miejsce po przecinku. */
	public static function position(?float $position): string
	{
		return $position === null ? '—' : self::number($position, 1);
	}

	/** Data GSC (Y-m-d, czas pacyficzny) jako dd.mm.rrrr — bez przeliczania strefy. */
	public static function date(?string $date): string
	{
		if ($date === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
			return '—';
		}

		return $m[3] . '.' . $m[2] . '.' . $m[1];
	}

	/** Kwota w USD (CPC, koszt API DataForSEO). */
	public static function usd(?float $value, int $decimals = 2): string
	{
		return $value === null ? '—' : self::number($value, $decimals) . self::NBSP . 'USD';
	}

	/** Konkurencja Ads (płatne wyniki Google Ads) — nie mylić z trudnością SEO. */
	public static function adsCompetition(?string $level): string
	{
		return match ($level) {
			'low' => 'niska',
			'medium' => 'średnia',
			'high' => 'wysoka',
			default => '—',
		};
	}

	/** Miesiąc historii wolumenu (Y-m-01) jako mm.rrrr. */
	public static function month(?string $date): string
	{
		return $date !== null && preg_match('/^(\d{4})-(\d{2})/', $date, $m) === 1 ? $m[2] . '.' . $m[1] : '—';
	}

	/** Czas UTC z bazy w strefie WordPressa. */
	public static function datetime(?string $utc): string
	{
		if ($utc === null || $utc === '') {
			return '—';
		}

		$timestamp = strtotime($utc . ' UTC');

		return $timestamp === false ? '—' : wp_date('d.m.Y H:i', $timestamp);
	}
}
