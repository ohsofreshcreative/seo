<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Siła powiązania frazy z seedem (0–100) — z danych dostawcy i deterministycznego porównania tekstu:
 *
 * - sam seed: 100,
 * - Related Keywords: głębokość 1 → 80, 2 → 60, 3 → 40, 4 → 30 (dalej od seeda = słabiej),
 * - Keyword Suggestions: fraza zawiera frazę seeda jako ciąg słów → 80, w pozostałych przypadkach (słowa seeda
 *   w innej kolejności lub odmianie) → 60.
 */
final class Relation
{
	public const SEED = 100;

	private const DEPTH = [0 => 100, 1 => 80, 2 => 60, 3 => 40, 4 => 30];

	public static function of(DiscoveryMethod $method, string $seed, string $keyword, ?int $depth, bool $isSeed = false): int
	{
		if ($isSeed || $keyword === $seed) {
			return self::SEED;
		}

		return match ($method) {
			DiscoveryMethod::Related => self::DEPTH[max(1, min(4, $depth ?? 1))],
			DiscoveryMethod::Suggestions => self::containsPhrase($keyword, $seed) ? 80 : 60,
		};
	}

	/** Czy fraza zawiera frazę seeda jako ciąg całych słów (postacie znormalizowane). */
	public static function containsPhrase(string $keyword, string $seed): bool
	{
		return $seed !== '' && str_contains(' ' . $keyword . ' ', ' ' . $seed . ' ');
	}
}
