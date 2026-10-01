<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Trudność SEO (Keyword Difficulty 0–100, szacunek trudności wejścia do organicznego TOP 10) dla paczki fraz.
 * Fraza bez wyniku albo z `null` — dostawca nie ma danych.
 */
final class DifficultyBatch
{
	/**
	 * @param list<array{keyword: string, difficulty: ?int}> $items fraza w postaci zwróconej przez dostawcę
	 */
	public function __construct(
		public readonly array $items,
		public readonly ?float $cost = null,
	) {
	}
}
