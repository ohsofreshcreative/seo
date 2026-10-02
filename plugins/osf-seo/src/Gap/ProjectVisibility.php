<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Widoczność projektu dla frazy z najlepszego dostępnego źródła (pomiar SERP → GSC → punkt odniesienia Labs).
 */
enum ProjectVisibility: string
{
	case Unknown = 'unknown';

	case None = 'none';

	case Low = 'low';

	case Visible = 'visible';

	public function label(): string
	{
		return match ($this) {
			self::Unknown => 'Nieznana',
			self::None => 'Brak',
			self::Low => 'Słaba',
			self::Visible => 'Widoczna',
		};
	}
}
