<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Strategy\Serp\OverlapSide;
use OsfSeo\Strategy\Serp\SerpOverlap;

/**
 * Overlap SERP fraz projektu na potrzeby grupowania (faza C): strony TOP10 najnowszych zgodnych pomiarów (`OverlapSide`), domeny
 * wszechobecne i porównania `SerpOverlap::compare` (progi i zabezpieczenia fazy B bez zmian, D64) — z pamięcią wyników i indeksem
 * odwrotnym (porównujemy tylko frazy ze wspólnym adresem TOP10).
 */
final class OverlapIndex
{
	/** @var array<int, list<int>> id adresu → frazy */
	private array $byUrl = [];

	/** @var array<string, array<string, mixed>> */
	private array $cache = [];

	/**
	 * @param array<int, OverlapSide> $sides id frazy rynkowej → strona porównania (tylko frazy ze zgodnym pomiarem)
	 * @param array<int, bool> $ubiquitous
	 */
	public function __construct(
		private readonly array $sides = [],
		private readonly array $ubiquitous = [],
		private readonly bool $ubiquityKnown = false,
	) {
		foreach ($sides as $id => $side) {
			foreach (array_keys($side->top10) as $urlId) {
				$this->byUrl[(int) $urlId][] = (int) $id;
			}
		}
	}

	public function has(int $marketKeywordId): bool
	{
		return isset($this->sides[$marketKeywordId]);
	}

	/**
	 * Frazy ze wspólnym adresem TOP10 (kandydaci do porównania), rosnąco.
	 *
	 * @return list<int>
	 */
	public function sharing(int $marketKeywordId): array
	{
		$side = $this->sides[$marketKeywordId] ?? null;

		if ($side === null) {
			return [];
		}

		$result = [];

		foreach (array_keys($side->top10) as $urlId) {
			foreach ($this->byUrl[(int) $urlId] ?? [] as $other) {
				if ($other !== $marketKeywordId) {
					$result[$other] = true;
				}
			}
		}

		$ids = array_keys($result);
		sort($ids);

		return $ids;
	}

	/**
	 * Wynik `SerpOverlap::compare` albo null (fraza bez zgodnego pomiaru).
	 *
	 * @return array<string, mixed>|null
	 */
	public function compare(int $a, int $b): ?array
	{
		if (! isset($this->sides[$a], $this->sides[$b])) {
			return null;
		}

		$key = min($a, $b) . ':' . max($a, $b);

		return $this->cache[$key] ??= SerpOverlap::compare($this->sides[min($a, $b)], $this->sides[max($a, $b)], $this->ubiquitous, $this->ubiquityKnown);
	}
}
