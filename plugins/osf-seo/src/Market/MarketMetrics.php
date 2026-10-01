<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Zapisane metryki rynkowe frazy (odczyt). Wartość `null` znaczy „nieznane” — nigdy nie zamieniamy jej na 0:
 * - `volumeFetchedAt === null` — wolumenu jeszcze nie pobrano,
 * - `volumeFetchedAt !== null && searchVolume === null` — dostawca nie ma danych dla tej frazy.
 */
final class MarketMetrics
{
	/**
	 * @param list<array{month: string, search_volume: ?int}> $monthly historia (rosnąco), dołączana osobno
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $keyword,
		public readonly ?int $searchVolume,
		public readonly ?float $cpc,
		public readonly ?string $competitionLevel,
		public readonly ?int $competitionIndex,
		public readonly ?int $keywordDifficulty,
		public readonly ?string $volumeFetchedAt,
		public readonly ?string $volumeStaleAfter,
		public readonly ?string $difficultyFetchedAt,
		public readonly ?string $difficultyStaleAfter,
		public array $monthly = [],
	) {
	}

	/**
	 * Wiersz `market_keywords` (opcjonalnie z prefiksem kolumn, np. `m_` w raporcie fraz). Null, gdy brak wiersza.
	 *
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row, string $prefix = ''): ?self
	{
		$id = $row[$prefix . 'id'] ?? null;

		if ($id === null) {
			return null;
		}

		$int = static fn (string $column): ?int => isset($row[$prefix . $column]) ? (int) $row[$prefix . $column] : null;
		$string = static fn (string $column): ?string => isset($row[$prefix . $column]) ? (string) $row[$prefix . $column] : null;

		return new self(
			id: (int) $id,
			keyword: (string) ($row[$prefix . 'keyword'] ?? ''),
			searchVolume: $int('search_volume'),
			cpc: isset($row[$prefix . 'cpc']) ? (float) $row[$prefix . 'cpc'] : null,
			competitionLevel: $string('competition_level'),
			competitionIndex: $int('competition_index'),
			keywordDifficulty: $int('keyword_difficulty'),
			volumeFetchedAt: $string('volume_fetched_at'),
			volumeStaleAfter: $string('volume_stale_after'),
			difficultyFetchedAt: $string('difficulty_fetched_at'),
			difficultyStaleAfter: $string('difficulty_stale_after'),
		);
	}

	public function volumeFetched(): bool
	{
		return $this->volumeFetchedAt !== null;
	}

	public function difficultyFetched(): bool
	{
		return $this->difficultyFetchedAt !== null;
	}

	public function isVolumeStale(string $now): bool
	{
		return $this->volumeStaleAfter === null || $this->volumeStaleAfter <= $now;
	}

	public function isDifficultyStale(string $now): bool
	{
		return $this->difficultyStaleAfter === null || $this->difficultyStaleAfter <= $now;
	}

	/** Ostatnia aktualizacja którejkolwiek metryki (UTC). */
	public function updatedAt(): ?string
	{
		$dates = array_filter([$this->volumeFetchedAt, $this->difficultyFetchedAt]);

		return $dates === [] ? null : max($dates);
	}

	/** Kolumny do SELECT z aliasami `{prefix}…` (raport fraz, wyszukiwanie po kluczach). */
	public static function columns(string $alias, string $prefix): string
	{
		$columns = ['id', 'keyword', 'search_volume', 'cpc', 'competition_level', 'competition_index', 'keyword_difficulty',
			'volume_fetched_at', 'volume_stale_after', 'difficulty_fetched_at', 'difficulty_stale_after'];

		return implode(', ', array_map(static fn (string $column): string => "{$alias}.{$column} AS {$prefix}{$column}", $columns));
	}
}
