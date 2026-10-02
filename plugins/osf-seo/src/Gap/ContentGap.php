<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Heurystyka luki treści (Content Gap) grupy fraz — sygnał do sprawdzenia, nigdy diagnoza „potrzebna nowa strona”.
 */
enum ContentGap: string
{
	/** Projekt ma spójną stronę docelową, ale widoczność grupy jest słaba. */
	case Improve = 'improve';

	/** Brak przekonującej strony docelowej projektu (albo tylko strona główna), konkurenci rankują podstronami. */
	case NewPage = 'new_page';

	/** Za mało danych albo sprzeczne sygnały. */
	case Unclear = 'unclear';

	/** Projekt ma stronę i porównywalną widoczność. */
	case Covered = 'covered';

	public function label(): string
	{
		return match ($this) {
			self::Improve => 'Istniejąca strona — do wzmocnienia',
			self::NewPage => 'Potencjalna luka treści',
			self::Unclear => 'Niejasne',
			self::Covered => 'Bez luki treści',
		};
	}

	/** Powód klasyfikacji (`ContentGapClassifier`) dla panelu i CLI. */
	public static function reasonLabel(?string $reason): string
	{
		return match ($reason) {
			'no_project_data' => 'brak danych projektu (GSC, punkt odniesienia Labs, pomiar SERP) — nie da się ocenić',
			'scattered' => 'wyświetlenia grupy rozkładają się na kilka stron projektu',
			'covered' => 'projekt ma stronę docelową i porównywalną widoczność',
			'homepage_only' => 'jedyną stroną projektu dla tych fraz jest strona główna, a konkurenci rankują podstronami',
			'target_serp' => 'strona projektu z naszego pomiaru SERP',
			'target_gsc' => 'strona projektu z Google Search Console (większość wyświetleń grupy)',
			'target_labs' => 'strona projektu z punktu odniesienia DataForSEO Labs',
			'target_slug' => 'adres projektu zawierający słowa frazy (bez danych o ruchu)',
			'no_target' => 'brak strony projektu dla tych fraz, a konkurenci rankują dedykowanymi podstronami',
			'competitors_homepages' => 'konkurenci rankują wyłącznie stronami głównymi',
			'low_demand' => 'mały łączny wolumen luk w grupie',
			default => (string) $reason,
		};
	}

	public static function fromInput(mixed $value): ?self
	{
		return is_string($value) ? self::tryFrom($value) : null;
	}
}
