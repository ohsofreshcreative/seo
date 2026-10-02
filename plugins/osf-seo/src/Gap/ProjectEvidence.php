<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Widoczność projektu dla frazy wraz ze źródłem i pozycją. Pozycje różnych źródeł mają różne znaczenie (dokładny pomiar
 * SERP, średnia ważona GSC, migawka Labs) — w UI zawsze z etykietą źródła, nigdy jako jedna „pozycja”.
 */
final class ProjectEvidence
{
	public const SOURCE_SERP = 'serp';

	public const SOURCE_GSC = 'gsc';

	public const SOURCE_LABS = 'labs';

	public function __construct(
		public readonly ProjectVisibility $visibility,
		public readonly ?string $source,
		/** Pozycja projektu z wybranego źródła (SERP i Labs: liczba całkowita, GSC: średnia ważona); null — brak. */
		public readonly ?float $position,
		/** GSC: pozycja w TOP 10, ale wyświetlenia sporadyczne względem wolumenu. */
		public readonly bool $sporadic = false,
	) {
	}

	public static function unknown(): self
	{
		return new self(ProjectVisibility::Unknown, null, null);
	}

	public function sourceLabel(): ?string
	{
		return match ($this->source) {
			self::SOURCE_SERP => 'SERP',
			self::SOURCE_GSC => 'GSC',
			self::SOURCE_LABS => 'Labs',
			default => null,
		};
	}
}
