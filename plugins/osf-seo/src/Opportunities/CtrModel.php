<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Referencyjny CTR zależny od średniej pozycji (GSC) — punkt odniesienia dla „niskiego CTR”
 * i szacunku potencjału kliknięć. CTR silnie zależy od pozycji, więc jeden próg (np. „CTR < 2%”)
 * dla wszystkich fraz byłby błędny.
 *
 * Dla każdego przedziału pozycji: mediana CTR fraz projektu w okresie (CTR frazy = jej kliknięcia /
 * jej wyświetlenia, tylko frazy z co najmniej `reference_min_impressions` wyświetleniami), gdy próbka ma
 * co najmniej `reference_min_keywords` fraz; inaczej ostrożna wartość domyślna. To rozkład referencyjny,
 * a nie CTR okresu — CTR okresów i grup jest zawsze liczony z sum (kliknięcia / wyświetlenia).
 * Mediana zamiast sumy ważonej: kilka fraz brandowych z bardzo wysokim CTR nie zawyża punktu odniesienia.
 */
final class CtrModel
{
	/**
	 * Przedziały średniej pozycji (górna granica wyłączna — pozycje GSC są średnimi, granice w połowie):
	 * [górna granica, etykieta, domyślny CTR].
	 */
	public const BUCKETS = [
		[1.5, '1', 0.28],
		[3.5, '2–3', 0.14],
		[5.5, '4–5', 0.07],
		[10.5, '6–10', 0.03],
		[20.5, '11–20', 0.01],
		[50.5, '21–50', 0.004],
		[INF, '51–100', 0.001],
	];

	/** Przedział celu „TOP 3” (pozycje 2–3), „TOP 10” (6–10) i „TOP 20” (11–20) — do szacunku potencjału. */
	public const TARGET_TOP3 = 1;

	public const TARGET_TOP10 = 3;

	public const TARGET_TOP20 = 4;

	/**
	 * @param list<array{ctr: float, source: string, keywords: int}> $references
	 */
	private function __construct(private readonly array $references)
	{
	}

	/**
	 * @param iterable<Stats> $samples statystyki fraz w jednym okresie
	 */
	public static function fromSamples(iterable $samples, int $minImpressions, int $minKeywords): self
	{
		$values = array_fill(0, count(self::BUCKETS), []);

		foreach ($samples as $stats) {
			$position = $stats->position();

			if ($position === null || $stats->impressions < max(1, $minImpressions)) {
				continue;
			}

			$values[self::bucketIndex($position)][] = $stats->clicks / $stats->impressions;
		}

		$references = [];

		foreach (self::BUCKETS as $index => [, , $default]) {
			$sample = $values[$index];
			$references[] = count($sample) >= max(1, $minKeywords)
				? ['ctr' => self::median($sample), 'source' => 'project', 'keywords' => count($sample)]
				: ['ctr' => $default, 'source' => 'default', 'keywords' => count($sample)];
		}

		return new self($references);
	}

	/** Model wyłącznie z wartości domyślnych (np. brak danych). */
	public static function defaults(): self
	{
		return self::fromSamples([], 1, 1);
	}

	public static function bucketIndex(float $position): int
	{
		foreach (self::BUCKETS as $index => [$max]) {
			if ($position < $max) {
				return $index;
			}
		}

		return count(self::BUCKETS) - 1;
	}

	public static function bucketLabel(int $index): string
	{
		return self::BUCKETS[$index][1] ?? '';
	}

	/** Referencyjny CTR dla średniej pozycji. */
	public function reference(float $position): float
	{
		return $this->references[self::bucketIndex($position)]['ctr'];
	}

	/** Referencyjny CTR przedziału (np. celu TOP 3). */
	public function bucketReference(int $index): float
	{
		return $this->references[$index]['ctr'];
	}

	/**
	 * @return list<array{label: string, ctr: float, source: string, keywords: int}>
	 */
	public function toArray(): array
	{
		$rows = [];

		foreach ($this->references as $index => $reference) {
			$rows[] = ['label' => self::bucketLabel($index), 'ctr' => round($reference['ctr'], 6), 'source' => $reference['source'], 'keywords' => $reference['keywords']];
		}

		return $rows;
	}

	/**
	 * @param list<float> $values
	 */
	private static function median(array $values): float
	{
		sort($values);
		$count = count($values);
		$middle = intdiv($count, 2);

		return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
	}
}
