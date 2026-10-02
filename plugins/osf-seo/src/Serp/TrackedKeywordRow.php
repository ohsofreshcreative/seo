<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Monitorowana fraza w widokach: bieżąca Pozycja SERP (ostatni pomiar) i zmiana — osobno od średniej pozycji GSC.
 */
final class TrackedKeywordRow
{
	/**
	 * @param array<string, array{best: int, url: ?string}> $competitors ULID konkurenta → najlepsza pozycja w ostatnim pomiarze
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly string $keyword,
		public readonly string $keywordKey,
		public readonly int $marketKeywordId,
		public readonly string $source,
		public readonly string $status,
		public readonly ?int $searchVolume,
		public readonly ?int $difficulty,
		public readonly ?int $lastSnapshotId,
		public readonly ?int $lastContextId,
		public readonly ?string $lastCheckedAt,
		public readonly ?bool $found,
		public readonly ?int $rank,
		public readonly ?int $rankAbsolute,
		public readonly ?string $url,
		public readonly ?int $depth,
		public readonly bool $featured,
		public readonly ?int $previousRank,
		public readonly ?string $changeType,
		public readonly ?int $changeValue,
		public readonly ?string $top10Change,
		public readonly string $addedAt,
		public array $competitors = [],
		public ?float $gscPosition = null,
		public ?int $gscImpressions = null,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$int = static fn (?string $value): ?int => $value === null ? null : (int) $value;

		return new self(
			(int) $row['id'],
			(string) $row['public_id'],
			(string) $row['keyword'],
			(string) $row['keyword_hex'],
			(int) $row['market_keyword_id'],
			(string) $row['source'],
			(string) $row['status'],
			$int($row['search_volume']),
			$int($row['keyword_difficulty']),
			$int($row['last_snapshot_id']),
			$int($row['last_context_id']),
			$row['last_checked_at'],
			$row['last_found'] === null ? null : (int) $row['last_found'] === 1,
			$int($row['last_rank']),
			$int($row['last_rank_absolute']),
			$row['url'],
			$int($row['last_depth']),
			(int) $row['last_featured'] === 1,
			$int($row['prev_rank']),
			$row['change_type'],
			$int($row['change_value']),
			$row['top10_change'],
			(string) $row['added_at'],
		);
	}

	public function checked(): bool
	{
		return $this->found !== null;
	}

	/** „#7”, „Poza TOP100” albo „—” (jeszcze nie sprawdzono). */
	public function rankLabel(): string
	{
		return match (true) {
			$this->found === null => '—',
			$this->rank === null => 'Poza TOP' . ($this->depth ?? SerpConfig::DEFAULT_DEPTH),
			default => '#' . $this->rank,
		};
	}

	public function changeLabel(): string
	{
		return RankChange::label($this->changeType, $this->changeValue, $this->depth ?? SerpConfig::DEFAULT_DEPTH);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->publicId,
			'keyword' => $this->keyword,
			'source' => $this->source,
			'status' => $this->status,
			'search_volume' => $this->searchVolume,
			'keyword_difficulty' => $this->difficulty,
			'serp_rank' => $this->rank,
			'serp_rank_absolute' => $this->rankAbsolute,
			'found' => $this->found,
			'rank_label' => $this->rankLabel(),
			'depth' => $this->depth,
			'url' => $this->url,
			'featured_snippet' => $this->featured,
			'previous_rank' => $this->previousRank,
			'change' => $this->changeType,
			'change_value' => $this->changeValue,
			'top10_change' => $this->top10Change,
			'last_checked_at' => $this->lastCheckedAt,
			'gsc_average_position' => $this->gscPosition,
			'competitors' => $this->competitors,
		];
	}
}
