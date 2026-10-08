<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

/**
 * Overlap SERP dwóch fraz na poziomie URL-i i domen TOP10 (czysta logika — docs/ARCHITECTURE.md, sekcja 15.12, D60).
 *
 * Zabezpieczenia przed fałszywym scaleniem:
 * - porównanie tylko pomiarów w tym samym kontekście (lokalizacja, język, urządzenie) i nie wygasłych (> 90 dni) — inaczej „nieporównywalne”,
 * - wspólne URL-e domen wszechobecnych (np. ta sama encyklopedia czy marketplace w większości SERP-ów projektu) i strony główne są liczone
 *   osobno i nie wchodzą do progu silnego overlapu,
 * - silny overlap (≥ 4 wspólne URL-e TOP10 po odliczeniach) wymaga świeżych pomiarów (≤ 30 dni) i braku sprzeczności intencji —
 *   inaczej najwyżej „umiarkowany” (tylko „możliwa grupa”, bez automatycznego scalania),
 * - wspólne domeny (bez wszechobecnych) to sygnał słaby — nigdy podstawa scalenia.
 */
final class SerpOverlap
{
	public const STRONG = 'strong';

	public const MODERATE = 'moderate';

	public const WEAK = 'weak';

	public const NONE = 'none';

	public const INCOMPARABLE = 'incomparable';

	/** Wspólne URL-e TOP10 (po odliczeniach) dla silnego overlapu — D60. */
	public const STRONG_URLS = 4;

	public const MODERATE_URLS = 2;

	/** Wspólne domeny TOP10 (bez wszechobecnych) dla słabego sygnału. */
	public const WEAK_DOMAINS = 3;

	/**
	 * @param array<int, bool> $ubiquitous identyfikatory domen wszechobecnych w SERP-ach projektu
	 * @param bool $ubiquityKnown czy było dość pomiarów, by rozpoznać domeny wszechobecne
	 * @return array{level: string, mergeable: bool, shared_urls: int, counted_urls: int, discounted_urls: int, shared_domains: int, score: float, reasons: list<string>, urls: list<array{url_id: int, rank_a: int, rank_b: int, counted: bool}>}
	 */
	public static function compare(OverlapSide $a, OverlapSide $b, array $ubiquitous = [], bool $ubiquityKnown = true): array
	{
		$result = ['level' => self::INCOMPARABLE, 'mergeable' => false, 'shared_urls' => 0, 'counted_urls' => 0, 'discounted_urls' => 0, 'shared_domains' => 0, 'score' => 0.0, 'reasons' => [], 'urls' => []];

		if ($a->snapshotId === null || $b->snapshotId === null) {
			return ['reasons' => ['no_measurement']] + $result;
		}

		if ($a->contextKey !== $b->contextKey) {
			return ['reasons' => ['context_mismatch']] + $result;
		}

		if (! SerpFreshness::usableForClassification($a->freshness) || ! SerpFreshness::usableForClassification($b->freshness)) {
			return ['reasons' => ['expired_measurement']] + $result;
		}

		$urls = [];
		$counted = 0;
		$discounted = 0;

		foreach (array_intersect_key($a->top10, $b->top10) as $urlId => $first) {
			$second = $b->top10[$urlId];
			$isCounted = ! isset($ubiquitous[$first['domain_id']]) && ! $first['home'];
			$counted += $isCounted ? 1 : 0;
			$discounted += $isCounted ? 0 : 1;
			$urls[] = ['url_id' => (int) $urlId, 'rank_a' => $first['rank'], 'rank_b' => $second['rank'], 'counted' => $isCounted];
		}

		usort($urls, static fn (array $x, array $y): int => [$x['rank_a'] + $x['rank_b'], $x['url_id']] <=> [$y['rank_a'] + $y['rank_b'], $y['url_id']]);
		$domains = static fn (OverlapSide $side): array => array_fill_keys(array_map(static fn (array $row): int => $row['domain_id'], array_values($side->top10)), true);
		$sharedDomains = count(array_diff_key(array_intersect_key($domains($a), $domains($b)), $ubiquitous));
		$reasons = [];

		$level = match (true) {
			$counted >= self::STRONG_URLS => self::STRONG,
			$counted >= self::MODERATE_URLS => self::MODERATE,
			$counted >= 1 || $sharedDomains >= self::WEAK_DOMAINS => self::WEAK,
			default => self::NONE,
		};

		if ($discounted > 0) {
			$reasons[] = 'discounted_ubiquitous_or_home';
		}

		if (! $ubiquityKnown) {
			$reasons[] = 'ubiquity_unknown';
		}

		$caps = [];

		if ($a->freshness !== SerpFreshness::FRESH || $b->freshness !== SerpFreshness::FRESH) {
			$caps[] = 'stale_measurement';
		}

		if ($a->providerIntent !== null && $b->providerIntent !== null && $a->providerIntent !== $b->providerIntent) {
			$caps[] = 'provider_intent_mismatch';
		}

		if (self::decisive($a) && self::decisive($b) && $a->serpIntent !== $b->serpIntent) {
			$caps[] = 'serp_intent_mismatch';
		}

		if ($level === self::STRONG && $caps !== []) {
			$level = self::MODERATE;
		}

		return [
			'level' => $level,
			'mergeable' => $level === self::STRONG,
			'shared_urls' => count($urls),
			'counted_urls' => $counted,
			'discounted_urls' => $discounted,
			'shared_domains' => $sharedDomains,
			'score' => round($counted / 10, 2),
			'reasons' => [...$reasons, ...$caps],
			'urls' => $urls,
		];
	}

	/**
	 * Domeny wszechobecne: obecne w TOP10 co najmniej `max(4, 40%)` pomiarów projektu w tym samym kontekście — rozpoznawane tylko
	 * przy co najmniej 10 pomiarach. Na mniejszej próbie (np. kilka fraz jednego tematu) wspólni konkurenci tematyczni wyglądaliby na
	 * wszechobecnych i blokowaliby prawdziwy overlap — wtedy „nieznane” (wspólne URL-e stron głównych i tak nie wchodzą do progu).
	 *
	 * @param list<array<int, bool>> $domainSets domeny TOP10 każdego pomiaru
	 * @return array{domains: array<int, bool>, known: bool, measurements: int, threshold: int}
	 */
	public static function ubiquitous(array $domainSets, int $minMeasurements = 10, float $share = 0.4, int $minCount = 4): array
	{
		$total = count($domainSets);
		$threshold = max($minCount, (int) ceil($share * $total));

		if ($total < $minMeasurements) {
			return ['domains' => [], 'known' => false, 'measurements' => $total, 'threshold' => $threshold];
		}

		$counts = [];

		foreach ($domainSets as $set) {
			foreach (array_keys($set) as $domainId) {
				$counts[$domainId] = ($counts[$domainId] ?? 0) + 1;
			}
		}

		$domains = [];

		foreach ($counts as $domainId => $count) {
			if ($count >= $threshold) {
				$domains[(int) $domainId] = true;
			}
		}

		ksort($domains);

		return ['domains' => $domains, 'known' => true, 'measurements' => $total, 'threshold' => $threshold];
	}

	public static function label(string $level): string
	{
		return match ($level) {
			self::STRONG => 'silny',
			self::MODERATE => 'umiarkowany',
			self::WEAK => 'słaby',
			self::NONE => 'brak',
			default => 'nieporównywalne',
		};
	}

	private static function decisive(OverlapSide $side): bool
	{
		return $side->serpIntent !== null && $side->serpIntent->isDecisive()
			&& $side->serpIntentConfidence !== null && $side->serpIntentConfidence !== SerpConfidence::LOW;
	}
}
