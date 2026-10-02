<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Fakty i dowody kandydata na chwilę przeliczenia (wiersz `strategy_keywords`). Fakty to wartości do filtrów i późniejszej
 * klasyfikacji; dowody (`evidence`) — wersjonowany JSON z odwołaniami do rekordów modułów. Metryki rynkowe nie są kopiowane.
 */
final class KeywordFacts
{
	/**
	 * @param array<string, mixed> $evidence
	 */
	public function __construct(
		public readonly int $marketKeywordId,
		public readonly int $sources,
		public readonly int $tier,
		/** NULL = projekt bez danych GSC; 0 = dane są, fraza bez wyświetleń (to nie dowód braku widoczności). */
		public readonly ?int $gscImpressions,
		public readonly ?int $gscClicks,
		public readonly ?float $gscPosition,
		public readonly ?int $gscPages,
		public readonly ?int $gscTopUrlId,
		public readonly ?float $gscTopShare,
		public readonly ?int $trackedKeywordId,
		public readonly ?string $serpCheckedAt,
		public readonly ?bool $serpFound,
		public readonly ?int $serpRank,
		public readonly ?int $serpUrlId,
		public readonly ?int $gapKeywordId,
		public readonly ?int $gapClusterId,
		public readonly ?int $discoveryCandidateId,
		public readonly int $opportunities,
		public readonly array $evidence,
	) {
	}

	/** Odcisk faktów i dowodów (hex) — zapis tylko po zmianie. */
	public function hash(): string
	{
		return md5((string) json_encode([
			$this->sources,
			$this->tier,
			$this->gscImpressions,
			$this->gscClicks,
			$this->gscPosition,
			$this->gscPages,
			$this->gscTopUrlId,
			$this->gscTopShare,
			$this->trackedKeywordId,
			$this->serpCheckedAt,
			$this->serpFound,
			$this->serpRank,
			$this->serpUrlId,
			$this->gapKeywordId,
			$this->gapClusterId,
			$this->discoveryCandidateId,
			$this->opportunities,
			$this->evidence,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
	}

	public function evidenceJson(): string
	{
		return (string) json_encode($this->evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
	}
}
