<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Discovery\ExclusionList;

/**
 * Rozpoznawanie fraz markowych (nawigacyjnych) — konkurenta albo projektu — bez AI. Takie frazy nie są lukami SEO
 * (wiersze zostają w bazie z powodem i są widoczne w filtrze „Odfiltrowane”).
 *
 * - Warianty podane przez użytkownika (składnia wykluczeń: wyrazy, `*` na końcu) pasują zawsze.
 * - Warianty automatyczne z domeny (etykieta nazwy, np. `wise-people.pl` → „wise people”, „wisepeople”) i nazwy
 *   (np. „Wise People” → „wise people”, „wisepeople”) pasują, gdy:
 *   - fraza zawiera wariant wielowyrazowy zapisany łącznie jako jeden wyraz („wisepeople opinie”) — silny sygnał marki,
 *   - albo fraza zawiera wariant (jako wyrazy lub sklejenie sąsiednich wyrazów), a dostawca oznaczył intencję
 *     jako nawigacyjną. Dzięki temu domena-fraza (np. `strony-internetowe.pl`) nie ukrywa zwykłych fraz branżowych.
 */
final class BrandMatcher
{
	/** Domeny drugiego poziomu pomijane przy wyznaczaniu nazwy z domeny (`example.com.pl` → `example`). */
	private const SECOND_LEVEL = ['com', 'co', 'org', 'net', 'gov', 'edu', 'ac', 'biz', 'info', 'waw', 'nom', 'ltd', 'plc', 'or', 'ne', 'go', 'gv'];

	/** Formy prawne pomijane w nazwie (ogólne, nie branżowe). */
	private const LEGAL = ['sp', 'z', 'o', 'oo', 'zoo', 's', 'a', 'sa', 'sk', 'spk', 'spj', 'j', 'ltd', 'llc', 'inc', 'gmbh', 'ag', 'bv', 'sro', 'spolka', 'company', 'corp'];

	/**
	 * @param list<array{tokens: list<string>, compact: string}> $variants
	 */
	private function __construct(
		private readonly array $variants,
		private readonly ExclusionList $terms,
	) {
	}

	/**
	 * @param list<string> $domains znormalizowane domeny (bez schematu i `www.`)
	 * @param list<string> $names nazwy (konkurenta, projektu)
	 */
	public static function build(array $domains, array $names, ?string $terms = null): self
	{
		$variants = [];

		foreach ($domains as $domain) {
			$label = self::domainLabel($domain);

			if ($label !== null) {
				$variants[] = TextFold::tokens(str_replace(['-', '_'], ' ', $label));
			}
		}

		foreach ($names as $name) {
			$tokens = array_values(array_filter(TextFold::tokens($name), static fn (string $token): bool => mb_strlen($token) > 1 && ! in_array($token, self::LEGAL, true)));

			if ($tokens !== [] && count($tokens) <= 3) {
				$variants[] = $tokens;
			}
		}

		$unique = [];

		foreach ($variants as $tokens) {
			$compact = implode('', $tokens);

			if (mb_strlen($compact) >= 3 && ! isset($unique[implode(' ', $tokens)])) {
				$unique[implode(' ', $tokens)] = ['tokens' => $tokens, 'compact' => $compact];
			}
		}

		return new self(array_values($unique), ExclusionList::parse($terms));
	}

	/** Nazwa z domeny: etykieta przed sufiksem (`blog.wise-people.com.pl` → `wise-people`). */
	public static function domainLabel(string $domain): ?string
	{
		$labels = array_values(array_filter(explode('.', strtolower($domain)), static fn (string $label): bool => $label !== ''));

		if (count($labels) < 2) {
			return null;
		}

		array_pop($labels);

		if (count($labels) >= 2 && in_array($labels[count($labels) - 1], self::SECOND_LEVEL, true)) {
			array_pop($labels);
		}

		$label = $labels[count($labels) - 1] ?? null;

		return $label === null || str_starts_with($label, 'xn--') ? null : $label;
	}

	public function isEmpty(): bool
	{
		return $this->variants === [] && $this->terms->isEmpty();
	}

	/**
	 * @return list<string>
	 */
	public function labels(): array
	{
		return [...array_map(static fn (array $variant): string => implode(' ', $variant['tokens']), $this->variants), ...$this->terms->terms];
	}

	/** Pasujący wariant marki albo null. */
	public function match(string $normalizedKeyword, ?string $intent): ?string
	{
		$term = $this->terms->match($normalizedKeyword);

		if ($term !== null) {
			return $term;
		}

		if ($this->variants === []) {
			return null;
		}

		$tokens = TextFold::tokens($normalizedKeyword);
		$navigational = $intent === 'navigational';

		foreach ($this->variants as $variant) {
			$multi = count($variant['tokens']) > 1;

			if ($multi && in_array($variant['compact'], $tokens, true)) {
				return implode(' ', $variant['tokens']);
			}

			if ($navigational && (self::containsSequence($tokens, $variant['tokens']) || self::containsJoined($tokens, $variant['compact']))) {
				return implode(' ', $variant['tokens']);
			}
		}

		return null;
	}

	/**
	 * @param list<string> $tokens
	 * @param list<string> $sequence
	 */
	private static function containsSequence(array $tokens, array $sequence): bool
	{
		$length = count($sequence);

		for ($i = 0; $i + $length <= count($tokens); $i++) {
			if (array_slice($tokens, $i, $length) === $sequence) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sklejenie sąsiednich wyrazów frazy równe wariantowi („wise people” ↔ „wisepeople”).
	 *
	 * @param list<string> $tokens
	 */
	private static function containsJoined(array $tokens, string $compact): bool
	{
		$count = count($tokens);

		for ($i = 0; $i < $count; $i++) {
			$joined = '';

			for ($j = $i; $j < $count && mb_strlen($joined) < mb_strlen($compact); $j++) {
				$joined .= $tokens[$j];

				if ($joined === $compact) {
					return true;
				}
			}
		}

		return false;
	}
}
