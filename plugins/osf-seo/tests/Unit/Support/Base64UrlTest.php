<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Support;

use OsfSeo\Support\Base64Url;
use PHPUnit\Framework\TestCase;

final class Base64UrlTest extends TestCase
{
	public function test_round_trip_without_padding_and_url_safe_alphabet(): void
	{
		for ($length = 0; $length < 40; $length++) {
			$bytes = $length === 0 ? '' : random_bytes($length);
			$encoded = Base64Url::encode($bytes);

			self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]*$/', $encoded);
			self::assertSame($bytes, Base64Url::decode($encoded));
		}

		self::assertSame('-_8', Base64Url::encode("\xfb\xff"));
	}

	public function test_invalid_input_is_rejected(): void
	{
		self::assertNull(Base64Url::decode('ab+c'));
		self::assertNull(Base64Url::decode('ab/c'));
		self::assertNull(Base64Url::decode('abc='));
		self::assertNull(Base64Url::decode('a'));
		self::assertNull(Base64Url::decode('ab c'));
	}
}
