<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Parametry wyszukiwania podane przez użytkownika (panel lub CLI) — po walidacji i przycięciu do limitów.
 */
final class DiscoveryRequest
{
	public function __construct(
		public readonly SeedList $seeds,
		public readonly DiscoveryMethod $method,
		/** Głębokość powiązań (tylko Related Keywords). */
		public readonly ?int $depth,
		public readonly int $maxCandidates,
		public readonly int $minVolume,
		public readonly ?int $maxDifficulty,
		/** Pomijaj frazy, które dostawca rozpoznał jako inny język niż język rynku. */
		public readonly bool $skipOtherLanguage = true,
		/** Pobierz ponownie także seedy aktualne w cache (wymaga uprawnienia — sprawdza usługa). */
		public readonly bool $force = false,
	) {
	}

	/**
	 * Z formularza panelu albo argumentów CLI: `seeds` (tekst), `seeds_gsc[]`, `seeds_opportunity[]`, `method`, `depth`,
	 * `max_candidates`, `min_volume`, `max_kd`, `other_language` ('1' = uwzględniaj), `force`.
	 *
	 * @param array<string, mixed> $input
	 * @param \Closure(string): bool $accepts reguła dostawcy dla seeda
	 */
	public static function fromInput(array $input, DiscoveryConfig $config, \Closure $accepts): self
	{
		$sources = [SeedList::SOURCE_MANUAL => is_string($input['seeds'] ?? null) ? $input['seeds'] : ''];

		foreach ([SeedList::SOURCE_GSC => 'seeds_gsc', SeedList::SOURCE_OPPORTUNITY => 'seeds_opportunity'] as $source => $field) {
			$value = $input[$field] ?? [];
			$sources[$source] = is_array($value) ? array_values(array_filter($value, 'is_string')) : (is_string($value) ? $value : []);
		}

		$method = DiscoveryMethod::fromInput($input['method'] ?? null) ?? DiscoveryMethod::Related;
		$depth = filter_var($input['depth'] ?? null, FILTER_VALIDATE_INT);
		$candidates = filter_var($input['max_candidates'] ?? null, FILTER_VALIDATE_INT);
		$minVolume = filter_var($input['min_volume'] ?? null, FILTER_VALIDATE_INT);
		$maxKd = filter_var($input['max_kd'] ?? null, FILTER_VALIDATE_INT);

		return new self(
			seeds: SeedList::parse($sources, $accepts, $config->maxSeeds()),
			method: $method,
			depth: $method->usesDepth() ? (in_array($depth, DiscoveryConfig::DEPTHS, true) ? $depth : DiscoveryConfig::DEFAULT_DEPTH) : null,
			maxCandidates: $candidates === false ? min(DiscoveryConfig::DEFAULT_CANDIDATES, $config->maxCandidates()) : max(10, min($config->maxCandidates(), $candidates)),
			minVolume: $minVolume === false ? $config->minVolume() : max(0, min(100000, $minVolume)),
			maxDifficulty: $maxKd === false || $maxKd >= 100 ? null : max(0, $maxKd),
			skipOtherLanguage: ! in_array($input['other_language'] ?? null, ['1', 1, true, 'yes'], true),
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
		$bySource = [];

		foreach ($this->seeds->accepted as $seed => $source) {
			$bySource[$source][] = $seed;
		}

		return array_filter([
			'seeds' => implode("\n", $bySource[SeedList::SOURCE_MANUAL] ?? []),
			'seeds_gsc' => $bySource[SeedList::SOURCE_GSC] ?? [],
			'seeds_opportunity' => $bySource[SeedList::SOURCE_OPPORTUNITY] ?? [],
			'method' => $this->method->value,
			'depth' => $this->depth === null ? '' : (string) $this->depth,
			'max_candidates' => (string) $this->maxCandidates,
			'min_volume' => (string) $this->minVolume,
			'max_kd' => $this->maxDifficulty === null ? '' : (string) $this->maxDifficulty,
			'other_language' => $this->skipOtherLanguage ? '' : '1',
			'force' => $this->force ? '1' : '',
		], static fn (string|array $value): bool => $value !== '' && $value !== []);
	}
}
