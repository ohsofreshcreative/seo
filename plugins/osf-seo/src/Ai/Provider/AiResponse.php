<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Provider;

/**
 * Odpowiedź modelu: tekst (JSON zgodny ze schematem — dopiero do walidacji po stronie PHP), zużycie i identyfikator odpowiedzi dostawcy.
 */
final class AiResponse
{
	public function __construct(
		public readonly string $text,
		public readonly ?AiUsage $usage,
		public readonly ?string $responseId = null,
		public readonly ?string $model = null,
		public readonly ?string $serviceTier = null,
	) {
	}

	public function __debugInfo(): array
	{
		return ['text' => '[' . strlen($this->text) . ' bytes]', 'usage' => $this->usage?->toArray(), 'response_id' => $this->responseId];
	}
}
