<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

/**
 * Fałszywe wartości w kształcie sekretów do testów redakcji.
 *
 * Składane w runtime z kawałków, żeby skanery sekretów (np. GitHub secret scanning)
 * nie zgłaszały fałszywych alarmów. Żadna z nich nie jest prawdziwym sekretem.
 */
final class SecretSamples
{
	public static function googleAccessToken(): string
	{
		return 'ya' . '29.' . str_repeat('a0B1', 12);
	}

	public static function googleRefreshToken(): string
	{
		return '1/' . '/' . str_repeat('c2D3', 12);
	}

	public static function googleClientSecret(): string
	{
		return 'GOC' . 'SPX-' . str_repeat('e4F5', 7);
	}

	public static function jwt(): string
	{
		return 'ey' . 'J' . str_repeat('g6H7', 4) . '.ey' . 'J' . str_repeat('i8J9', 4) . '.' . str_repeat('k0L1', 4);
	}

	public static function privateKey(): string
	{
		return '-----BEGIN ' . 'OPENSSH PRIVATE KEY-----' . "\n" . str_repeat('m2N3', 16) . "\n"
			. '-----END ' . 'OPENSSH PRIVATE KEY-----';
	}
}
