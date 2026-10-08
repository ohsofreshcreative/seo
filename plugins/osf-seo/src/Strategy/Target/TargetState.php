<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Target;

/**
 * Stan strony docelowej frazy albo tematu (D57). `None` = w dostępnych źródłach nie ma odpowiedniej strony i są dodatkowe dowody
 * braku widoczności — nigdy dowód, że strona nie istnieje (brak GSC, Labs, TOP100 albo dopasowania adresu ≠ brak strony).
 */
enum TargetState: string
{
	case Confirmed = 'confirmed';
	case Probable = 'probable';
	case Conflict = 'conflict';
	case None = 'none';
	case Unknown = 'unknown';

	/** Znana strona docelowa (potwierdzona albo prawdopodobna). */
	public function hasTarget(): bool
	{
		return $this === self::Confirmed || $this === self::Probable;
	}

	public function label(): string
	{
		return match ($this) {
			self::Confirmed => 'Potwierdzona',
			self::Probable => 'Prawdopodobna',
			self::Conflict => 'Konflikt stron',
			self::None => 'Brak znanej strony docelowej',
			self::Unknown => 'Nieznana',
		};
	}
}
