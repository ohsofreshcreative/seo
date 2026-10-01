<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Urządzenie pomiaru. System operacyjny podajemy jawnie (domyślne wartości dostawcy), żeby kontekst był powtarzalny.
 */
enum SerpDevice: string
{
	case Desktop = 'desktop';
	case Mobile = 'mobile';

	public function os(): string
	{
		return match ($this) {
			self::Desktop => 'windows',
			self::Mobile => 'android',
		};
	}

	public function label(): string
	{
		return match ($this) {
			self::Desktop => 'Desktop',
			self::Mobile => 'Mobile',
		};
	}
}
