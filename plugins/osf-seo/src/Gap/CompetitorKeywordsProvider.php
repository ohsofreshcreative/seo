<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Market\Market;
use OsfSeo\Market\ProviderEndpoint;
use OsfSeo\Market\ProviderException;

/**
 * Dostawca fraz, na które rankuje domena (Luki SEO, STEP 15): domena × rynek → frazy z pozycją wyniku organicznego,
 * adresem i metrykami rynkowymi.
 *
 * Warstwa aplikacji zależy wyłącznie od tego interfejsu; DataForSEO Labs Ranked Keywords jest pierwszą implementacją.
 * `rankedKeywords()` jest płatne — wywołuje je wyłącznie `GapImporter` (w tle albo z CLI, pod wspólną blokadą i limitami),
 * nigdy kontroler, widok ani raport.
 */
interface CompetitorKeywordsProvider
{
	/** Identyfikator dostawcy w bazie (`market_keywords.provider`, `gap_domains.provider`) — wspólny z danymi rynkowymi. */
	public function name(): string;

	public function label(): string;

	public function isConfigured(): bool;

	/**
	 * @return list<string>
	 */
	public function configurationProblems(): array;

	public function resolveMarket(string $country, string $language): ?Market;

	public function endpoint(): ProviderEndpoint;

	/** Maksymalna liczba fraz w jednym żądaniu (strona wyników). */
	public function maxItemsPerRequest(): int;

	/**
	 * Największa liczba fraz domeny, jaką można bezpiecznie pobrać stronicowaniem potwierdzonym w dokumentacji dostawcy
	 * (bez pomijania i dublowania fraz). Import nigdy nie wychodzi poza tę granicę.
	 */
	public function maxRowsPerDomain(): int;

	/** Maksymalny koszt (USD) jednego żądania zwracającego najwyżej $items fraz. */
	public function estimateCost(int $items): float;

	/**
	 * Strona fraz domeny (płatne).
	 *
	 * @throws ProviderException
	 */
	public function rankedKeywords(Market $market, RankedKeywordsQuery $query): RankedKeywordsBatch;
}
