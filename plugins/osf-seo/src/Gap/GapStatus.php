<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Praca nad luką frazy albo grupą (te same wartości co kandydaci Nowych fraz — wspólne wejście dla przyszłego backlogu).
 */
enum GapStatus: string
{
	case New = 'new';

	case Review = 'review';

	case Accepted = 'accepted';

	case Dismissed = 'dismissed';

	public function label(): string
	{
		return match ($this) {
			self::New => 'Nowa',
			self::Review => 'Do analizy',
			self::Accepted => 'Zaakceptowana',
			self::Dismissed => 'Odrzucona',
		};
	}

	public static function fromInput(mixed $value): ?self
	{
		return is_string($value) ? self::tryFrom($value) : null;
	}
}
