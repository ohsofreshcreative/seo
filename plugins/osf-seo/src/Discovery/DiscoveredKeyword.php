<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Market\VolumeMetrics;

/**
 * Fraza znaleziona przez dostawcę z metrykami zwróconymi w tej samej odpowiedzi (bez dodatkowych płatnych wywołań).
 * Wartości spoza typu/zakresu → null (brak danych).
 */
final class DiscoveredKeyword
{
	public const INTENTS = ['informational', 'navigational', 'commercial', 'transactional'];

	/**
	 * @param list<array{month: string, search_volume: ?int}> $monthly
	 */
	public function __construct(
		public readonly string $keyword,
		public readonly ?int $searchVolume,
		public readonly ?float $cpc,
		public readonly ?string $competitionLevel,
		/** Konkurencja Ads 0–100 (dostawca: 0–1 × 100). */
		public readonly ?int $competitionIndex,
		public readonly ?float $lowTopOfPageBid,
		public readonly ?float $highTopOfPageBid,
		public readonly array $monthly,
		public readonly ?int $keywordDifficulty,
		/** Główna intencja wyszukiwania według dostawcy (informational, navigational, commercial, transactional). */
		public readonly ?string $intent,
		/** Dostawca rozpoznał inny język frazy niż język rynku. */
		public readonly ?bool $isAnotherLanguage,
		/** Główna fraza grupy synonimów dostawcy (tylko informacyjnie — nie scalamy kandydatów). */
		public readonly ?string $coreKeyword,
		/** Głębokość powiązania z seedem (`related`), null dla innych metod. */
		public readonly ?int $depth,
		/** Pozycja na liście wyników dostawcy (od 1, z uwzględnieniem offsetu). */
		public readonly int $position,
	) {
	}

	/** Metryki wolumenu w formacie danych rynkowych (STEP 12). */
	public function volumeMetrics(): VolumeMetrics
	{
		return new VolumeMetrics(
			keyword: $this->keyword,
			searchVolume: $this->searchVolume,
			cpc: $this->cpc,
			competitionLevel: $this->competitionLevel,
			competitionIndex: $this->competitionIndex,
			lowTopOfPageBid: $this->lowTopOfPageBid,
			highTopOfPageBid: $this->highTopOfPageBid,
			monthly: $this->monthly,
		);
	}

	/**
	 * Metadane dostawcy zapisywane przy kandydacie (diagnostyka, bez metryk).
	 *
	 * @return array<string, string|bool>
	 */
	public function meta(): array
	{
		return array_filter([
			'core_keyword' => $this->coreKeyword,
			'is_another_language' => $this->isAnotherLanguage,
		], static fn (mixed $value): bool => $value !== null);
	}
}
