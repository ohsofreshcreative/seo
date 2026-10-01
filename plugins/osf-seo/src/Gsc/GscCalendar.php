<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use DateTimeZone;
use OsfSeo\Support\Clock;
use OsfSeo\Support\DateRange;

/**
 * Kalendarz Search Console: daty raportów GSC są w czasie pacyficznym (America/Los_Angeles).
 * „Dziś” liczymy w tej strefie, a same daty zapisujemy i porównujemy bez przeliczania na Europe/Warsaw.
 */
final class GscCalendar
{
	public const TIMEZONE = 'America/Los_Angeles';

	public function __construct(private readonly Clock $clock)
	{
	}

	/** Bieżąca data w czasie pacyficznym (Y-m-d). */
	public function today(): string
	{
		return $this->clock->now()->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d');
	}

	/** Ostatni pełny dzień GSC. */
	public function yesterday(): string
	{
		return DateRange::shift($this->today(), -1);
	}
}
