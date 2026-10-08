<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Run;

/**
 * Uruchomienie analizy AI (`ai_runs`) — metadane bez treści: zadanie, dostawca, model, wersje, odciski, status, tokeny, koszt i decyzja
 * użytkownika. Wejście, odpowiedź modelu, zwalidowany wynik i raport walidacji są osobno (`ai_run_payloads`).
 *
 * Statusy: `reserved` (koszt zarezerwowany, żądanie jeszcze niewysłane) → `running` (żądanie w toku) → `succeeded` (odpowiedź zgodna
 * z kontraktem) | `invalid` (odpowiedź niezgodna z kontraktem — wynik odrzucony) | `failed` (błąd; dostawca na pewno niczego nie wykonał
 * albo rozliczono zgłoszone zużycie) | `uncertain` (nie wiadomo, czy dostawca wykonał i rozliczył żądanie — liczy się rezerwacja).
 */
final class AiRun
{
	public const STATUS_RESERVED = 'reserved';

	public const STATUS_RUNNING = 'running';

	public const STATUS_SUCCEEDED = 'succeeded';

	public const STATUS_INVALID = 'invalid';

	public const STATUS_FAILED = 'failed';

	public const STATUS_UNCERTAIN = 'uncertain';

	/** Statusy w toku (rezerwacja albo żądanie bez wyniku). */
	public const ACTIVE = [self::STATUS_RESERVED, self::STATUS_RUNNING];

	/** Koszt rozliczony ze zgłoszonego zużycia tokenów. */
	public const COST_USAGE = 'usage';

	/** Wynik niepewny — do limitów liczy się cała rezerwacja (ostrożnie). */
	public const COST_RESERVATION = 'reservation';

	/** Dostawca na pewno niczego nie wykonał (konfiguracja, odrzucone żądanie, limit żądań) albo żądanie nie zostało wysłane. */
	public const COST_NOT_CHARGED = 'not_charged';

	/** Dostawca bezpłatny (testowy). */
	public const COST_FREE = 'free';

	public const DECISION_ACCEPTED = 'accepted';

	public const DECISION_REJECTED = 'rejected';

	public const DECISIONS = [self::DECISION_ACCEPTED, self::DECISION_REJECTED];

	public const TRIGGER_CLI = 'cli';

	public const TRIGGER_PANEL = 'panel';

	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $projectId,
		public readonly ?int $topicId,
		public readonly ?string $topicPublicId,
		public readonly string $task,
		public readonly string $provider,
		public readonly string $model,
		public readonly bool $paid,
		public readonly string $promptVersion,
		public readonly int $contextVersion,
		public readonly int $contractVersion,
		public readonly string $contextFingerprint,
		public readonly ?string $evidenceHash,
		public readonly string $status,
		public readonly string $triggerType,
		public readonly ?int $requestedBy,
		public readonly string $createdAt,
		public readonly ?string $startedAt,
		public readonly ?string $finishedAt,
		public readonly ?int $inputTokensEstimate,
		public readonly ?int $inputTokens,
		public readonly ?int $cachedTokens,
		public readonly ?int $outputTokens,
		public readonly float $estimatedCost,
		public readonly float $reservedCost,
		public readonly ?float $actualCost,
		public readonly ?string $costBasis,
		public readonly ?string $providerResponseId,
		public readonly ?string $errorCode,
		public readonly int $validationErrors,
		public readonly ?string $decision,
		public readonly ?int $decidedBy,
		public readonly ?string $decidedAt,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$int = static fn (?string $value): ?int => $value === null ? null : (int) $value;

		return new self(
			(int) $row['id'],
			(string) $row['public_id'],
			(int) $row['project_id'],
			$int($row['topic_id'] ?? null),
			$row['topic_public_id'] ?? null,
			(string) $row['task'],
			(string) $row['provider'],
			(string) $row['model'],
			(int) $row['paid'] === 1,
			(string) $row['prompt_version'],
			(int) $row['context_version'],
			(int) $row['contract_version'],
			bin2hex((string) $row['context_fingerprint']),
			$row['evidence_hash'] === null ? null : bin2hex($row['evidence_hash']),
			(string) $row['status'],
			(string) $row['trigger_type'],
			$int($row['requested_by']),
			(string) $row['created_at'],
			$row['started_at'],
			$row['finished_at'],
			$int($row['input_tokens_estimate']),
			$int($row['input_tokens']),
			$int($row['cached_tokens']),
			$int($row['output_tokens']),
			(float) $row['estimated_cost'],
			(float) $row['reserved_cost'],
			$row['actual_cost'] === null ? null : (float) $row['actual_cost'],
			$row['cost_basis'],
			$row['provider_response_id'],
			$row['error_code'],
			(int) $row['validation_errors'],
			$row['decision'],
			$int($row['decided_by']),
			$row['decided_at'],
		);
	}

	public function isActive(): bool
	{
		return in_array($this->status, self::ACTIVE, true);
	}

	/** Koszt liczony do limitów: rozliczony, a bez rozliczenia — rezerwacja (tylko dostawcy płatni). */
	public function chargedCost(): float
	{
		return $this->paid ? ($this->actualCost ?? $this->reservedCost) : 0.0;
	}

	/**
	 * Metadane bez treści (CLI, przyszły panel) — bez identyfikatorów wewnętrznych; użytkownicy tylko na życzenie.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(bool $withUsers = false): array
	{
		return [
			'id' => $this->publicId,
			'topic' => $this->topicPublicId,
			'task' => $this->task,
			'provider' => $this->provider,
			'model' => $this->model,
			'paid' => $this->paid,
			'prompt_version' => $this->promptVersion,
			'context_version' => $this->contextVersion,
			'contract_version' => $this->contractVersion,
			'context_fingerprint' => $this->contextFingerprint,
			'evidence_hash' => $this->evidenceHash,
			'status' => $this->status,
			'trigger' => $this->triggerType,
			'created_at' => $this->createdAt,
			'started_at' => $this->startedAt,
			'finished_at' => $this->finishedAt,
			'tokens' => [
				'input_estimate' => $this->inputTokensEstimate,
				'input' => $this->inputTokens,
				'cached' => $this->cachedTokens,
				'output' => $this->outputTokens,
			],
			'cost' => [
				'estimated' => round($this->estimatedCost, 6),
				'reserved' => round($this->reservedCost, 6),
				'actual' => $this->actualCost === null ? null : round($this->actualCost, 6),
				'basis' => $this->costBasis,
				'charged' => round($this->chargedCost(), 6),
			],
			'error' => $this->errorCode,
			'validation_errors' => $this->validationErrors,
			'decision' => $this->decision,
			'decided_at' => $this->decidedAt,
		] + ($withUsers ? ['requested_by' => $this->requestedBy, 'decided_by' => $this->decidedBy] : []);
	}
}
