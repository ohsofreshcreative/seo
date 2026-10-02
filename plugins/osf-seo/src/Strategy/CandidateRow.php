<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Kandydat Strategii do listy i szczegółów: fakty z `strategy_keywords` i metryki rynkowe z `market_keywords`
 * (odczyt, nie kopia). Pozycje mają osobne znaczenia: Pozycja SERP (pomiar) ≠ średnia pozycja (GSC).
 */
final class CandidateRow
{
	/**
	 * @param list<StrategySource> $sources
	 * @param array<string, mixed>|null $evidence
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $marketKeywordId,
		public readonly string $keyword,
		public readonly bool $active,
		public readonly ?string $inactiveReason,
		public readonly array $sources,
		public readonly ?int $tier,
		public readonly bool $manual,
		public readonly ?int $gscImpressions,
		public readonly ?int $gscClicks,
		public readonly ?float $gscPosition,
		public readonly ?int $gscPages,
		public readonly ?string $serpCheckedAt,
		public readonly ?bool $serpFound,
		public readonly ?int $serpRank,
		public readonly int $opportunities,
		public readonly ?int $searchVolume,
		public readonly ?int $keywordDifficulty,
		public readonly ?float $cpc,
		public readonly ?string $intent,
		public readonly string $firstSeenAt,
		public readonly ?string $refreshedAt,
		public readonly ?array $evidence = null,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$int = static fn (?string $value): ?int => $value === null ? null : (int) $value;
		$evidence = isset($row['evidence']) ? json_decode((string) $row['evidence'], true) : null;

		return new self(
			id: (int) $row['id'],
			publicId: (string) $row['public_id'],
			marketKeywordId: (int) $row['market_keyword_id'],
			keyword: (string) $row['keyword'],
			active: (int) $row['active'] === 1,
			inactiveReason: $row['inactive_reason'],
			sources: StrategySource::fromBits((int) $row['sources']),
			tier: $int($row['tier']),
			manual: (int) $row['manual'] === 1,
			gscImpressions: $int($row['gsc_impressions']),
			gscClicks: $int($row['gsc_clicks']),
			gscPosition: $row['gsc_position'] === null ? null : (float) $row['gsc_position'],
			gscPages: $int($row['gsc_pages']),
			serpCheckedAt: $row['serp_checked_at'],
			serpFound: $row['serp_found'] === null ? null : (int) $row['serp_found'] === 1,
			serpRank: $int($row['serp_rank']),
			opportunities: (int) $row['opportunities'],
			searchVolume: $int($row['search_volume']),
			keywordDifficulty: $int($row['keyword_difficulty']),
			cpc: ($row['cpc'] ?? null) === null ? null : (float) $row['cpc'],
			intent: $row['search_intent'],
			firstSeenAt: (string) $row['first_seen_at'],
			refreshedAt: $row['refreshed_at'],
			evidence: is_array($evidence) ? $evidence : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(bool $withEvidence = false): array
	{
		$data = [
			'id' => $this->publicId,
			'keyword' => $this->keyword,
			'active' => $this->active,
			'inactive_reason' => $this->inactiveReason,
			'sources' => array_map(static fn (StrategySource $source): string => $source->value, $this->sources),
			'tier' => $this->tier,
			'manual' => $this->manual,
			'gsc_impressions' => $this->gscImpressions,
			'gsc_clicks' => $this->gscClicks,
			'gsc_position' => $this->gscPosition,
			'gsc_pages' => $this->gscPages,
			'serp_checked_at' => $this->serpCheckedAt,
			'serp_found' => $this->serpFound,
			'serp_rank' => $this->serpRank,
			'opportunities' => $this->opportunities,
			'search_volume' => $this->searchVolume,
			'keyword_difficulty' => $this->keywordDifficulty,
			'cpc' => $this->cpc,
			'intent' => $this->intent,
			'first_seen_at' => $this->firstSeenAt,
			'refreshed_at' => $this->refreshedAt,
		];

		if ($withEvidence) {
			$data['evidence'] = $this->evidence;
		}

		return $data;
	}

	public static function inactiveReasonLabel(?string $reason): string
	{
		return match ($reason) {
			null => '—',
			StrategyKeywordRepository::REASON_NO_SOURCE => 'brak źródła',
			StrategyKeywordRepository::REASON_OVERFLOW => 'ponad limit kandydatów',
			StrategyKeywordRepository::REASON_MARKET_CHANGED => 'inny rynek projektu',
			CandidateFilter::BRAND_OWN => 'marka projektu',
			CandidateFilter::BRAND_COMPETITOR => 'marka konkurenta',
			CandidateFilter::EXCLUDED => 'wykluczenie projektu',
			default => $reason,
		};
	}
}
