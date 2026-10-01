<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Obecna widoczność frazy w Google Search Console (średnia pozycja GSC — nie dokładny ranking SERP).
 */
enum Visibility: string
{
	/** Brak danych GSC projektu (np. przed importem albo po resecie property) — nie da się ocenić. */
	case Unknown = 'unknown';
	/** Brak istotnej widoczności (poniżej progu wyświetleń w oknie). */
	case None = 'none';
	/** Fraza pojawia się w GSC, ale słabo: średnia pozycja poza TOP 10 albo widoczność sporadyczna. */
	case Low = 'low';
	/** Strona jest już stale widoczna (TOP 10 średniej pozycji GSC i istotny udział wyświetleń). */
	case Visible = 'visible';

	public function label(): string
	{
		return match ($this) {
			self::Unknown => 'Nieznana',
			self::None => 'Brak widoczności',
			self::Low => 'Słaba widoczność',
			self::Visible => 'Już widoczna',
		};
	}

	/** Domyślny widok listy: frazy z luką (brak lub słaba widoczność) i nieocenione. */
	public function isGap(): bool
	{
		return $this !== self::Visible;
	}

	/**
	 * @return list<string>
	 */
	public static function gapValues(): array
	{
		return [self::None->value, self::Low->value, self::Unknown->value];
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $visibility): string => $visibility->value, self::cases());
	}
}
