<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

/**
 * Sygnał konfliktu adresów projektu (faza C — dowód, nie osobny moduł kanibalizacji). Siła: `strong` (podstawa konsolidacji),
 * `moderate` (do sprawdzenia, obniża pewność), `weak` (pojedyncza zmiana adresu rankującego — nigdy sama nie oznacza kanibalizacji).
 */
final class ConflictSignal
{
	/** Szansa SEO „Możliwa kanibalizacja” powiązana bezpośrednio z frazą. */
	public const CANNIBALIZATION = 'cannibalization';

	/** Wyświetlenia frazy rozłożone na co najmniej dwie strony projektu (GSC query × page). */
	public const GSC_SPLIT = 'gsc_split';

	/** Adres rankujący w świeżym pomiarze ≠ potwierdzona strona docelowa (spójnie w dwóch porównywalnych pomiarach — silny). */
	public const TARGET_MISMATCH = 'target_mismatch';

	/** Zmiana adresu rankującego między porównywalnymi pomiarami. */
	public const URL_FLIP = 'url_flip';

	/** Kilka adresów projektu w TOP10 świeżego pomiaru. */
	public const MULTIPLE_TOP10 = 'multiple_top10';

	/** Silny overlap SERP fraz z różnymi stronami docelowymi (scalenie zablokowane). */
	public const OVERLAP_TARGETS = 'overlap_targets';

	public const STRONG = 'strong';

	public const MODERATE = 'moderate';

	public const WEAK = 'weak';

	/** Kolejność typów w uzasadnieniu konsolidacji. */
	public const ORDER = [self::CANNIBALIZATION, self::GSC_SPLIT, self::OVERLAP_TARGETS, self::TARGET_MISMATCH, self::MULTIPLE_TOP10, self::URL_FLIP];

	/**
	 * @param list<string> $urls
	 * @param array<string, mixed> $detail
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $strength,
		public readonly array $urls,
		public readonly string $keyword,
		public readonly array $detail = [],
	) {
	}

	public function isStrong(): bool
	{
		return $this->strength === self::STRONG;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return ['type' => $this->type, 'strength' => $this->strength, 'urls' => $this->urls, 'keyword' => $this->keyword, 'detail' => $this->detail];
	}

	/**
	 * Sygnały posortowane (typ, siła, fraza) — deterministycznie.
	 *
	 * @param list<self> $signals
	 * @return list<self>
	 */
	public static function sorted(array $signals): array
	{
		$order = array_flip(self::ORDER);
		$strength = [self::STRONG => 0, self::MODERATE => 1, self::WEAK => 2];
		usort($signals, static fn (self $a, self $b): int => [$strength[$a->strength], $order[$a->type] ?? 99, $a->keyword, implode(' ', $a->urls)]
			<=> [$strength[$b->strength], $order[$b->type] ?? 99, $b->keyword, implode(' ', $b->urls)]);

		return $signals;
	}
}
