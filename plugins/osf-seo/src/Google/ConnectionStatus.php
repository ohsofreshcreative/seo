<?php

declare(strict_types=1);

namespace OsfSeo\Google;

enum ConnectionStatus: string
{
	case Active = 'active';
	case NeedsReauth = 'needs_reauth';
	case Revoked = 'revoked';

	public function label(): string
	{
		return match ($this) {
			self::Active => 'Aktywne',
			self::NeedsReauth => 'Wymaga ponownej autoryzacji',
			self::Revoked => 'Odwołane',
		};
	}
}
