<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Fetch;

/**
 * Polityka sieci pobierania stron: które adresy IP i porty są dozwolone. Produkcyjnie wyłącznie publiczne adresy na portach 80/443
 * (`PublicNetworkPolicy`); testy transportu podstawiają politykę dopuszczającą kontrolowany serwer lokalny.
 */
interface NetworkPolicy
{
	/** Powód blokady adresu IP albo null (dozwolony). */
	public function addressReason(string $ip): ?string;

	/**
	 * @return list<int>
	 */
	public function allowedPorts(): array;
}
