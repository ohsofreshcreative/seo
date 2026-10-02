<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

/**
 * Kształt wyniku organicznego (rodzaj strony w wynikach) — heurystyka z adresu, flag i danych prezentacji wyniku. To opis tego,
 * co rankuje, a nie fakt o treści strony („Dedykowana podstrona” nigdy nie oznacza „strony usługowej”).
 */
enum ResultShape: string
{
	case Home = 'home';
	case Subpage = 'subpage';
	case Article = 'article';
	case Listing = 'listing';
	case Product = 'product';
	case Video = 'video';
	case Unknown = 'unknown';

	public function label(): string
	{
		return match ($this) {
			self::Home => 'Strona główna',
			self::Subpage => 'Dedykowana podstrona',
			self::Article => 'Artykuł / poradnik',
			self::Listing => 'Listing / kategoria',
			self::Product => 'Produkt',
			self::Video => 'Wideo',
			self::Unknown => 'Nieznany',
		};
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $shape): string => $shape->value, self::cases());
	}
}
