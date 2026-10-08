<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

/**
 * Sygnał intencji odczytany z kształtu strony wyników (SERP Intelligence) — osobny od intencji dostawcy (`market_keywords.search_intent`),
 * której nigdy nie nadpisuje. To sygnał do sprawdzenia, nie klasyfikacja zapytania.
 */
enum SerpIntentSignal: string
{
	case Informational = 'informational';
	case Commercial = 'commercial';
	case Transactional = 'transactional';
	case Local = 'local';
	case Navigational = 'navigational';
	case Mixed = 'mixed';
	case Unknown = 'unknown';

	public function label(): string
	{
		return match ($this) {
			self::Informational => 'Informacyjna',
			self::Commercial => 'Komercyjna',
			self::Transactional => 'Transakcyjna',
			self::Local => 'Lokalna',
			self::Navigational => 'Nawigacyjna',
			self::Mixed => 'Mieszana',
			self::Unknown => 'Nieznana',
		};
	}

	/** Sygnał rozstrzygający (inny niż mieszany i nieznany) — tylko takie porównujemy przy overlapie. */
	public function isDecisive(): bool
	{
		return $this !== self::Mixed && $this !== self::Unknown;
	}
}
