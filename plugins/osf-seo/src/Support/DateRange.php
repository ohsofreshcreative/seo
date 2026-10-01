<?php

declare(strict_types=1);

namespace OsfSeo\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Zakres dat kalendarzowych (włącznie), np. daty GSC. Same daty Y-m-d — bez stref czasowych
 * i bez przeliczania (arytmetyka na północy UTC tylko jako licznik dni).
 */
final class DateRange
{
	public function __construct(
		public readonly string $start,
		public readonly string $end,
	) {
		if (! self::isDate($start) || ! self::isDate($end)) {
			throw new InvalidArgumentException('Dates must be valid Y-m-d values.');
		}

		if ($start > $end) {
			throw new InvalidArgumentException('Range start must not be after its end.');
		}
	}

	public static function isDate(string $value): bool
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return false;
		}

		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

		return $date !== false && $date->format('Y-m-d') === $value;
	}

	/** Liczba dni w zakresie (włącznie z końcami). */
	public function days(): int
	{
		return self::diffDays($this->start, $this->end) + 1;
	}

	public function contains(string $date): bool
	{
		return $date >= $this->start && $date <= $this->end;
	}

	/** Data przesunięta o $days dni (ujemne — wstecz). */
	public static function shift(string $date, int $days): string
	{
		return self::parse($date)->modify(sprintf('%+d days', $days))->format('Y-m-d');
	}

	/** Liczba dni od $from do $to (ujemna, gdy $to jest wcześniej). */
	public static function diffDays(string $from, string $to): int
	{
		return (int) round((self::parse($to)->getTimestamp() - self::parse($from)->getTimestamp()) / 86400);
	}

	/** Okres o tej samej długości bezpośrednio przed tym zakresem. */
	public function previous(): self
	{
		return new self(self::shift($this->start, -$this->days()), self::shift($this->start, -1));
	}

	public function equals(self $other): bool
	{
		return $this->start === $other->start && $this->end === $other->end;
	}

	public function __toString(): string
	{
		return $this->start . '..' . $this->end;
	}

	private static function parse(string $date): DateTimeImmutable
	{
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

		if ($parsed === false) {
			throw new InvalidArgumentException('Invalid date.');
		}

		return $parsed;
	}
}
