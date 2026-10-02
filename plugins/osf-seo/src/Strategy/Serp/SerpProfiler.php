<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

use OsfSeo\Serp\ItemTypes;

/**
 * Profil zakończonego pomiaru z jego wyników organicznych (czysta logika — docs/ARCHITECTURE.md, sekcja 15.12):
 *
 * 1. kształt każdego wyniku TOP20 (`ResultShapeClassifier`, z pewnością i powodem),
 * 2. kompozycja TOP10/TOP20: liczba wyników i domen, najwięcej wyników jednej domeny w TOP10, strony główne w TOP10,
 * 3. dominujący kształt TOP10: udział ≥ 40% i bez remisu (inaczej „mieszany”); pewność wysoka tylko przy udziale ≥ 60%, ≥ 7 wynikach i w większości
 *    pewnych klasyfikacjach (średnio ≥ 0,8), średnia przy średniej pewności klasyfikacji ≥ 0,45,
 * 4. cechy strony wyników z elementów SERP (wyniki lokalne, zakupy, wideo, wiadomości, pytania, reklamy, AI Overview),
 * 5. sygnał intencji z SERP — reguły w stałej kolejności (nawigacyjna → lokalna → transakcyjna → informacyjna → komercyjna → mieszana).
 */
final class SerpProfiler
{
	/** Wersja reguł profilu — zmiana unieważnia zapisane profile (przeliczane przy odczycie). */
	public const VERSION = 1;

	/** Minimalna liczba wyników organicznych TOP10 do klasyfikacji kształtu. */
	public const MIN_ORGANIC = 3;

	/** Minimalny udział kształtu, by uznać go za dominujący. */
	public const DOMINANT_SHARE = 0.4;

	/** Grupy elementów SERP (`ItemTypes`) → cecha strony wyników. */
	public const FEATURES = [
		'local' => ['local_pack', 'map', 'local_services', 'hotels_pack', 'google_hotels'],
		'shopping' => ['shopping', 'popular_products', 'commercial_units', 'refine_products', 'product_considerations', 'compare_sites'],
		'video' => ['video', 'short_videos'],
		'news' => ['top_stories'],
		'questions' => ['people_also_ask', 'featured_snippet', 'answer_box', 'knowledge_graph', 'questions_and_answers', 'discussions_and_forums'],
		'ads' => ['paid'],
		'ai_overview' => ['ai_overview'],
	];

	public function __construct(private readonly ResultShapeClassifier $classifier = new ResultShapeClassifier())
	{
	}

	/**
	 * @param list<array{rank: int, host: string, url: string, flags: int, published_at: ?string}> $organic wyniki organiczne pomiaru
	 */
	public function profile(int $snapshotId, array $organic, int $itemTypes): SerpProfile
	{
		usort($organic, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
		$results = [];
		$hosts10 = [];
		$hosts20 = [];
		$shapes10 = [];
		$shapes20 = [];
		$weights10 = [];
		$home10 = 0;

		foreach ($organic as $row) {
			if ($row['rank'] > 20) {
				break;
			}

			$class = $this->classifier->classify($row['url'], $row['host'], $row['flags'], $row['published_at']);
			$shape = $class['shape']->value;
			$results[] = [$row['rank'], $shape, $class['confidence'], $class['reason']];
			$hosts20[$row['host']] = true;
			$shapes20[$shape] = ($shapes20[$shape] ?? 0) + 1;

			if ($row['rank'] <= 10) {
				$hosts10[$row['host']] = ($hosts10[$row['host']] ?? 0) + 1;
				$shapes10[$shape] = ($shapes10[$shape] ?? 0) + 1;
				$weights10[$shape][] = SerpConfidence::weight($class['confidence']);
				$home10 += $class['shape'] === ResultShape::Home ? 1 : 0;
			}
		}

		$top10 = array_sum($shapes10);
		$features = self::features($itemTypes);
		[$shape, $share, $shapeConfidence] = self::dominant($shapes10, $weights10, $top10);
		$topDomain = $hosts10 === [] ? 0 : max($hosts10);
		[$intent, $intentConfidence] = self::intent($shapes10, $top10, $topDomain, $features);

		return new SerpProfile(
			$snapshotId,
			self::VERSION,
			$top10,
			count($results),
			count($hosts10),
			count($hosts20),
			$topDomain,
			$home10,
			$shape,
			$share,
			$shapeConfidence,
			$intent,
			$intentConfidence,
			self::ordered($shapes10),
			self::ordered($shapes20),
			$results,
			$features,
		);
	}

	/**
	 * @return list<string>
	 */
	public static function features(int $itemTypes): array
	{
		$types = array_flip(ItemTypes::fromMask($itemTypes));
		$features = [];

		foreach (self::FEATURES as $feature => $members) {
			foreach ($members as $member) {
				if (isset($types[$member])) {
					$features[] = $feature;

					break;
				}
			}
		}

		return $features;
	}

	/**
	 * @param array<string, int> $shapes
	 * @param array<string, list<float>> $weights
	 * @return array{0: string, 1: ?float, 2: string}
	 */
	private static function dominant(array $shapes, array $weights, int $total): array
	{
		if ($total < self::MIN_ORGANIC) {
			return [ResultShape::Unknown->value, null, SerpConfidence::LOW];
		}

		$best = null;

		foreach (ResultShape::values() as $shape) {
			if (($shapes[$shape] ?? 0) > ($best === null ? 0 : $shapes[$best])) {
				$best = $shape;
			}
		}

		$share = round(($shapes[(string) $best] ?? 0) / $total, 3);
		// Remis dwóch kształtów (np. 5 artykułów i 5 podstron) to strona wyników mieszana — bez rozstrzygania kolejnością.
		$tied = count(array_filter($shapes, static fn (int $count): bool => $best !== null && $count === $shapes[$best])) > 1;

		if ($best === null || $tied || $share < self::DOMINANT_SHARE) {
			return ['mixed', $share, $total >= 8 ? SerpConfidence::MEDIUM : SerpConfidence::LOW];
		}

		$certainty = array_sum($weights[$best]) / count($weights[$best]);
		$confidence = match (true) {
			$share >= 0.6 && $total >= 7 && $certainty >= 0.8 => SerpConfidence::HIGH,
			$certainty >= 0.45 => SerpConfidence::MEDIUM,
			default => SerpConfidence::LOW,
		};

		return [$best, $share, $confidence];
	}

	/**
	 * @param array<string, int> $shapes
	 * @param list<string> $features
	 * @return array{0: SerpIntentSignal, 1: string}
	 */
	private static function intent(array $shapes, int $total, int $topDomain, array $features): array
	{
		if ($total < self::MIN_ORGANIC) {
			return [SerpIntentSignal::Unknown, SerpConfidence::LOW];
		}

		$share = static fn (string ...$names): float => array_sum(array_map(static fn (string $name): int => $shapes[$name] ?? 0, $names)) / $total;
		$informational = $share(ResultShape::Article->value, ResultShape::Video->value);
		$transactional = $share(ResultShape::Product->value, ResultShape::Listing->value);
		$commercial = $share(ResultShape::Subpage->value, ResultShape::Home->value);
		$has = static fn (string $feature): bool => in_array($feature, $features, true);

		return match (true) {
			$topDomain >= 5 => [SerpIntentSignal::Navigational, $topDomain >= 7 ? SerpConfidence::HIGH : SerpConfidence::MEDIUM],
			$has('local') => [SerpIntentSignal::Local, $commercial + $transactional >= 0.5 ? SerpConfidence::HIGH : SerpConfidence::MEDIUM],
			$has('shopping') && $transactional >= 0.3 => [SerpIntentSignal::Transactional, $transactional >= 0.5 ? SerpConfidence::HIGH : SerpConfidence::MEDIUM],
			$transactional >= 0.5 => [SerpIntentSignal::Transactional, $transactional >= 0.7 ? SerpConfidence::HIGH : SerpConfidence::MEDIUM],
			$informational >= 0.5 => [SerpIntentSignal::Informational, $informational >= 0.7 || ($informational >= 0.6 && $has('questions')) ? SerpConfidence::HIGH : SerpConfidence::MEDIUM],
			// Podstrony to kształt zastępczy (pewność niska) — sygnał komercyjny nigdy nie jest pewny.
			$commercial >= 0.6 => [SerpIntentSignal::Commercial, $commercial >= 0.8 ? SerpConfidence::MEDIUM : SerpConfidence::LOW],
			default => [SerpIntentSignal::Mixed, SerpConfidence::LOW],
		};
	}

	/**
	 * @param array<string, int> $shapes
	 * @return array<string, int>
	 */
	private static function ordered(array $shapes): array
	{
		$result = [];

		foreach (ResultShape::values() as $shape) {
			if (isset($shapes[$shape])) {
				$result[$shape] = $shapes[$shape];
			}
		}

		return $result;
	}
}
