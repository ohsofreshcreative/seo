<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Parametry importu fraz konkurencji podane w panelu lub CLI — po walidacji i przycięciu do limitów.
 */
final class GapRequest
{
	/**
	 * @param list<string> $competitors identyfikatory publiczne konkurentów; pusta lista = wszyscy aktywni
	 */
	public function __construct(
		public readonly array $competitors,
		public readonly Coverage $coverage,
		/** Punkt odniesienia projektu (Ranked Keywords domeny projektu, TOP100). */
		public readonly bool $baseline = true,
		/** Pobierz ponownie także świeże zbiory (wymaga uprawnienia — sprawdza usługa). */
		public readonly bool $force = false,
	) {
	}

	/**
	 * Z formularza albo argumentów CLI: `competitors[]` (albo tekst rozdzielony przecinkami), `preset`
	 * (quick/standard/full) albo `max_rank`, `min_volume`, `max_rows`, `baseline` ('0' = bez), `force`.
	 *
	 * @param array<string, mixed> $input
	 */
	public static function fromInput(array $input, GapSettings $defaults, int $maxRowsPerDomain): self
	{
		$competitors = $input['competitors'] ?? [];
		$competitors = is_string($competitors) ? (preg_split('/[\s,;]+/', $competitors) ?: []) : (is_array($competitors) ? $competitors : []);
		$competitors = array_values(array_unique(array_filter(array_map(
			static fn (mixed $id): string => is_string($id) ? strtoupper(trim($id)) : '',
			$competitors,
		), static fn (string $id): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1)));

		[$maxRank, $minVolume, $maxRows] = [$defaults->fetchMaxRank, $defaults->fetchMinVolume, $defaults->maxRows];
		$preset = is_string($input['preset'] ?? null) ? $input['preset'] : null;

		if ($preset !== null && isset(GapConfig::PRESETS[$preset])) {
			[$maxRank, $minVolume, $maxRows] = GapConfig::PRESETS[$preset];
		}

		$rank = filter_var($input['max_rank'] ?? null, FILTER_VALIDATE_INT);
		$volume = filter_var($input['min_volume'] ?? null, FILTER_VALIDATE_INT);
		$rows = filter_var($input['max_rows'] ?? null, FILTER_VALIDATE_INT);
		$maxRank = $rank !== false && in_array($rank, GapConfig::FETCH_RANKS, true) ? $rank : $maxRank;
		$minVolume = $volume !== false ? max(0, min(100000, $volume)) : $minVolume;
		$maxRows = $rows !== false ? $rows : $maxRows;

		return new self(
			competitors: $competitors,
			coverage: new Coverage($maxRank, $minVolume, max(100, min($maxRowsPerDomain, $maxRows))),
			baseline: ! in_array($input['baseline'] ?? null, ['0', 0, false, 'no'], true),
			force: in_array($input['force'] ?? null, ['1', 1, true, 'yes'], true),
		);
	}

	/**
	 * Pola formularza do potwierdzenia podglądu (ten sam plan musi zostać przeliczony przy uruchomieniu).
	 *
	 * @return array<string, string|list<string>>
	 */
	public function toInput(): array
	{
		return [
			'competitors' => $this->competitors,
			'max_rank' => (string) $this->coverage->maxRank,
			'min_volume' => (string) $this->coverage->minVolume,
			'max_rows' => (string) $this->coverage->maxRows,
			'baseline' => $this->baseline ? '1' : '0',
			'force' => $this->force ? '1' : '0',
		];
	}
}
