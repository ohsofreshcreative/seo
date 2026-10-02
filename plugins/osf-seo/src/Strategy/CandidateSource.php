<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Adapter źródła kandydatów Strategii (docs/ARCHITECTURE.md, sekcja 15.2). Wyłącznie odczyt danych modułu; decyzje modułu
 * (odrzucenia) są respektowane w `signals()` — odrzucona fraza nie wchodzi tym źródłem, ale `evidence()` pokazuje decyzję,
 * gdy fraza weszła innym źródłem.
 */
interface CandidateSource
{
	public function source(): StrategySource;

	/** Tani odcisk danych i decyzji źródła (część klucza danych przeliczenia — także mutacje ręczne). */
	public function fingerprint(SourceScope $scope): string;

	public function signals(SourceScope $scope): SignalBatch;

	/**
	 * Dowody źródła dla kandydatów (niezależnie od tego, którym źródłem weszli). Źródła są wywoływane w stałej kolejności
	 * (najpierw GSC) — `$collected` zawiera dowody źródeł wcześniejszych.
	 *
	 * @param array<int, string> $keys market_keyword_id → klucz rynkowy (hex)
	 * @param array<int, array<string, mixed>> $collected market_keyword_id → kod źródła → dowód
	 * @return array<int, mixed> market_keyword_id → dowód tego źródła (brak klucza = brak dowodu)
	 */
	public function evidence(SourceScope $scope, array $keys, array $collected): array;
}
