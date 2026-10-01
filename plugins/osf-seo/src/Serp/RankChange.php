<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Zmiana Pozycji SERP między bieżącym a poprzednim porównywalnym pomiarem (ta sama fraza i ten sam kontekst:
 * lokalizacja, język, urządzenie, głębokość). Wartość liczbowa tylko, gdy oba pomiary znalazły domenę —
 * nigdy z pustej pozycji:
 *
 * - #12 → #7 = `up` +5, #4 → #9 = `down` −5, bez zmiany = `same`,
 * - poza TOP → znaleziona = `entered` („Weszła do TOP{głębokość}”), znaleziona → poza TOP = `left` („Wypadła z TOP…”),
 * - poza TOP w obu = `out`, brak poprzedniego pomiaru = `new`, poprzedni pomiar w innym kontekście = `incomparable`.
 *
 * Osobno: wejście do TOP10 (`entered`) i wypadnięcie z TOP10 (`left`).
 */
final class RankChange
{
	public const NEW = 'new';

	public const UP = 'up';

	public const DOWN = 'down';

	public const SAME = 'same';

	public const ENTERED = 'entered';

	public const LEFT = 'left';

	public const OUT = 'out';

	public const INCOMPARABLE = 'incomparable';

	public function __construct(
		public readonly string $type,
		public readonly ?int $value = null,
		public readonly ?string $top10 = null,
	) {
	}

	/**
	 * @param bool $hasPrevious istnieje poprzedni pomiar w tym samym kontekście
	 * @param bool $hadAnyPrevious istnieje jakikolwiek wcześniejszy pomiar (także w innym kontekście)
	 */
	public static function compare(bool $hasPrevious, bool $hadAnyPrevious, ?int $previousRank, ?int $currentRank): self
	{
		if (! $hasPrevious) {
			return new self($hadAnyPrevious ? self::INCOMPARABLE : self::NEW);
		}

		$top10 = match (true) {
			($previousRank === null || $previousRank > 10) && $currentRank !== null && $currentRank <= 10 => self::ENTERED,
			$previousRank !== null && $previousRank <= 10 && ($currentRank === null || $currentRank > 10) => self::LEFT,
			default => null,
		};

		return match (true) {
			$previousRank === null && $currentRank === null => new self(self::OUT, null, $top10),
			$previousRank === null => new self(self::ENTERED, null, $top10),
			$currentRank === null => new self(self::LEFT, null, $top10),
			default => new self(
				$previousRank === $currentRank ? self::SAME : ($previousRank > $currentRank ? self::UP : self::DOWN),
				$previousRank - $currentRank,
				$top10,
			),
		};
	}

	public static function label(?string $type, ?int $value, int $depth): string
	{
		return match ($type) {
			self::UP => '+' . $value,
			self::DOWN => '−' . abs((int) $value),
			self::SAME => '0',
			self::ENTERED => 'Weszła do TOP' . $depth,
			self::LEFT => 'Wypadła z TOP' . $depth,
			self::OUT => 'Poza TOP' . $depth,
			self::NEW => 'Pierwszy pomiar',
			self::INCOMPARABLE => 'Nieporównywalne',
			default => '—',
		};
	}
}
