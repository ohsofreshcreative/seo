<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Support;

use OsfSeo\Support\Redactor;
use OsfSeo\Tests\Support\SecretSamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stringable;

final class RedactorTest extends TestCase
{
	/**
	 * @return iterable<string, array{string}>
	 */
	public static function sensitiveKeys(): iterable
	{
		foreach (['access_token', 'Refresh_Token', 'client_secret', 'Authorization', 'password', 'code', 'code_verifier', 'id_token', 'private_key', 'encryption_key', 'api_key', 'cookie', 'state', 'State'] as $key) {
			yield $key => [$key];
		}

		yield 'suffix _token' => ['google_token'];
		yield 'suffix _secret' => ['oauth_client_secret'];
		yield 'suffix _password' => ['db_password'];
	}

	#[DataProvider('sensitiveKeys')]
	public function test_values_under_sensitive_keys_are_masked(string $key): void
	{
		$result = (new Redactor())->context([$key => 'plain-value-123']);

		self::assertSame(Redactor::MASK, $result[$key]);
	}

	public function test_regular_keys_are_left_intact(): void
	{
		$context = ['keyword' => 'agencja wordpress', 'error_code' => 'quotaExceeded', 'status_code' => 429, 'cache_key' => 'kw:1', 'clicks' => 48];

		self::assertSame($context, (new Redactor())->context($context));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function secretShapedValues(): iterable
	{
		yield 'Google access token' => [SecretSamples::googleAccessToken()];
		yield 'Google refresh token' => [SecretSamples::googleRefreshToken()];
		yield 'Google client secret' => [SecretSamples::googleClientSecret()];
		yield 'JWT' => [SecretSamples::jwt()];
		yield 'private key' => [SecretSamples::privateKey()];
	}

	#[DataProvider('secretShapedValues')]
	public function test_secret_shaped_values_are_masked_in_strings(string $secret): void
	{
		$redacted = (new Redactor())->string('before ' . $secret . ' after');

		self::assertStringNotContainsString($secret, $redacted);
		self::assertStringContainsString(Redactor::MASK, $redacted);
		self::assertStringStartsWith('before ', $redacted);
	}

	public function test_bearer_authorization_header_is_masked(): void
	{
		$redacted = (new Redactor())->string('Authorization: Bearer abcDEF123456.xyz');

		self::assertSame('Authorization: ' . Redactor::MASK, $redacted);
	}

	public function test_nested_context_is_redacted(): void
	{
		$result = (new Redactor())->context([
			'response' => ['body' => ['refresh_token' => 'r', 'note' => 'token ' . SecretSamples::googleAccessToken()]],
		]);

		self::assertSame(Redactor::MASK, $result['response']['body']['refresh_token']);
		self::assertSame('token ' . Redactor::MASK, $result['response']['body']['note']);
	}

	public function test_exceptions_are_reduced_to_class_redacted_message_and_code(): void
	{
		$exception = new RuntimeException('failed with ' . SecretSamples::googleClientSecret(), 7);

		$result = (new Redactor())->context(['exception' => $exception]);

		self::assertSame(
			['exception' => RuntimeException::class, 'message' => 'failed with ' . Redactor::MASK, 'code' => 7],
			$result['exception'],
		);
	}

	public function test_objects_are_not_serialized(): void
	{
		$stringable = new class implements Stringable {
			public function __toString(): string
			{
				return 'value ' . SecretSamples::googleAccessToken();
			}
		};

		$result = (new Redactor())->context(['object' => new stdClass(), 'stringable' => $stringable]);

		self::assertSame('[object stdClass]', $result['object']);
		self::assertSame('value ' . Redactor::MASK, $result['stringable']);
	}

	public function test_deep_nesting_is_cut_off(): void
	{
		$context = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => ['l7' => 'deep']]]]]]];

		$result = (new Redactor())->context($context);

		self::assertSame('[array]', $result['l1']['l2']['l3']['l4']['l5']['l6']);
	}
}
