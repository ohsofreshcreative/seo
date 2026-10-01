<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Market\MarketKeyword;

/**
 * Lista seedów przebiegu: frazy wpisane ręcznie (po jednej w wierszu lub po przecinku) i wybrane podpowiedzi.
 * Normalizacja jak dla danych rynkowych (NFC, małe litery, spacje); duplikaty scalane; frazy, których dostawca nie
 * przyjmie (długość, liczba słów, niedozwolone znaki), są odrzucane przed planem — nic nie jest wysyłane.
 */
final class SeedList
{
	public const SOURCE_MANUAL = 'manual';

	public const SOURCE_GSC = 'gsc';

	public const SOURCE_OPPORTUNITY = 'opportunity';

	public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_GSC, self::SOURCE_OPPORTUNITY];

	/**
	 * @param array<string, string> $accepted postać znormalizowana → źródło
	 * @param list<array{seed: string, reason: string}> $rejected
	 */
	private function __construct(
		public readonly array $accepted,
		public readonly array $rejected,
	) {
	}

	/**
	 * @param array<string, list<string>|string> $bySource źródło → tekst (wiersze/przecinki) albo lista fraz
	 * @param \Closure(string): bool $accepts reguła dostawcy dla postaci znormalizowanej
	 */
	public static function parse(array $bySource, \Closure $accepts, int $max): self
	{
		$accepted = [];
		$rejected = [];

		foreach ($bySource as $source => $input) {
			$source = in_array($source, self::SOURCES, true) ? $source : self::SOURCE_MANUAL;

			foreach (self::split($input) as $raw) {
				$seed = MarketKeyword::normalize($raw);

				if ($seed === '' || isset($accepted[$seed])) {
					continue;
				}

				if (mb_strlen($seed) < 2) {
					$rejected[] = ['seed' => $seed, 'reason' => 'too_short'];
				} elseif (! $accepts($seed)) {
					$rejected[] = ['seed' => $seed, 'reason' => 'provider_rules'];
				} elseif (count($accepted) >= $max) {
					$rejected[] = ['seed' => $seed, 'reason' => 'limit'];
				} else {
					$accepted[$seed] = $source;
				}
			}
		}

		return new self($accepted, $rejected);
	}

	/**
	 * @return list<string>
	 */
	public function seeds(): array
	{
		return array_keys($this->accepted);
	}

	public function count(): int
	{
		return count($this->accepted);
	}

	public static function reasonLabel(string $reason): string
	{
		return match ($reason) {
			'too_short' => 'za krótka',
			'provider_rules' => 'niedozwolona u dostawcy (długość, liczba słów lub znaki)',
			'limit' => 'ponad limit seedów',
			default => $reason,
		};
	}

	/**
	 * @param list<string>|string $input
	 * @return list<string>
	 */
	private static function split(array|string $input): array
	{
		$parts = is_array($input) ? $input : (preg_split('/[\r\n,;]+/u', $input) ?: []);

		return array_values(array_filter(array_map(static fn (mixed $part): string => is_string($part) ? trim($part) : '', $parts), static fn (string $part): bool => $part !== ''));
	}
}
