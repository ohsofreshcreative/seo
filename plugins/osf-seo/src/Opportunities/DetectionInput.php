<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Analytics\KeywordRow;
use OsfSeo\Analytics\Period;

/**
 * Dane wejściowe wykrywania szans dla jednego projektu i okresu — już zagregowane w SQL
 * (bez historii dziennej w PHP). Klasa bez zależności od WordPressa (testy jednostkowe).
 */
final class DetectionInput
{
	/**
	 * @param list<KeywordRow> $keywords frazy (`query_daily`) z porównaniem okresów
	 * @param list<PagePair> $pairs fraza × podstrona (adresy bez fragmentu, scalone)
	 * @param array<string, array{0: Stats, 1: Stats}> $pageTotals podstrona → [bieżący, poprzedni] (suma widocznych fraz)
	 * @param array<int, array<string, array<int, int>>> $segments fraza → adres → segment okresu → wyświetlenia (kandydaci do kanibalizacji)
	 * @param array<int, string> $keywordTexts teksty fraz spoza $keywords (kandydaci do kanibalizacji)
	 */
	public function __construct(
		public readonly string $property,
		public readonly Period $period,
		public readonly bool $previousCovered,
		public readonly array $keywords,
		public readonly array $pairs,
		public readonly array $pageTotals,
		public readonly array $segments = [],
		public readonly array $keywordTexts = [],
	) {
	}

	public function withSegments(array $segments, array $keywordTexts): self
	{
		return new self($this->property, $this->period, $this->previousCovered, $this->keywords, $this->pairs, $this->pageTotals, $segments, $keywordTexts);
	}

	/** Długość segmentu (dni) do wykrywania zmian dominującego adresu w okresie. */
	public function segmentDays(): int
	{
		return $this->period->days >= 28 ? 7 : 1;
	}
}
