<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use OsfSeo\Support\Base64Url;

/**
 * Fałszywe wartości Google do testów — generowane losowo w runtime (nie są prawdziwymi sekretami;
 * losowość pozwala wyszukać je w bazie i logach bez fałszywych trafień).
 */
final class GoogleFakes
{
	public const CLIENT_ID = 'test-client-id.apps.example.test';

	public static function accessToken(): string
	{
		return 'ya' . '29.test-' . bin2hex(random_bytes(16));
	}

	public static function refreshToken(): string
	{
		return '1/' . '/test-' . bin2hex(random_bytes(16));
	}

	public static function clientSecret(): string
	{
		return 'test-secret-' . bin2hex(random_bytes(8));
	}

	public static function encryptionKey(): string
	{
		return 'base64:' . base64_encode(random_bytes(32));
	}

	/**
	 * Niepodpisany JWT w kształcie id_token Google (podpis nie jest weryfikowany — patrz IdToken).
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function idToken(int $now, array $overrides = []): string
	{
		$claims = $overrides + [
			'iss' => 'https://accounts.google.com',
			'aud' => self::CLIENT_ID,
			'sub' => '1000' . random_int(10000000, 99999999),
			'email' => 'owner@example.test',
			'email_verified' => true,
			'iat' => $now,
			'exp' => $now + 3600,
		];

		return Base64Url::encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
			. '.' . Base64Url::encode((string) json_encode($claims))
			. '.' . Base64Url::encode(random_bytes(32));
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function tokenResponse(int $now, ?string $accessToken = null, ?string $refreshToken = null, ?string $idToken = null, string $scope = ''): array
	{
		return array_filter([
			'access_token' => $accessToken ?? self::accessToken(),
			'expires_in' => 3599,
			'refresh_token' => $refreshToken,
			'scope' => $scope !== '' ? $scope : 'https://www.googleapis.com/auth/webmasters.readonly openid https://www.googleapis.com/auth/userinfo.email',
			'token_type' => 'Bearer',
			'id_token' => $idToken ?? self::idToken($now),
		], static fn (mixed $value): bool => $value !== null);
	}
}
