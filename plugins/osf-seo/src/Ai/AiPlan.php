<?php

declare(strict_types=1);

namespace OsfSeo\Ai;

use OsfSeo\Ai\Context\AiContext;
use OsfSeo\Ai\Contract\AnalysisContract;
use OsfSeo\Ai\Prompt\PromptTemplate;

/**
 * Plan analizy AI (bez żadnego żądania): dostawca, model, kontekst (odcisk, rozmiar, braki danych), szacowane tokeny, koszt maksymalny,
 * stan budżetu i powody blokady. Uruchomienie jest możliwe tylko bez powodów blokady (płatne — dodatkowo z potwierdzeniem).
 */
final class AiPlan
{
	/**
	 * @param list<string> $blockers
	 * @param array<string, mixed>|null $budget
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
	) {
	}

	public function runnable(): bool
	{
		return $this->blockers === [];
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
			'task' => PromptTemplate::TASK,
			'prompt_version' => PromptTemplate::VERSION,
			'contract_version' => AnalysisContract::VERSION,
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
			'tokens' => ['input_estimate' => $this->inputTokensEstimate, 'max_output' => $this->maxOutputTokens],
			'max_cost' => $this->maxCost,
			'budget' => $this->budget,
		];
	}
}
