<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Opis endpointu dostawcy (do planu synchronizacji, dry-run i rejestru kosztów).
 */
final class ProviderEndpoint
{
	public const MODE_STANDARD = 'standard';

	public const MODE_LIVE = 'live';

	public function __construct(
		/** Etykieta w `market_tasks.endpoint`, np. `google_ads_search_volume`. */
		public readonly string $name,
		/** `standard` (zlecenie + odbiór wyniku później) albo `live` (wynik w odpowiedzi). */
		public readonly string $mode,
		/** Ścieżka API (bez hosta) — dokumentacyjnie, np. w dry-run. */
		public readonly string $path,
	) {
	}
}
