<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Fetch;

/**
 * Pobranie jednego adresu z pełną polityką bezpieczeństwa (każdy hop: składnia, zakres, DNS, adresy, połączenie z przypiętym IP).
 * Produkcyjnie `CurlPageFetcher`; testy usług podstawiają atrapę.
 */
interface PageFetcher
{
	public function fetch(FetchRequest $request): FetchResult;
}
