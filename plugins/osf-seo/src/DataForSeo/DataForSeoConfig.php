<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Support\Config;

/**
 * Konfiguracja DataForSEO: dane logowania API wyłącznie ze stałych w wp-config.php lub zmiennych środowiskowych.
 * Wartości są czytane przy każdym użyciu i nigdy nie trafiają do pól obiektów, bazy, logów ani wyjątków.
 *
 * Ceny (USD) służą wyłącznie do szacunku przed wywołaniem i lokalnych limitów kosztów; po wywołaniu liczy się
 * koszt zgłoszony przez API. Domyślne wartości według cennika DataForSEO (październik 2026) — można je nadpisać,
 * gdy cennik się zmieni.
 */
final class DataForSeoConfig
{
	public const LOGIN = 'OSF_SEO_DATAFORSEO_LOGIN';

	public const PASSWORD = 'OSF_SEO_DATAFORSEO_PASSWORD';

	public const PRICE_VOLUME_TASK = 'OSF_SEO_DATAFORSEO_PRICE_VOLUME_TASK';

	public const PRICE_DIFFICULTY_REQUEST = 'OSF_SEO_DATAFORSEO_PRICE_DIFFICULTY_REQUEST';

	public const PRICE_DIFFICULTY_ITEM = 'OSF_SEO_DATAFORSEO_PRICE_DIFFICULTY_ITEM';

	public const PRICE_DISCOVERY_REQUEST = 'OSF_SEO_DATAFORSEO_PRICE_DISCOVERY_REQUEST';

	public const PRICE_DISCOVERY_ITEM = 'OSF_SEO_DATAFORSEO_PRICE_DISCOVERY_ITEM';

	/** Google Ads Search Volume, kolejka Standard: cena zadania (do 1000 fraz). */
	public const DEFAULT_PRICE_VOLUME_TASK = 0.06;

	/** DataForSEO Labs Bulk Keyword Difficulty (Live): cena żądania. */
	public const DEFAULT_PRICE_DIFFICULTY_REQUEST = 0.012;

	/** DataForSEO Labs: cena za każdy zwrócony element. */
	public const DEFAULT_PRICE_DIFFICULTY_ITEM = 0.00012;

	/** DataForSEO Labs Related Keywords / Keyword Suggestions (Live): cena żądania. */
	public const DEFAULT_PRICE_DISCOVERY_REQUEST = 0.012;

	/** DataForSEO Labs: cena za każdy zwrócony element wyszukiwania. */
	public const DEFAULT_PRICE_DISCOVERY_ITEM = 0.00012;

	public function __construct(private readonly Config $config = new Config())
	{
	}

	public function isConfigured(): bool
	{
		return $this->missing() === [];
	}

	/**
	 * Nazwy brakujących stałych (bez wartości).
	 *
	 * @return list<string>
	 */
	public function missing(): array
	{
		return array_values(array_filter(
			[self::LOGIN, self::PASSWORD],
			fn (string $name): bool => trim((string) $this->config->get($name)) === '',
		));
	}

	/**
	 * Nagłówek Basic Auth budowany w chwili żądania (login:hasło w Base64 — wymóg DataForSEO).
	 *
	 * @throws ProviderException brak konfiguracji
	 */
	public function authorizationHeader(): string
	{
		$login = trim((string) $this->config->get(self::LOGIN));
		$password = (string) $this->config->get(self::PASSWORD);

		if ($login === '' || trim($password) === '') {
			throw new ProviderException(ProviderErrorCategory::NotConfigured, 'DataForSEO credentials are not configured.');
		}

		return 'Basic ' . base64_encode($login . ':' . $password);
	}

	public function priceVolumeTask(): float
	{
		return $this->price(self::PRICE_VOLUME_TASK, self::DEFAULT_PRICE_VOLUME_TASK);
	}

	public function priceDifficultyRequest(): float
	{
		return $this->price(self::PRICE_DIFFICULTY_REQUEST, self::DEFAULT_PRICE_DIFFICULTY_REQUEST);
	}

	public function priceDifficultyItem(): float
	{
		return $this->price(self::PRICE_DIFFICULTY_ITEM, self::DEFAULT_PRICE_DIFFICULTY_ITEM);
	}

	public function priceDiscoveryRequest(): float
	{
		return $this->price(self::PRICE_DISCOVERY_REQUEST, self::DEFAULT_PRICE_DISCOVERY_REQUEST);
	}

	public function priceDiscoveryItem(): float
	{
		return $this->price(self::PRICE_DISCOVERY_ITEM, self::DEFAULT_PRICE_DISCOVERY_ITEM);
	}

	/**
	 * @return array<string, string>
	 */
	public function __debugInfo(): array
	{
		return ['configured' => $this->isConfigured() ? 'yes' : 'no'];
	}

	private function price(string $name, float $default): float
	{
		$value = $this->config->get($name);

		return $value !== null && is_numeric(trim($value)) ? max(0.0, min(100.0, (float) $value)) : $default;
	}
}
