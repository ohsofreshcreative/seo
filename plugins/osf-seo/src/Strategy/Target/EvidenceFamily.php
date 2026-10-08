<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Target;

/**
 * Rodzina dowodów — jednostka niezależności (docs/ARCHITECTURE.md, sekcja 15.13): dwa sygnały z tej samej rodziny nie są dwoma
 * niezależnymi potwierdzeniami (np. szansa SEO, „Widoczność GSC” Nowych fraz i strona docelowa luki ze źródłem GSC pochodzą z tych
 * samych danych query × page co GSC). Dopasowanie adresu (`slug`) jest zawsze słabą heurystyką, nigdy potwierdzeniem.
 */
enum EvidenceFamily: string
{
	case Manual = 'manual';
	case Gsc = 'gsc';
	case Serp = 'serp';
	case Labs = 'labs';
	case Slug = 'slug';

	/** Rodzina może potwierdzać stronę docelową (slug — nie). */
	public function confirms(): bool
	{
		return $this !== self::Slug;
	}

	public function label(): string
	{
		return match ($this) {
			self::Manual => 'wskazanie ręczne',
			self::Gsc => 'Google Search Console',
			self::Serp => 'pomiar SERP',
			self::Labs => 'DataForSEO Labs',
			self::Slug => 'dopasowanie adresu',
		};
	}
}
