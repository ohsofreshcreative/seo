<?php

declare(strict_types=1);

namespace OsfSeo\Ai;

use OsfSeo\Ai\Analysis\Readiness;
use OsfSeo\Ai\Context\AiContext;
use OsfSeo\Ai\Contract\AnalysisContract;
use OsfSeo\Ai\Prompt\PromptTemplate;

/**
 * Plan analizy AI (bez żadnego żądania): dostawca, model, kontekst (odcisk, rozmiar, braki danych), szacowane tokeny, koszt maksymalny,
 * stan budżetu i powody blokady. Uruchomienie jest możliwe tylko bez powodów blokady (płatne — dodatkowo z potwierdzeniem).
 *
 * Analizy rekomendacji (faza C): typ (zadanie), wersje instrukcji i kontraktu, język odpowiedzi, gotowość i odcisk planu (`fingerprint`) —
 * wykonanie płatne wymaga zatwierdzenia dokładnie tego odcisku (zmiana kontekstu, modelu, cen, limitu tokenów albo kosztu = nowy plan, D108).
 */
final class AiPlan
{
	/**
	 * @param list<string> $blockers
	 * @param array<string, mixed>|null $budget
	 * @param array<string, ?float> $prices ceny z konfiguracji (USD za 1 mln tokenów) — część odcisku planu
	 * @param array<string, string> $options opcje żądania zmieniające koszt albo treść (np. `reasoning_effort`) — w odcisku tylko, gdy ustawione
	 */
	public function __construct(
		public readonly string $provider,
		public readonly ?string $model,
		public readonly bool $paid,
		public readonly AiContext $context,
		public readonly ?string $focus,
		public readonly int $inputTokensEstimate,
		public readonly int $maxOutputTokens,
		public readonly ?float $maxCost,
		public readonly array $blockers,
		public readonly ?array $budget,
		public readonly string $task = PromptTemplate::TASK,
		public readonly string $promptVersion = PromptTemplate::VERSION,
		public readonly int $contractVersion = AnalysisContract::VERSION,
		public readonly ?string $language = null,
		public readonly ?Readiness $readiness = null,
		public readonly array $prices = [],
		public readonly array $options = [],
	) {
	}

	public function runnable(): bool
	{
		return $this->blockers === [];
	}

	/**
	 * Odcisk planu: wszystko, co decyduje o treści i koszcie wywołania (bez stanu budżetu — limity sprawdza rezerwacja pod blokadą).
	 */
	public function fingerprint(): string
	{
		$data = [
			'task' => $this->task,
			'provider' => $this->provider,
			'model' => $this->model,
			'paid' => $this->paid,
			'prompt_version' => $this->promptVersion,
			'contract_version' => $this->contractVersion,
			'language' => $this->language,
			'context' => $this->context->fingerprint(),
			'focus' => $this->focus,
			'input_tokens_estimate' => $this->inputTokensEstimate,
			'max_output_tokens' => $this->maxOutputTokens,
			'max_cost' => $this->maxCost === null ? null : round($this->maxCost, 6),
			'prices' => $this->prices,
		];

		// Opcje tylko, gdy ustawione — odcisk planów bez nich pozostaje taki jak przed fazą E.
		if ($this->options !== []) {
			$data['options'] = $this->options;
		}

		return hash('sha256', (string) json_encode($data, AiContext::JSON_FLAGS));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'runnable' => $this->runnable(),
			'blockers' => $this->blockers,
			'provider' => $this->provider,
			'model' => $this->model,
			'paid' => $this->paid,
			'requires_confirmation' => $this->paid,
			'task' => $this->task,
			'prompt_version' => $this->promptVersion,
			'contract_version' => $this->contractVersion,
			'language' => $this->language,
			'plan_fingerprint' => $this->fingerprint(),
			'readiness' => $this->readiness?->toArray(),
			'context' => [
				'version' => $this->context->body['context_version'] ?? null,
				'topic' => $this->context->topicPublicId,
				'fingerprint' => $this->context->fingerprint(),
				'evidence_fingerprint' => $this->context->evidenceFingerprint(),
				'evidence_hash' => $this->context->evidenceHash(),
				'bytes' => $this->context->bytes(),
				'refs' => count($this->context->refs()),
				'data_gaps' => $this->context->dataGaps(),
				'omitted' => $this->context->body['limits']['omitted'] ?? [],
				'within_budget' => $this->context->withinBudget(),
			],
			'focus' => $this->focus,
			'options' => $this->options,
			'tokens' => ['input_estimate' => $this->inputTokensEstimate, 'max_output' => $this->maxOutputTokens],
			'max_cost' => $this->maxCost,
			'budget' => $this->budget,
		];
	}
}
