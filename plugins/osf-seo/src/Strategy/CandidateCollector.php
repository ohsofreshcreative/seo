<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Scalanie sygnałów źródeł w kandydatów Strategii (czysta logika, bez bazy — docs/ARCHITECTURE.md, sekcja 15.2):
 *
 * 1. jedna fraza rynkowa (klucz hex) z kilku źródeł = jeden kandydat z maską źródeł,
 * 2. poziom kandydata = najważniejszy poziom jego sygnałów, waga = największa waga sygnałów tego poziomu,
 * 3. filtry marki i wykluczeń — poza frazami dodanymi ręcznie (jawna decyzja użytkownika),
 * 4. kolejność: poziom rosnąco, waga malejąco, klucz (stabilnie); najwyżej limit wybranych, reszta = nadmiar (liczony).
 */
final class CandidateCollector
{
	/**
	 * @param list<SignalBatch> $batches
	 */
	public function collect(array $batches, CandidateFilter $filter, int $limit): CandidateSet
	{
		/** @var array<string, array{id: ?int, keyword: string, intent: ?string, sources: int, tier: int, weight: float, weights: array<string, float>}> $merged */
		$merged = [];
		$signals = [];
		$omitted = 0;

		foreach ($batches as $batch) {
			$omitted += $batch->omitted;

			foreach ($batch->signals as $signal) {
				$code = $signal->source->value;
				$signals[$code] = ($signals[$code] ?? 0) + 1;
				$key = (string) $signal->keyHex;
				$entry = $merged[$key] ?? null;

				if ($entry === null) {
					$merged[$key] = [
						'id' => $signal->marketKeywordId,
						'keyword' => $signal->keyword,
						'intent' => $signal->intent,
						'sources' => $signal->source->bit(),
						'tier' => $signal->tier,
						'weight' => $signal->weight,
						'weights' => [$code => $signal->weight],
					];

					continue;
				}

				$entry['id'] ??= $signal->marketKeywordId;
				$entry['intent'] ??= $signal->intent;
				$entry['sources'] |= $signal->source->bit();
				$entry['weights'][$code] = max($entry['weights'][$code] ?? $signal->weight, $signal->weight);

				if ($signal->tier < $entry['tier']) {
					$entry['tier'] = $signal->tier;
					$entry['weight'] = $signal->weight;
				} elseif ($signal->tier === $entry['tier']) {
					$entry['weight'] = max($entry['weight'], $signal->weight);
				}

				$merged[$key] = $entry;
			}
		}

		$kept = [];
		$filtered = [];

		foreach ($merged as $key => $entry) {
			ksort($entry['weights']);
			$candidate = new Candidate((string) $key, $entry['id'], $entry['keyword'], $entry['intent'], $entry['sources'], $entry['tier'], $entry['weight'], $entry['weights']);
			$reason = $candidate->has(StrategySource::Manual) ? null : $filter->reason($candidate->keyword, $candidate->intent);

			if ($reason !== null) {
				$filtered[(string) $key] = ['candidate' => $candidate, 'reason' => $reason];

				continue;
			}

			$kept[] = $candidate;
		}

		usort($kept, static fn (Candidate $a, Candidate $b): int => [$a->tier, $b->weight, $a->keyHex] <=> [$b->tier, $a->weight, $b->keyHex]);
		ksort($signals);

		return new CandidateSet(array_slice($kept, 0, $limit), array_slice($kept, $limit), $filtered, $signals, $omitted, $limit);
	}
}
