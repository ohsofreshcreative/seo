<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Market\Market;

/**
 * Kontekst pomiaru SERP: wyszukiwarka, typ wyników, lokalizacja, język, urządzenie, system i głębokość.
 * Pomiary są porównywalne tylko w tym samym kontekście (zmiana urządzenia lub głębokości zaczyna nową serię).
 */
final class SerpContext
{
	public const ENGINE_GOOGLE = 'google';

	public const TYPE_ORGANIC = 'organic';

	public function __construct(
		public readonly int $locationCode,
		public readonly string $languageCode,
		public readonly SerpDevice $device,
		public readonly int $depth,
		public readonly string $engine = self::ENGINE_GOOGLE,
		public readonly string $serpType = self::TYPE_ORGANIC,
		public readonly ?int $id = null,
	) {
	}

	public static function forMarket(Market $market, SerpDevice $device, int $depth): self
	{
		return new self($market->locationCode, $market->languageCode, $device, $depth);
	}

	public function os(): string
	{
		return $this->device->os();
	}

	/** Liczba stron SERP (po 10 wyników) — górna granica pobrania (`max_crawl_pages`) i kosztu. */
	public function pages(): int
	{
		return max(1, (int) ceil($this->depth / 10));
	}

	/** Klucz tożsamości kontekstu (hex MD5). */
	public function key(): string
	{
		return md5(implode('|', [$this->engine, $this->serpType, $this->locationCode, $this->languageCode, $this->device->value, $this->os(), $this->depth]));
	}

	public function withId(int $id): self
	{
		return new self($this->locationCode, $this->languageCode, $this->device, $this->depth, $this->engine, $this->serpType, $id);
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			(int) $row['location_code'],
			(string) $row['language_code'],
			SerpDevice::tryFrom((string) $row['device']) ?? SerpDevice::Desktop,
			(int) $row['depth'],
			(string) $row['engine'],
			(string) $row['serp_type'],
			isset($row['id']) ? (int) $row['id'] : null,
		);
	}

	/**
	 * @return array<string, int|string>
	 */
	public function toArray(): array
	{
		return [
			'engine' => $this->engine,
			'serp_type' => $this->serpType,
			'location_code' => $this->locationCode,
			'language_code' => $this->languageCode,
			'device' => $this->device->value,
			'os' => $this->os(),
			'depth' => $this->depth,
		];
	}
}
