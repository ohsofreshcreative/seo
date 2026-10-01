<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Support;

use InvalidArgumentException;
use OsfSeo\Gsc\GscCalendar;
use OsfSeo\Support\DateRange;
use OsfSeo\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
	public function test_days_shift_and_previous_period(): void
	{
		$range = new DateRange('2026-09-01', '2026-09-28');

		self::assertSame(28, $range->days());
		self::assertSame('2026-08-04..2026-08-31', (string) $range->previous());
		self::assertSame(28, $range->previous()->days());
		self::assertTrue($range->contains('2026-09-28'));
		self::assertFalse($range->contains('2026-09-29'));
		self::assertSame('2026-03-01', DateRange::shift('2026-02-28', 1));
		self::assertSame('2024-02-29', DateRange::shift('2024-03-01', -1));
		self::assertSame(-31, DateRange::diffDays('2026-04-01', '2026-03-01'));
	}

	public function test_dst_changes_do_not_shift_dates(): void
	{
		// Zmiany czasu (marzec/październik/listopad) nie mogą gubić ani dublować dni.
		self::assertSame('2026-03-09', DateRange::shift('2026-03-08', 1));
		self::assertSame('2026-11-02', DateRange::shift('2026-11-01', 1));
		self::assertSame(365, (new DateRange('2026-01-01', '2026-12-31'))->days());
	}

	public function test_invalid_ranges_are_rejected(): void
	{
		foreach ([['2026-09-02', '2026-09-01'], ['2026-13-01', '2026-13-02'], ['2026-9-1', '2026-09-02'], ['', '']] as [$start, $end]) {
			try {
				new DateRange($start, $end);
				self::fail("Zakres {$start}..{$end} powinien być odrzucony.");
			} catch (InvalidArgumentException) {
			}
		}

		self::assertTrue(true);
	}

	public function test_gsc_calendar_uses_pacific_time(): void
	{
		// 07:30 UTC = 23:30 poprzedniego dnia w Los Angeles (PST, UTC−8).
		self::assertSame('2026-01-14', (new GscCalendar(new FrozenClock('2026-01-15 07:30:00')))->today());
		self::assertSame('2026-01-15', (new GscCalendar(new FrozenClock('2026-01-15 08:30:00')))->today());
		// Lato (PDT, UTC−7): 06:59 UTC to jeszcze poprzedni dzień.
		self::assertSame('2026-07-01', (new GscCalendar(new FrozenClock('2026-07-02 06:59:00')))->today());
		self::assertSame('2026-07-01', (new GscCalendar(new FrozenClock('2026-07-02 07:00:00')))->yesterday());
	}
}
