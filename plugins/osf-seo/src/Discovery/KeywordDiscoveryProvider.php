<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Market\Market;
use OsfSeo\Market\ProviderEndpoint;
use OsfSeo\Market\ProviderException;

/**
 * Dostawca wyszukiwania nowych fraz na rynku (seed → powiązane frazy z metrykami rynkowymi).
 *
 * Warstwa aplikacji (`DiscoveryService`) zależy wyłącznie od tego interfejsu; DataForSEO Labs jest pierwszą implementacją.
 * `discover()` jest płatne — wywołuje je wyłącznie `DiscoveryService` po kontroli planu, uprawnień i limitów kosztów,
 * nigdy kontroler, widok ani raport.
 */
interface KeywordDiscoveryProvider
{
	/** Identyfikator dostawcy w bazie (`market_keywords.provider`) — ten sam co dostawcy metryk (wspólne dane rynkowe). */
	public function name(): string;

	public function label(): string;

	public function isConfigured(): bool;

	/**
	 * @return list<string>
	 */
	public function configurationProblems(): array;

	public function resolveMarket(string $country, string $language): ?Market;

	/** Czy dostawca przyjmie seed (postać znormalizowana). */
	public function acceptsKeyword(string $normalizedKeyword): bool;

	/**
	 * @return list<DiscoveryMethod>
	 */
	public function methods(): array;

	public function endpoint(DiscoveryMethod $method): ProviderEndpoint;

	/** Maksymalna liczba elementów w jednym żądaniu (strona wyników). */
	public function maxItemsPerRequest(): int;

	/** Górna granica liczby wyników seeda dla metody i głębokości (null — bez znanej granicy). */
	public function maxResults(DiscoveryMethod $method, ?int $depth): ?int;

	/** Maksymalny koszt (USD) jednego żądania zwracającego najwyżej $items elementów. */
	public function estimateCost(int $items): float;

	/**
	 * Wyszukiwanie (płatne).
	 *
	 * @throws ProviderException
	 */
	public function discover(Market $market, DiscoveryQuery $query): DiscoveryBatch;
}
