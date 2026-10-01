<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Google;

use OsfSeo\Google\Pkce;
use PHPUnit\Framework\TestCase;

final class PkceTest extends TestCase
{
	/** RFC 7636, Appendix B. */
	public function test_s256_challenge_matches_rfc_7636_example(): void
	{
		self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
	}

	public function test_verifier_is_random_43_char_unreserved_string(): void
	{
		$verifiers = [];

		for ($i = 0; $i < 20; $i++) {
			$verifier = Pkce::verifier();
			self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $verifier);
			$verifiers[] = $verifier;
		}

		self::assertCount(20, array_unique($verifiers));
	}
}
