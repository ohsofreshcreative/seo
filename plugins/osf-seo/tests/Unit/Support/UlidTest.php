<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Support;

use DateTimeImmutable;
use OsfSeo\Support\Ulid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
	public function test_generated_ulid_has_valid_format(): void
	{
		$ulid = Ulid::generate();

		self::assertSame(26, strlen($ulid));
		self::assertTrue(Ulid::isValid($ulid));
	}

	public function test_generated_ulids_are_unique(): void
	{
		$ulids = array_map(static fn (): string => Ulid::generate(), range(1, 2000));

		self::assertCount(2000, array_unique($ulids));
	}

	public function test_time_component_sorts_chronologically(): void
	{
		$earlier = Ulid::generate(new DateTimeImmutable('2026-01-01 00:00:00.000 UTC'));
		$later = Ulid::generate(new DateTimeImmutable('2026-01-01 00:00:00.001 UTC'));

		self::assertLessThan(0, strcmp(substr($earlier, 0, 10), substr($later, 0, 10)));
	}

	public function test_known_timestamp_is_encoded_as_in_specification(): void
	{
		// Przykład z referencyjnej implementacji ULID: 1469918176385 ms → 01ARYZ6S41.
		$time = DateTimeImmutable::createFromFormat('U.v', '1469918176.385');

		self::assertSame('01ARYZ6S41', substr(Ulid::generate($time), 0, 10));
	}

	public function test_normalize_uppercases_and_trims(): void
	{
		self::assertSame('01ARYZ6S41TSV4RRFFQ69G5FAV', Ulid::normalize(' 01aryz6s41tsv4rrffq69g5fav '));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidValues(): iterable
	{
		yield 'raw numeric id' => ['12'];
		yield 'empty' => [''];
		yield 'too short' => ['01ARYZ6S41TSV4RRFFQ69G5FA'];
		yield 'too long' => ['01ARYZ6S41TSV4RRFFQ69G5FAVX'];
		yield 'forbidden letter I' => ['01ARYZ6S41TSV4RRFFQ69G5FAI'];
		yield 'overflow first char' => ['81ARYZ6S41TSV4RRFFQ69G5FAV'];
		yield 'sql injection' => ["01ARYZ6S41' OR '1'='1"];
		yield 'path' => ['../../01ARYZ6S41TSV4RRFFQ6'];
	}

	#[DataProvider('invalidValues')]
	public function test_invalid_values_are_rejected(string $value): void
	{
		self::assertNull(Ulid::normalize($value));
		self::assertFalse(Ulid::isValid($value));
	}
}
