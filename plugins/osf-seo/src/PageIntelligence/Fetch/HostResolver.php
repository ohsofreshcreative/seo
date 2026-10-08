<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Fetch;

/**
 * Rozwiązywanie nazwy hosta na adresy IP — jedyne miejsce zapytań DNS pobierania stron. Transport łączy się wyłącznie z adresami
 * zwróconymi tutaj i sprawdzonymi przez politykę sieci (bez drugiego zapytania DNS w chwili połączenia).
 */
interface HostResolver
{
	/**
	 * @return list<string> adresy IPv4 i IPv6 (pusta lista = brak odpowiedzi DNS)
	 */
	public function resolve(string $host): array;
}
