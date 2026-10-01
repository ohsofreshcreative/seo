<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Support\Base64Url;

/** PKCE (RFC 7636), metoda S256. */
final class Pkce
{
	/** 32 losowe bajty → 43 znaki (minimum RFC 7636). */
	public static function verifier(): string
	{
		return Base64Url::encode(random_bytes(32));
	}

	public static function challenge(#[\SensitiveParameter] string $verifier): string
	{
		return Base64Url::encode(hash('sha256', $verifier, true));
	}
}
