<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Ustawienia Luk SEO projektu (`gap_settings`): progi luki i trafności, słowa tematyczne, marka projektu, domyślny zakres
 * pobierania i harmonogram odświeżania (domyślnie wyłączony — włączenie oznacza przyszłe płatne importy).
 */
final class GapSettings
{
	public function __construct(
		public readonly int $projectId,
		/** Konkurent jest „znaczący” dla frazy, gdy jego pozycja (Labs) jest nie gorsza niż próg. */
		public readonly int $competitorMaxRank = GapConfig::DEFAULT_COMPETITOR_MAX_RANK,
		public readonly int $minVolume = GapConfig::DEFAULT_MIN_VOLUME,
		public readonly ?int $maxDifficulty = null,
		public readonly int $fetchMaxRank = GapConfig::DEFAULT_FETCH_MAX_RANK,
		public readonly int $fetchMinVolume = GapConfig::DEFAULT_FETCH_MIN_VOLUME,
		public readonly int $maxRows = GapConfig::DEFAULT_MAX_ROWS,
		/** Słowa tematyczne (składnia wykluczeń): gdy lista jest niepusta, fraza musi pasować do jednego z nich. */
		public readonly string $includeTerms = '',
		/** Warianty marki projektu (frazy markowe projektu nie są lukami). */
		public readonly string $brandTerms = '',
		public readonly int $refreshDays = 30,
		public readonly bool $scheduleEnabled = false,
		public readonly ?string $scheduleEnabledAt = null,
		public readonly ?int $scheduleEnabledBy = null,
		public readonly ?string $nextRefreshAt = null,
		public readonly ?string $lastSkipReason = null,
		public readonly ?string $lastSkipAt = null,
		/** Skrót danych wejściowych ostatniego przeliczenia (hex) — przeliczenie tylko po zmianie. */
		public readonly ?string $dataKey = null,
		public readonly ?string $recalculatedAt = null,
		public readonly ?string $updatedAt = null,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			projectId: (int) $row['project_id'],
			competitorMaxRank: in_array((int) $row['competitor_max_rank'], GapConfig::COMPETITOR_RANKS, true) ? (int) $row['competitor_max_rank'] : GapConfig::DEFAULT_COMPETITOR_MAX_RANK,
			minVolume: max(0, (int) $row['min_volume']),
			maxDifficulty: $row['max_difficulty'] === null ? null : max(0, min(100, (int) $row['max_difficulty'])),
			fetchMaxRank: in_array((int) $row['fetch_max_rank'], GapConfig::FETCH_RANKS, true) ? (int) $row['fetch_max_rank'] : GapConfig::DEFAULT_FETCH_MAX_RANK,
			fetchMinVolume: max(0, (int) $row['fetch_min_volume']),
			maxRows: max(100, (int) $row['max_rows']),
			includeTerms: (string) $row['include_terms'],
			brandTerms: (string) $row['brand_terms'],
			refreshDays: max(7, min(180, (int) $row['refresh_days'])),
			scheduleEnabled: (int) $row['schedule_enabled'] === 1,
			scheduleEnabledAt: $row['schedule_enabled_at'],
			scheduleEnabledBy: $row['schedule_enabled_by'] === null ? null : (int) $row['schedule_enabled_by'],
			nextRefreshAt: $row['next_refresh_at'],
			lastSkipReason: $row['last_skip_reason'],
			lastSkipAt: $row['last_skip_at'],
			dataKey: $row['data_key_hex'] ?? null,
			recalculatedAt: $row['recalculated_at'],
			updatedAt: $row['updated_at'],
		);
	}

	public function fetchCoverage(int $maxRowsPerDomain): Coverage
	{
		return new Coverage($this->fetchMaxRank, $this->fetchMinVolume, max(100, min($maxRowsPerDomain, $this->maxRows)));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'competitor_max_rank' => $this->competitorMaxRank,
			'min_volume' => $this->minVolume,
			'max_difficulty' => $this->maxDifficulty,
			'fetch_max_rank' => $this->fetchMaxRank,
			'fetch_min_volume' => $this->fetchMinVolume,
			'max_rows' => $this->maxRows,
			'include_terms' => $this->includeTerms,
			'brand_terms' => $this->brandTerms,
			'refresh_days' => $this->refreshDays,
			'schedule_enabled' => $this->scheduleEnabled,
			'schedule_enabled_at' => $this->scheduleEnabledAt,
			'next_refresh_at' => $this->nextRefreshAt,
			'last_skip_reason' => $this->lastSkipReason,
			'last_skip_at' => $this->lastSkipAt,
			'recalculated_at' => $this->recalculatedAt,
		];
	}
}
