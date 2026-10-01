<?php

declare(strict_types=1);

namespace OsfSeo\Support;

/** Base64 URL-safe bez paddingu (RFC 4648 §5) — PKCE, state OAuth, JWT, koperty TokenVault. */
final class Base64Url
{
	public static function encode(string $bytes): string
	{
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}

	/** Ścisłe dekodowanie; null dla niepoprawnego wejścia. */
	public static function decode(string $value): ?string
	{
		if (preg_match('/^[A-Za-z0-9_-]*$/', $value) !== 1 || strlen($value) % 4 === 1) {
			return null;
		}

		$decoded = base64_decode(strtr($value, '-_', '+/'), true);

		return $decoded === false ? null : $decoded;
	}
}
