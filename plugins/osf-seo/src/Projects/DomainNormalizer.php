<?php

declare(strict_types=1);

namespace OsfSeo\Projects;

use InvalidArgumentException;

/**
 * Normalizuje domenę projektu do postaci `example.pl`:
 * małe litery, bez protokołu, `www.`, portu, ścieżki, parametrów i kropki na końcu;
 * akceptuje też property GSC (`sc-domain:example.pl`) i domeny IDN (zapis punycode).
 * Odrzuca adresy IP, localhost i nazwy bez domeny najwyższego poziomu.
 */
final class DomainNormalizer
{
	public static function normalize(string $input): string
	{
		$value = strtolower(trim($input));
		$value = (string) preg_replace('/^sc-domain:/', '', $value);

		if (str_contains($value, '://')) {
			$host = parse_url($value, PHP_URL_HOST);
			$value = is_string($host) ? $host : '';
		} else {
			$value = (string) preg_split('/[\/?#]/', $value, 2)[0];
		}

		$value = (string) preg_replace('/^[^@]*@/', '', $value);
		$value = (string) preg_replace('/:\d*$/', '', $value);
		$value = rtrim($value, '.');
		$value = (string) preg_replace('/^www\./', '', $value);

		if ($value !== '' && preg_match('/[^\x20-\x7e]/', $value) === 1) {
			$value = self::toAscii($value);
		}

		if (! self::isValidHostname($value)) {
			throw new InvalidArgumentException('Invalid domain.');
		}

		return $value;
	}

	private static function toAscii(string $value): string
	{
		if (! function_exists('idn_to_ascii')) {
			return '';
		}

		$ascii = idn_to_ascii(mb_strtolower($value, 'UTF-8'), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

		return is_string($ascii) ? $ascii : '';
	}

	private static function isValidHostname(string $value): bool
	{
		if ($value === '' || strlen($value) > 253) {
			return false;
		}

		$labels = explode('.', $value);

		if (count($labels) < 2) {
			return false;
		}

		foreach ($labels as $label) {
			if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
				return false;
			}
		}

		// TLD nie może być liczbą (odrzuca adresy IPv4).
		return preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', end($labels)) === 1;
	}
}
