<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Analytics;

use OsfSeo\Analytics\Metrics;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
	public function test_ctr_is_aggregate_clicks_over_impressions_not_average_of_ctr(): void
	{
		// Dzień 1: 1/1 = 100% CTR, dzień 2: 0/99 = 0% → średnia CTR 50%, prawdziwy CTR 1%.
		$rows = [[1, 1], [0, 99]];
		$clicks = array_sum(array_column($rows, 0));
		$impressions = array_sum(array_column($rows, 1));

		self::assertSame(0.01, Metrics::ctr($clicks, $impressions));
		self::assertNotSame(0.5, Metrics::ctr($clicks, $impressions));
		self::assertNull(Metrics::ctr(0, 0));
	}

	public function test_position_is_weighted_by_impressions_not_plain_average(): void
	{
		// Pozycja 1 przy 990 wyświetleniach i pozycja 50 przy 10 → ważona 1,49, a AVG(position) dałoby 25,5.
		$positionSum = Metrics::positionSum(1.0, 990) + Metrics::positionSum(50.0, 10);

		self::assertEqualsWithDelta(1.49, Metrics::position($positionSum, 1000), 1e-9);
		self::assertNull(Metrics::position(0.0, 0));
		self::assertSame(0.0, Metrics::positionSum(12.0, 0), 'Wiersz bez wyświetleń nie wpływa na średnią.');
	}

	public function test_position_change_is_previous_minus_current(): void
	{
		self::assertSame(8.0, Metrics::positionChange(15.0, 7.0), '15 → 7 to poprawa o 8 (wzrost).');
		self::assertSame(-3.0, Metrics::positionChange(4.0, 7.0), '4 → 7 to spadek.');
		self::assertNull(Metrics::positionChange(null, 7.0), 'Nowa fraza — bez zmiany.');
		self::assertNull(Metrics::positionChange(7.0, null), 'Utracona fraza — bez zmiany.');
	}

	public function test_changes_and_percentages(): void
	{
		self::assertSame(5, Metrics::change(10, 15));
		self::assertSame(50.0, Metrics::percentChange(10, 15));
		self::assertSame(-100.0, Metrics::percentChange(10, 0));
		self::assertNull(Metrics::percentChange(0, 15), 'Przy 0 w poprzednim okresie procent nie istnieje.');
	}
}
