<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Typ luki frazy (Keyword Gap) — porównanie najlepszego konkurenta (pozycja Labs) z widocznością projektu.
 */
enum GapType: string
{
	/** Konkurent rankuje, a projekt — według wiarygodnego dowodu — nie ma widoczności. */
	case Missing = 'missing';

	/** Konkurent wyraźnie wyżej, projekt widoczny słabo (poza TOP 10 albo sporadycznie). */
	case Weak = 'weak';

	/** Projekt już rankuje rozsądnie (TOP 10 albo blisko konkurenta). */
	case Competitive = 'competitive';

	/** Projekt wyżej niż najlepszy konkurent. */
	case Stronger = 'stronger';

	/** Brak wiarygodnych danych o widoczności projektu. */
	case Unknown = 'unknown';

	public function label(): string
	{
		return match ($this) {
			self::Missing => 'Brak widoczności',
			self::Weak => 'Słaba widoczność',
			self::Competitive => 'Porównywalna',
			self::Stronger => 'Projekt silniejszy',
			self::Unknown => 'Nieznana',
		};
	}

	/** Luka do sprawdzenia (domyślna lista: brak, słaba, nieznana). */
	public function isGap(): bool
	{
		return in_array($this, [self::Missing, self::Weak, self::Unknown], true);
	}

	public static function fromInput(mixed $value): ?self
	{
		return is_string($value) ? self::tryFrom($value) : null;
	}
}
