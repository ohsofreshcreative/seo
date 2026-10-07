<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Strategy\Decision\ConfidenceResult;
use OsfSeo\Strategy\Decision\PriorityModel;
use OsfSeo\Strategy\Decision\StrategyAction;
use OsfSeo\Strategy\StrategySource;
use OsfSeo\Strategy\Target\TargetState;

/**
 * Temat Strategii (odczyt): stan, działanie, pewność, priorytet, strona docelowa, status pracy; analiza i podstawa decyzji w szczegółach.
 */
final class TopicRow
{
	/**
	 * @param array<string, mixed>|null $analysis
	 * @param array<string, mixed>|null $statusBasis
	 * @param array<string, mixed>|null $baseline
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly bool $active,
		public readonly ?string $inactiveReason,
		public readonly ?string $mergedInto,
		public readonly ?string $label,
		public readonly int $keywordsCount,
		public readonly ?int $demand,
		public readonly ?string $action,
		public readonly ?string $actionReason,
		public readonly ?int $confidence,
		public readonly ?string $confidenceLevel,
		public readonly ?int $priority,
		public readonly ?string $targetState,
		public readonly ?string $targetUrl,
		public readonly ?string $manualTargetUrl,
		public readonly bool $manualNoPage,
		public readonly ?string $serpBand,
		public readonly string $status,
		public readonly ?string $note,
		public readonly ?string $statusChangedAt,
		public readonly ?int $statusChangedBy,
		public readonly ?string $completedOn,
		public readonly bool $decisionChanged,
		public readonly ?string $evidenceHash,
		public readonly ?string $firstSeenAt,
		public readonly ?string $refreshedAt,
		public readonly ?array $analysis = null,
		public readonly ?array $statusBasis = null,
		public readonly ?array $baseline = null,
		public readonly int $sources = 0,
		public readonly ?int $serpRank = null,
		public readonly ?string $serpCheckedAt = null,
		public readonly ?int $gscImpressions = null,
		public readonly ?float $gscPosition = null,
		public readonly ?string $leaderIntent = null,
		public readonly ?int $leaderDifficulty = null,
		public readonly ?int $leaderVolume = null,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row, bool $withNote = true): self
	{
		$json = static function (mixed $value): ?array {
			$decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;

			return is_array($decoded) ? $decoded : null;
		};

		return new self(
			(int) $row['id'],
			(string) $row['public_id'],
			(int) $row['active'] === 1,
			$row['inactive_reason'] ?? null,
			$row['merged_into'] ?? null,
			$row['label'] ?? null,
			(int) $row['keywords_count'],
			isset($row['demand']) ? (int) $row['demand'] : null,
			$row['action'] ?? null,
			$row['action_reason'] ?? null,
			isset($row['confidence']) ? (int) $row['confidence'] : null,
			$row['confidence_level'] ?? null,
			isset($row['priority']) ? (int) $row['priority'] : null,
			$row['target_state'] ?? null,
			$row['target_url'] ?? null,
			$row['manual_target_url'] ?? null,
			(int) ($row['manual_no_page'] ?? 0) === 1,
			$row['serp_band'] ?? null,
			(string) $row['status'],
			$withNote ? ($row['note'] ?? null) : null,
			$row['status_changed_at'] ?? null,
			// Identyfikator użytkownika tylko dla uprawnionych (klient go nie widzi — D62).
			$withNote && isset($row['status_changed_by']) ? (int) $row['status_changed_by'] : null,
			$row['completed_on'] ?? null,
			(int) ($row['decision_changed'] ?? 0) === 1,
			$row['evidence_hash'] ?? null,
			$row['first_seen_at'] ?? null,
			$row['refreshed_at'] ?? null,
			$json($row['analysis'] ?? null),
			$json($row['status_basis'] ?? null),
			$json($row['baseline'] ?? null),
			(int) ($row['sources'] ?? 0),
			isset($row['serp_rank']) ? (int) $row['serp_rank'] : null,
			$row['serp_checked_at'] ?? null,
			isset($row['gsc_impressions']) ? (int) $row['gsc_impressions'] : null,
			isset($row['gsc_position']) ? (float) $row['gsc_position'] : null,
			isset($row['leader_intent']) && $row['leader_intent'] !== '' ? (string) $row['leader_intent'] : null,
			isset($row['leader_kd']) ? (int) $row['leader_kd'] : null,
			isset($row['leader_volume']) ? (int) $row['leader_volume'] : null,
		);
	}

	/**
	 * Kody źródeł dowodów fraz tematu.
	 *
	 * @return list<string>
	 */
	public function sourceCodes(): array
	{
		return array_values(array_map(
			static fn (StrategySource $source): string => $source->value,
			array_filter(StrategySource::cases(), fn (StrategySource $source): bool => ($this->sources & $source->bit()) !== 0),
		));
	}

	/** Temat wymaga uwagi: po decyzji użytkownika zmieniło się działanie albo strona docelowa. */
	public function needsAttention(): bool
	{
		return $this->decisionChanged && $this->status !== TopicStatus::New->value;
	}

	public function actionLabel(): string
	{
		return $this->action === null ? '—' : (StrategyAction::tryFrom($this->action)?->label() ?? $this->action);
	}

	public function targetLabel(): string
	{
		return $this->targetState === null ? '—' : (TargetState::tryFrom($this->targetState)?->label() ?? $this->targetState);
	}

	public function confidenceLabel(): string
	{
		return ConfidenceResult::levelLabel($this->confidenceLevel);
	}

	public function statusLabel(): string
	{
		return TopicStatus::tryFrom($this->status)?->label() ?? $this->status;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(bool $detail = false): array
	{
		$data = [
			'id' => $this->publicId,
			'active' => $this->active,
			'inactive_reason' => $this->inactiveReason,
			'merged_into' => $this->mergedInto,
			'label' => $this->label,
			'keywords' => $this->keywordsCount,
			'demand' => $this->demand,
			'action' => $this->action,
			'action_reason' => $this->actionReason,
			'confidence' => $this->confidence,
			'confidence_level' => $this->confidenceLevel,
			'priority' => $this->priority,
			'priority_band' => PriorityModel::band($this->priority),
			'target_state' => $this->targetState,
			'target_url' => $this->targetUrl,
			'manual_target' => $this->manualTargetUrl !== null || $this->manualNoPage,
			'manual_no_page' => $this->manualNoPage,
			'serp_band' => $this->serpBand,
			'status' => $this->status,
			'note' => $this->note,
			'status_changed_at' => $this->statusChangedAt,
			'completed_on' => $this->completedOn,
			'decision_changed' => $this->decisionChanged,
			'sources' => $this->sourceCodes(),
			'serp_rank' => $this->serpRank,
			'serp_checked_at' => $this->serpCheckedAt,
			'gsc_impressions' => $this->gscImpressions,
			'gsc_position' => $this->gscPosition,
			'evidence_hash' => $this->evidenceHash,
			'first_seen_at' => $this->firstSeenAt,
			'refreshed_at' => $this->refreshedAt,
		];

		if ($detail) {
			$data['analysis'] = $this->analysis;
			$data['status_basis'] = $this->statusBasis;
			$data['baseline'] = $this->baseline;
		}

		return $data;
	}
}
