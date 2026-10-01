<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Market\MarketKeyword;

/**
 * Wykluczone słowa projektu (np. „praca”, „darmow*”, „torrent”) — frazy kandydatów, które je zawierają, nie trafiają
 * na listę. Dopasowanie deterministyczne po całych słowach postaci znormalizowanej; `*` na końcu słowa = dowolna
 * końcówka (odmiana: „darmow*” → darmowe, darmowy, darmowa). Wielowyrazowe wykluczenie dopasowuje ciąg słów.
 * Brak wbudowanej listy — każdy projekt prowadzi własną.
 */
final class ExclusionList
{
	public const MAX_TERMS = 200;

	/**
	 * @param list<string> $terms
	 */
	private function __construct(public readonly array $terms)
	{
	}

	public static function parse(?string $text): self
	{
		$terms = [];

		foreach (preg_split('/[\r\n,;]+/u', (string) $text) ?: [] as $raw) {
			$term = trim((string) preg_replace('/\*+/', '*', MarketKeyword::normalize($raw)));
			$term = trim((string) preg_replace('/ \*|^\*/u', '', $term));

			if ($term !== '' && $term !== '*' && ! in_array($term, $terms, true) && mb_strlen($term) <= 80) {
				$terms[] = $term;
			}

			if (count($terms) >= self::MAX_TERMS) {
				break;
			}
		}

		return new self($terms);
	}

	public function isEmpty(): bool
	{
		return $this->terms === [];
	}

	/** Pierwsze wykluczenie pasujące do frazy (postać znormalizowana) albo null. */
	public function match(string $keyword): ?string
	{
		foreach ($this->terms as $term) {
			if (preg_match($this->pattern($term), $keyword) === 1) {
				return $term;
			}
		}

		return null;
	}

	public function toText(): string
	{
		return implode("\n", $this->terms);
	}

	/** Skrót listy (klucz przeliczenia kandydatów). */
	public function hash(): string
	{
		return md5($this->toText());
	}

	private function pattern(string $term): string
	{
		$words = array_map(
			static fn (string $word): string => str_ends_with($word, '*')
				? preg_quote(rtrim($word, '*'), '/') . '[^ ]*'
				: preg_quote($word, '/'),
			explode(' ', $term),
		);

		return '/(?:^| )' . implode(' ', $words) . '(?:$| )/u';
	}
}
