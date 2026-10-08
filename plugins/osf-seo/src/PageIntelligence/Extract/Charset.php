<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Extract;

/**
 * Kodowanie dokumentu HTML: nagłówek Content-Type → BOM → `<meta charset>` / `http-equiv` (pierwsze 4 KB) → poprawny UTF-8 →
 * Windows-1250 (najczęstsze dla stron polskich bez deklaracji). Konwersja do UTF-8 z usunięciem niepoprawnych sekwencji.
 */
final class Charset
{
	private const ALIASES = [
		'utf8' => 'UTF-8',
		'utf-8' => 'UTF-8',
		'iso-8859-2' => 'ISO-8859-2',
		'iso8859-2' => 'ISO-8859-2',
		'latin2' => 'ISO-8859-2',
		'windows-1250' => 'Windows-1250',
		'cp1250' => 'Windows-1250',
		'x-cp1250' => 'Windows-1250',
		'iso-8859-1' => 'Windows-1252',
		'latin1' => 'Windows-1252',
		'us-ascii' => 'Windows-1252',
		'windows-1252' => 'Windows-1252',
		'iso-8859-15' => 'ISO-8859-15',
		'utf-16' => 'UTF-16',
		'utf-16le' => 'UTF-16LE',
		'utf-16be' => 'UTF-16BE',
	];

	/**
	 * @return array{0: string, 1: string} [tekst UTF-8, użyte kodowanie]
	 */
	public static function toUtf8(string $html, ?string $declared): array
	{
		$charset = self::detect($html, $declared);

		if (str_starts_with($html, "\xEF\xBB\xBF")) {
			$html = substr($html, 3);
		}

		if ($charset !== 'UTF-8') {
			$html = self::convert($html, $charset);
		}

		// Deklaracje kodowania w dokumencie po konwersji wskazują UTF-8 (libxml przełączyłby dekodowanie na zadeklarowane kodowanie).
		$html = (string) preg_replace('/(<meta\b[^>]*?charset\s*=\s*["\']?\s*)[A-Za-z0-9._:\-]+/i', '${1}utf-8', mb_scrub($html, 'UTF-8'));

		return [$html, $charset];
	}

	/** mbstring, a gdy nie obsługuje kodowania (np. Windows-1250) — iconv; niekonwertowalne znaki pomijane. */
	private static function convert(string $html, string $charset): string
	{
		if (self::mbSupports($charset)) {
			try {
				$converted = mb_convert_encoding($html, 'UTF-8', $charset);

				if (is_string($converted)) {
					return $converted;
				}
			} catch (\ValueError) {
			}
		}

		if (function_exists('iconv')) {
			$converted = @iconv($charset, 'UTF-8//IGNORE', $html);

			if (is_string($converted)) {
				return $converted;
			}
		}

		return $html;
	}

	private static function mbSupports(string $charset): bool
	{
		return in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true);
	}

	public static function detect(string $html, ?string $declared): string
	{
		$normalized = self::normalize($declared);

		if ($normalized !== null) {
			return $normalized;
		}

		if (str_starts_with($html, "\xEF\xBB\xBF")) {
			return 'UTF-8';
		}

		if (str_starts_with($html, "\xFF\xFE")) {
			return 'UTF-16LE';
		}

		if (str_starts_with($html, "\xFE\xFF")) {
			return 'UTF-16BE';
		}

		$head = substr($html, 0, 4096);

		if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([A-Za-z0-9._:\-]{1,40})/i', $head, $match) === 1) {
			$fromMeta = self::normalize($match[1]);

			if ($fromMeta !== null) {
				return $fromMeta;
			}
		}

		return mb_check_encoding($html, 'UTF-8') ? 'UTF-8' : 'Windows-1250';
	}

	private static function normalize(?string $charset): ?string
	{
		if ($charset === null || trim($charset) === '') {
			return null;
		}

		$charset = strtolower(trim($charset));

		if (isset(self::ALIASES[$charset])) {
			return self::ALIASES[$charset];
		}

		return self::mbSupports($charset) || function_exists('iconv') && @iconv($charset, 'UTF-8', 'a') !== false ? strtoupper($charset) : null;
	}
}
