<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Provider;

/**
 * Dostawca modelu AI (STEP 17 — docs/ARCHITECTURE.md, sekcja 22.3). Adapter odpowiada wyłącznie za transport i mapowanie formatu:
 * nie buduje kontekstu, nie liczy kosztów, nie zapisuje historii i nie decyduje o uruchomieniu (to `AiAnalysisService`).
 *
 * Nowy dostawca (np. Anthropic): klasa implementująca ten interfejs + rejestracja w `AiProviderRegistry` (kontener) + stała klucza
 * API w `AiConfig`. Klucz czytany wyłącznie w chwili żądania; błędy jako `AiProviderException` z rodzajem (bez treści odpowiedzi).
 */
interface AiProvider
{
	/** Identyfikator techniczny (`openai`, `fake`) — zapisywany w historii. */
	public function id(): string;

	/** Czy wywołanie kosztuje (płatny dostawca podlega wyłącznikowi, cenom, limitom i potwierdzeniu). */
	public function isPaid(): bool;

	/** Model używany przez dostawcę (z konfiguracji) albo null, gdy nieskonfigurowany. */
	public function model(): ?string;

	/**
	 * Braki konfiguracji dostawcy (kody, np. `missing_api_key`) — bez wartości sekretów.
	 *
	 * @return list<string>
	 */
	public function problems(): array;

	/**
	 * Jedno wywołanie modelu ze strukturalną odpowiedzią (bez ponowień — płatne żądanie mogło zostać wykonane).
	 *
	 * @throws AiProviderException
	 */
	public function generate(AiRequest $request): AiResponse;
}
