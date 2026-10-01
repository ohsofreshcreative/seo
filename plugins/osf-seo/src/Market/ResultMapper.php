<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Mapowanie fraz zwróconych przez dostawcę na frazy wysłane. Dostawca może zwrócić frazę w zmienionej postaci
 * (np. małe litery, bez kropki lub myślnika), więc:
 * 1. dokładnie po postaci znormalizowanej (`MarketKeyword::normalize`),
 * 2. pomocniczo po postaci luźnej (bez interpunkcji) — tylko gdy jednoznacznie wskazuje jedną wysłaną frazę,
 * 3. pozostałe wyniki są pomijane (liczone jako niedopasowane); wysłana fraza bez wyniku = „brak danych”.
 */
final class ResultMapper
{
	/**
	 * @param list<string> $requested postacie znormalizowane wysłane do dostawcy
	 * @param list<string> $returned frazy z odpowiedzi (indeks = pozycja wyniku)
	 * @return array{0: array<int, string>, 1: int} indeks wyniku → wysłana fraza, liczba niedopasowanych wyników
	 */
	public static function map(array $requested, array $returned): array
	{
		$exact = [];
		$loose = [];

		foreach ($requested as $keyword) {
			$normalized = MarketKeyword::normalize($keyword);
			$exact[$normalized] = $keyword;
			$loose[MarketKeyword::loose($keyword)][] = $keyword;
		}

		$used = [];
		$mapped = [];
		$unmatched = 0;

		foreach ($returned as $index => $keyword) {
			$normalized = MarketKeyword::normalize($keyword);

			if (isset($exact[$normalized]) && ! isset($used[$exact[$normalized]])) {
				$mapped[$index] = $exact[$normalized];
				$used[$exact[$normalized]] = true;

				continue;
			}

			$candidates = $loose[MarketKeyword::loose($keyword)] ?? [];

			if (count($candidates) === 1 && ! isset($used[$candidates[0]])) {
				$mapped[$index] = $candidates[0];
				$used[$candidates[0]] = true;

				continue;
			}

			$unmatched++;
		}

		return [$mapped, $unmatched];
	}
}
