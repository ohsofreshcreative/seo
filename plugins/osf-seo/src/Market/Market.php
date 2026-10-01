<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Rynek danych rynkowych: kraj i język projektu (kody ISO z ustawień projektu) oraz identyfikatory dostawcy
 * (DataForSEO: `location_code` = identyfikator lokalizacji Google Ads, `language_code` = kod języka).
 * Klucz danych rynkowych: dostawca + lokalizacja + język + klucz frazy.
 */
final class Market
{
	public function __construct(
		public readonly string $provider,
		public readonly string $country,
		public readonly string $language,
		public readonly int $locationCode,
		public readonly string $languageCode,
		public readonly string $countryLabel,
		public readonly string $languageLabel,
	) {
	}

	/** Np. „Polska / polski”. */
	public function label(): string
	{
		return $this->countryLabel . ' / ' . $this->languageLabel;
	}

	public function id(): string
	{
		return $this->provider . ':' . $this->locationCode . ':' . $this->languageCode;
	}
}
