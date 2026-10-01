<?php

declare(strict_types=1);

namespace OsfSeo\Analytics;

/**
 * Pamięć podręczna wyników raportów (transienty WordPressa — bez Redis). Klucz zawiera stan danych
 * projektu (ostatni import `last_synced_at`, źródło danych, ostatnią datę), więc nowy import albo reset
 * danych zmienia klucz — nie ma potrzeby jawnego unieważniania. TTL ogranicza rozrost tabeli opcji.
 */
final class ReportCache
{
	public const TTL = 21600;

	/** Zmiana formatu wyników = nowa wersja (stare wpisy są ignorowane). */
	private const VERSION = 1;

	/**
	 * @template T
	 * @param list<scalar|null> $key
	 * @param callable(): T $compute
	 * @return T
	 */
	public function remember(array $key, callable $compute): mixed
	{
		$name = 'osf_seo_rc_' . md5((string) wp_json_encode([self::VERSION, ...$key]));
		$cached = get_transient($name);

		if (is_array($cached) && array_key_exists('data', $cached)) {
			return $cached['data'];
		}

		$data = $compute();
		set_transient($name, ['data' => $data], self::TTL);

		return $data;
	}
}
