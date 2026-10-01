<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Market\MarketMetrics;

/**
 * Nowa fraza projektu (odczyt): stan pracy, migawka widoczności GSC i priorytet z kandydata, metryki — ze wspólnej
 * frazy rynkowej (`market_keywords`, bez kopiowania). Źródła (seedy) i historia dołączane w szczegółach.
 */
final class CandidateRow
{
	/**
	 * @param array<string, mixed> $meta metadane dostawcy (grupa synonimów, inny język)
	 * @param list<array{seed: string, method: string, relation: int, depth: ?int, position: ?int, run: string, first_seen_at: string, last_seen_at: string}> $sources
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly string $keyword,
		public readonly CandidateStatus $status,
		public readonly ?string $note,
		public readonly int $seedsCount,
		public readonly int $bestRelation,
		public readonly Visibility $visibility,
		public readonly ?int $gscImpressions,
		public readonly ?int $gscClicks,
		public readonly ?float $gscPosition,
		public readonly ?string $targetUrl,
		public readonly ?int $priority,
		public readonly ?DiscoveryScore $score,
		public readonly bool $excluded,
		public readonly ?string $intent,
		public readonly ?string $intentFetchedAt,
		public readonly MarketMetrics $market,
		public readonly string $discoveredAt,
		public readonly string $lastSeenAt,
		public readonly ?string $statusChangedAt,
		public readonly ?int $statusChangedBy,
		public readonly array $meta = [],
		public array $sources = [],
	) {
	}

	/**
	 * @param array<string, string|null> $row wiersz z kolumnami kandydata i `m_…` frazy rynkowej
	 */
	public static function fromRow(array $row): self
	{
		$score = json_decode((string) ($row['score'] ?? ''), true);
		$meta = json_decode((string) ($row['provider_meta'] ?? ''), true);

		return new self(
			id: (int) $row['id'],
			publicId: (string) $row['public_id'],
			keyword: (string) $row['m_keyword'],
			status: CandidateStatus::tryFrom((string) $row['status']) ?? CandidateStatus::New,
			note: $row['note'] ?? null,
			seedsCount: (int) $row['seeds_count'],
			bestRelation: (int) $row['best_relation'],
			visibility: Visibility::tryFrom((string) $row['visibility']) ?? Visibility::Unknown,
			gscImpressions: isset($row['gsc_impressions']) ? (int) $row['gsc_impressions'] : null,
			gscClicks: isset($row['gsc_clicks']) ? (int) $row['gsc_clicks'] : null,
			gscPosition: isset($row['gsc_position']) ? (float) $row['gsc_position'] : null,
			targetUrl: $row['target_url'] ?? null,
			priority: isset($row['priority']) ? (int) $row['priority'] : null,
			score: DiscoveryScore::fromArray(is_array($score) ? $score : null),
			excluded: (int) ($row['excluded'] ?? 0) === 1,
			intent: $row['m_search_intent'] ?? null,
			intentFetchedAt: $row['m_intent_fetched_at'] ?? null,
			market: MarketMetrics::fromRow($row, 'm_') ?? throw new \UnexpectedValueException('Candidate without market keyword.'),
			discoveredAt: (string) $row['discovered_at'],
			lastSeenAt: (string) $row['last_seen_at'],
			statusChangedAt: $row['status_changed_at'] ?? null,
			statusChangedBy: isset($row['status_changed_by']) ? (int) $row['status_changed_by'] : null,
			meta: is_array($meta) ? $meta : [],
		);
	}

	public static function intentLabel(?string $intent): ?string
	{
		return match ($intent) {
			'informational' => 'informacyjna',
			'navigational' => 'nawigacyjna',
			'commercial' => 'komercyjna',
			'transactional' => 'transakcyjna',
			default => null,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->publicId,
			'keyword' => $this->keyword,
			'status' => $this->status->value,
			'priority' => $this->priority,
			'visibility' => $this->visibility->value,
			'gsc_position' => $this->gscPosition,
			'gsc_impressions' => $this->gscImpressions,
			'gsc_clicks' => $this->gscClicks,
			'search_volume' => $this->market->searchVolume,
			'keyword_difficulty' => $this->market->keywordDifficulty,
			'cpc' => $this->market->cpc,
			'competition_level' => $this->market->competitionLevel,
			'intent' => $this->intent,
			'seeds' => $this->seedsCount,
			'target_url' => $this->targetUrl,
			'excluded' => $this->excluded,
			'discovered_at' => $this->discoveredAt,
			'last_seen_at' => $this->lastSeenAt,
		];
	}
}
