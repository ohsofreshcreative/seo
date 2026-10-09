<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Provider;

/**
 * Żądanie do modelu: instrukcje aplikacji (osobno), wejście (zadanie + dowody + treści niezaufane w osobnych blokach), schemat
 * odpowiedzi strukturalnej i limit tokenów odpowiedzi. `hints` nie są wysyłane do dostawców rzeczywistych — służą wyłącznie
 * dostawcy testowemu (znane odwołania do dowodów i braki danych).
 */
final class AiRequest
{
	/**
	 * @param array<string, mixed> $schema JSON Schema odpowiedzi
	 * @param array<string, mixed> $hints
	 */
	public function __construct(
		public readonly string $model,
		public readonly string $instructions,
		public readonly string $input,
		public readonly string $schemaName,
		public readonly array $schema,
		public readonly int $maxOutputTokens,
		public readonly ?float $temperature = null,
		public readonly array $hints = [],
		public readonly ?string $reasoningEffort = null,
	) {
	}

	/** Treść nie trafia do zrzutów obiektu (dane projektu). */
	public function __debugInfo(): array
	{
		return ['model' => $this->model, 'schema' => $this->schemaName, 'input' => '[' . strlen($this->input) . ' bytes]'];
	}
}
