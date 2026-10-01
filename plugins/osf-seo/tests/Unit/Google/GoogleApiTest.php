<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Google;

use InvalidArgumentException;
use OsfSeo\Google\GoogleApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleApiTest extends TestCase
{
	public function test_google_api_hosts_are_allowed(): void
	{
		GoogleApi::assertGoogleApiUrl('https://searchconsole.googleapis.com/webmasters/v3/sites');
		GoogleApi::assertGoogleApiUrl('https://www.googleapis.com/webmasters/v3/sites');
		$this->addToAssertionCount(2);
	}

	#[DataProvider('foreignUrls')]
	public function test_bearer_token_is_never_sent_elsewhere(string $url): void
	{
		$this->expectException(InvalidArgumentException::class);

		GoogleApi::assertGoogleApiUrl($url);
	}

	/** @return iterable<string, array{string}> */
	public static function foreignUrls(): iterable
	{
		yield 'plain http' => ['http://searchconsole.googleapis.com/webmasters/v3/sites'];
		yield 'other domain' => ['https://evil.example/webmasters/v3/sites'];
		yield 'suffix trick' => ['https://googleapis.com.evil.example/x'];
		yield 'bare domain' => ['https://googleapis.com/x'];
		yield 'userinfo' => ['https://user@searchconsole.googleapis.com/x'];
		yield 'custom port' => ['https://searchconsole.googleapis.com:8443/x'];
		yield 'relative' => ['/webmasters/v3/sites'];
	}
}
