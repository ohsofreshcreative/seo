<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Wynik zbierania kandydatów: wybrani (w kolejności poziomu i wagi, najwyżej limit), nadmiar i odfiltrowani — z liczbami,
 * żeby nic nie było pomijane po cichu.
 */
final class CandidateSet
{
	/**
	 * @param list<Candidate> $selected
	 * @param list<Candidate> $overflow kandydaci ponad limit (zwróceni przez źródła)
	 * @param array<string, array{candidate: Candidate, reason: string}> $filtered klucz hex → kandydat i powód
	 * @param array<string, int> $signals kod źródła → liczba sygnałów
	 * @param int $omitted frazy spełniające kryteria źródeł, niezwrócone (i tak ponad limitem)
	 */
	public function __construct(
		public readonly array $selected,
		public readonly array $overflow,
		public readonly array $filtered,
		public readonly array $signals,
		public readonly int $omitted,
		public readonly int $limit,
	) {
	}

	public function overflowCount(): int
	{
		return count($this->overflow) + $this->omitted;
	}

	/**
	 * @return array<string, int> powód → liczba
	 */
	public function filteredByReason(): array
	{
		$counts = [];

		foreach ($this->filtered as $entry) {
			$counts[$entry['reason']] = ($counts[$entry['reason']] ?? 0) + 1;
		}

		ksort($counts);

		return $counts;
	}

	/**
	 * Wybrani kandydaci według źródła (fraza z kilku źródeł liczy się w każdym).
	 *
	 * @return array<string, int>
	 */
	public function selectedBySource(): array
	{
		$counts = array_fill_keys(StrategySource::values(), 0);

		foreach ($this->selected as $candidate) {
			foreach ($candidate->sourceCodes() as $code) {
				$counts[$code]++;
			}
		}

		return $counts;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function stats(): array
	{
		return [
			'selected' => count($this->selected),
			'limit' => $this->limit,
			'overflow' => $this->overflowCount(),
			'filtered' => $this->filteredByReason(),
			'signals' => $this->signals,
			'selected_by_source' => $this->selectedBySource(),
			'new_market_keywords' => count(array_filter($this->selected, static fn (Candidate $candidate): bool => $candidate->marketKeywordId === null)),
		];
	}
}
