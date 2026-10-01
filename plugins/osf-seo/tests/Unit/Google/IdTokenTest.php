<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Google;

use OsfSeo\Google\IdToken;
use OsfSeo\Google\OAuthFlowException;
use OsfSeo\Tests\Support\GoogleFakes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdTokenTest extends TestCase
{
	private const NOW = 1_768_478_400;

	public function test_valid_token_returns_identity(): void
	{
		$jwt = GoogleFakes::idToken(self::NOW, ['sub' => '1000555', 'email' => 'seo@example.test']);

		self::assertSame(['sub' => '1000555', 'email' => 'seo@example.test'], IdToken::identity($jwt, GoogleFakes::CLIENT_ID, self::NOW));
	}

	public function test_audience_list_and_short_issuer_are_accepted(): void
	{
		$jwt = GoogleFakes::idToken(self::NOW, ['aud' => ['other', GoogleFakes::CLIENT_ID], 'iss' => 'accounts.google.com', 'sub' => '42']);

		self::assertSame('42', IdToken::identity($jwt, GoogleFakes::CLIENT_ID, self::NOW)['sub']);
	}

	public function test_small_clock_skew_is_tolerated(): void
	{
		$jwt = GoogleFakes::idToken(self::NOW, ['exp' => self::NOW - 120]);

		self::assertNotSame('', IdToken::identity($jwt, GoogleFakes::CLIENT_ID, self::NOW)['sub']);
	}

	/**
	 * @param array<string, mixed> $claims
	 */
	#[DataProvider('invalidClaims')]
	public function test_invalid_claims_are_rejected(array $claims): void
	{
		$this->expectException(OAuthFlowException::class);

		IdToken::identity(GoogleFakes::idToken(self::NOW, $claims), GoogleFakes::CLIENT_ID, self::NOW);
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function invalidClaims(): iterable
	{
		yield 'other audience' => [['aud' => 'someone-else.apps.example.test']];
		yield 'other issuer' => [['iss' => 'https://evil.example']];
		yield 'expired' => [['exp' => self::NOW - 3600]];
		yield 'missing sub' => [['sub' => '']];
		yield 'non-string sub' => [['sub' => 12345]];
		yield 'sub with spaces' => [['sub' => 'a b']];
	}

	#[DataProvider('malformedTokens')]
	public function test_malformed_tokens_are_rejected(string $jwt): void
	{
		try {
			IdToken::identity($jwt, GoogleFakes::CLIENT_ID, self::NOW);
			self::fail('Expected OAuthFlowException.');
		} catch (OAuthFlowException $exception) {
			self::assertSame(OAuthFlowException::ID_TOKEN_INVALID, $exception->reason());
		}
	}

	/** @return iterable<string, array{string}> */
	public static function malformedTokens(): iterable
	{
		yield 'empty' => [''];
		yield 'two parts' => ['a.b'];
		yield 'payload not json' => ['eyJhbGciOiJub25lIn0.bm90LWpzb24.c2ln'];
		yield 'payload not base64url' => ['a.b+c.d'];
	}
}
