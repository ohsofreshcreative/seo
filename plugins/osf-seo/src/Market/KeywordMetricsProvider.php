<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Dostawca rynkowych metryk fraz (wolumen, CPC, konkurencja Ads, historia miesięczna, trudność SEO).
 *
 * Warstwa aplikacji (synchronizacja, raport fraz, szanse SEO) zależy wyłącznie od tego interfejsu;
 * DataForSEO jest pierwszą implementacją. GSC pozostaje źródłem prawdy dla kliknięć, wyświetleń, CTR
 * i średniej pozycji — dostawca tylko wzbogaca frazy o dane rynkowe.
 *
 * Każda metoda wysyłająca żądanie może być płatna: wywołuje ją wyłącznie `MarketSyncService`
 * (po sprawdzeniu limitów kosztów), nigdy kontroler ani widok.
 */
interface KeywordMetricsProvider
{
	/** Identyfikator w bazie (`market_keywords.provider`), np. `dataforseo`. */
	public function name(): string;

	/** Nazwa w UI, np. „DataForSEO”. */
	public function label(): string;

	public function isConfigured(): bool;

	/**
	 * Nazwy brakujących stałych konfiguracji (bez wartości).
	 *
	 * @return list<string>
	 */
	public function configurationProblems(): array;

	/** Rynek dostawcy dla kraju i języka projektu (kody ISO); null = rynek nieobsługiwany. */
	public function resolveMarket(string $country, string $language): ?Market;

	/**
	 * Obsługiwane rynki (do formularza projektu).
	 *
	 * @return list<Market>
	 */
	public function markets(): array;

	/** Czy dostawca przyjmie frazę (postać znormalizowana) — zła fraza potrafi odrzucić całą paczkę. */
	public function acceptsKeyword(string $normalizedKeyword): bool;

	/** Maksymalna liczba fraz w jednym zadaniu/żądaniu. */
	public function maxKeywordsPerTask(): int;

	public function volumeEndpoint(): ProviderEndpoint;

	public function difficultyEndpoint(): ProviderEndpoint;

	/** Szacowany koszt (USD) jednego zadania wolumenu dla podanej liczby fraz. */
	public function estimateVolumeCost(int $keywords): float;

	/** Szacowany koszt (USD) jednego żądania trudności SEO dla podanej liczby fraz (górna granica). */
	public function estimateDifficultyCost(int $keywords): float;

	/**
	 * Zleca pobranie wolumenu (płatne).
	 *
	 * @param list<string> $keywords postacie znormalizowane, maks. maxKeywordsPerTask()
	 *
	 * @throws ProviderException
	 */
	public function submitVolume(Market $market, array $keywords): VolumeSubmission;

	/**
	 * Odbiera wynik zadania wolumenu (bezpłatne); null — wynik jeszcze nie jest gotowy.
	 *
	 * @throws ProviderException
	 */
	public function fetchVolume(string $taskId): ?VolumeBatch;

	/**
	 * Trudność SEO (płatne).
	 *
	 * @param list<string> $keywords postacie znormalizowane, maks. maxKeywordsPerTask()
	 *
	 * @throws ProviderException
	 */
	public function difficulty(Market $market, array $keywords): DifficultyBatch;
}
