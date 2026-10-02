<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Market\Market;
use OsfSeo\Market\ProviderEndpoint;
use OsfSeo\Market\ProviderException;

/**
 * Dostawca pomiarów SERP (STEP 14). Jedyna implementacja: `DataForSeoSerpProvider` (Google Organic, kolejka Standard).
 * Płatne jest wyłącznie `submit()` — wywołuje je tylko `SerpSubmitter` pod wspólną blokadą płatnych żądań.
 */
interface SerpProvider
{
	public function name(): string;

	public function isConfigured(): bool;

	/**
	 * @return list<string>
	 */
	public function configurationProblems(): array;

	public function resolveMarket(string $country, string $language): ?Market;

	public function endpoint(): ProviderEndpoint;

	public function maxTasksPerPost(): int;

	/** Szacowany maksymalny koszt jednego zadania (fraza × kontekst) — przed wykonaniem. */
	public function estimateCost(SerpContext $context): float;

	/**
	 * Płatne zlecenie zadań (jeden POST). Zwraca odpowiedź dla każdego zadania (klucz: `tag`).
	 *
	 * @param list<SerpTaskRequest> $tasks
	 * @return array<string, SerpSubmission>
	 *
	 * @throws ProviderException gdy całe zlecenie się nie powiodło
	 */
	public function submit(array $tasks): array;

	/**
	 * Zadania gotowe do odbioru (bezpłatnie). Lista może zawierać zadania innych instalacji na tym samym koncie.
	 *
	 * @return list<SerpReadyTask>
	 *
	 * @throws ProviderException
	 */
	public function readyTasks(): array;

	/**
	 * Wynik zadania (bezpłatnie); null — jeszcze się wykonuje.
	 *
	 * @throws ProviderException
	 */
	public function fetch(string $taskId): ?SerpPage;
}
