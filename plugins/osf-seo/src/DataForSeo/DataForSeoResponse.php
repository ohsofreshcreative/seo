<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;

/**
 * Wspólne odczytywanie odpowiedzi DataForSEO (metryki rynkowe i wykrywanie fraz): jedno zadanie w kopercie,
 * błąd zadania, koszt i bezpieczna konwersja wartości (spoza typu lub zakresu → null = brak danych).
 */
final class DataForSeoResponse
{
	/**
	 * Wysyłamy jedno zadanie na żądanie — odpowiedź musi zawierać dokładnie jedno zadanie ze statusem.
	 *
	 * @param array<string, mixed> $envelope
	 * @return array<string, mixed> zadanie z całkowitym `status_code`
	 */
	public static function singleTask(array $envelope): array
	{
		$tasks = $envelope['tasks'];
		$task = is_array($tasks) && count($tasks) === 1 ? reset($tasks) : null;

		if (! is_array($task) || ! is_int($task['status_code'] ?? null)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO response does not contain exactly one task.');
		}

		return $task;
	}

	/**
	 * @param array<string, mixed> $task
	 */
	public static function taskError(array $task): ProviderException
	{
		$code = (int) $task['status_code'];

		return new ProviderException(DataForSeoStatus::category($code, true), DataForSeoClient::message($code, $task['status_message'] ?? null), $code);
	}

	/**
	 * @param array<string, mixed> $task
	 */
	public static function cost(array $task): ?float
	{
		return self::nonNegativeFloat($task['cost'] ?? null);
	}

	/**
	 * Historia miesięczna (`monthly_searches`): miesiąc (RRRR-MM-01) → wolumen, rosnąco.
	 *
	 * @return list<array{month: string, search_volume: ?int}>
	 */
	public static function monthly(mixed $monthly): array
	{
		if (! is_array($monthly)) {
			return [];
		}

		$months = [];

		foreach ($monthly as $row) {
			$year = is_array($row) ? self::bounded($row['year'] ?? null, 2000, 2100) : null;
			$month = is_array($row) ? self::bounded($row['month'] ?? null, 1, 12) : null;

			if ($year !== null && $month !== null) {
				$months[sprintf('%04d-%02d-01', $year, $month)] = self::nonNegativeInt($row['search_volume'] ?? null);
			}
		}

		ksort($months);

		return array_map(
			static fn (string $date, ?int $volume): array => ['month' => $date, 'search_volume' => $volume],
			array_keys($months),
			array_values($months),
		);
	}

	public static function nonNegativeInt(mixed $value): ?int
	{
		if (is_float($value) && floor($value) === $value) {
			$value = (int) $value;
		}

		return is_int($value) && $value >= 0 && $value <= 4294967295 ? $value : null;
	}

	public static function nonNegativeFloat(mixed $value): ?float
	{
		return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value < 100000000 ? (float) $value : null;
	}

	public static function bounded(mixed $value, int $min, int $max): ?int
	{
		if (is_float($value) && is_finite($value)) {
			$value = (int) round($value);
		}

		return is_int($value) && $value >= $min && $value <= $max ? $value : null;
	}

	public static function nonEmptyString(mixed $value, int $maxLength = 255): ?string
	{
		return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $maxLength, 'UTF-8') : null;
	}
}
