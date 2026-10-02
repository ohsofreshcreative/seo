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

	public static function fromInput(mixed $value): ?self
	{
		return is_string($value) ? self::tryFrom($value) : null;
	}
}
