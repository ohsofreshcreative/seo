<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Szansa do wyświetlenia: trwały stan pracy + dowody z wykrycia wybranego okresu
 * (albo ostatni snapshot, gdy sygnał w tym okresie nie występuje / szansa jest nieaktywna).
 */
final class Opportunity
{
	/**
	 * @param array<string, mixed> $evidence
	 * @param array<string, mixed>|null $baseline
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly OpportunityType $type,
		public readonly string $property,
		public readonly ?string $pageUrl,
		public readonly ?string $keyword,
		public readonly OpportunityState $state,
		public readonly OpportunityStatus $status,
		public readonly ?string $note,
		public readonly ?string $completedOn,
		public readonly ?array $baseline,
		public readonly int $priority,
		public readonly Confidence $confidence,
		public readonly int $periodDays,
		public readonly ?string $latestDate,
		public readonly array $evidence,
		/** Dowody z bieżącego wykrycia w wybranym okresie (false = ostatni snapshot). */
		public readonly bool $detectedInPeriod,
		public readonly string $firstDetectedAt,
		public readonly string $lastDetectedAt,
		public readonly ?string $inactiveSince,
		public readonly ?string $statusChangedAt,
		public readonly ?int $statusChangedBy,
	) {
	}

	/**
	 * @param array<string, string|null> $row wiersz opportunities + kolumny d_* (wykrycie albo snapshot)
	 */
	public static function fromRow(array $row, bool $detectedInPeriod): self
	{
		return new self(
			id: (int) $row['id'],
			publicId: (string) $row['public_id'],
			type: OpportunityType::from((string) $row['type']),
			property: (string) $row['property'],
			pageUrl: $row['page_url'] ?? null,
			keyword: $row['keyword'] ?? null,
			state: OpportunityState::from((string) $row['state']),
			status: OpportunityStatus::from((string) $row['status']),
			note: $row['note'] ?? null,
			completedOn: $row['completed_on'] ?? null,
			baseline: self::decode($row['baseline'] ?? null),
			priority: (int) ($row['d_priority'] ?? 0),
			confidence: Confidence::tryFrom((int) ($row['d_confidence'] ?? 0)) ?? Confidence::Low,
			periodDays: (int) ($row['d_period_days'] ?? 0),
			latestDate: $row['d_latest_date'] ?? null,
			evidence: self::decode($row['d_evidence'] ?? null) ?? [],
			detectedInPeriod: $detectedInPeriod,
			firstDetectedAt: (string) $row['first_detected_at'],
			lastDetectedAt: (string) $row['last_detected_at'],
			inactiveSince: $row['inactive_since'] ?? null,
			statusChangedAt: $row['status_changed_at'] ?? null,
			statusChangedBy: isset($row['status_changed_by']) ? (int) $row['status_changed_by'] : null,
		);
	}

	public function title(): string
	{
		return $this->evidence === [] ? (string) ($this->pageUrl ?? $this->keyword) : OpportunityExplainer::title($this->evidence);
	}

	public function summary(): string
	{
		return $this->evidence === [] ? '' : OpportunityExplainer::summary($this->type, $this->evidence);
	}

	/**
	 * @return list<string>
	 */
	public function recommendations(): array
	{
		return $this->evidence === [] ? [] : OpportunityExplainer::recommendations($this->type, $this->evidence);
	}

	public function current(): Stats
	{
		return Stats::fromArray($this->evidence['metrics']['current'] ?? null);
	}

	public function previous(): Stats
	{
		return Stats::fromArray($this->evidence['metrics']['previous'] ?? null);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function decode(?string $json): ?array
	{
		if ($json === null || $json === '') {
			return null;
		}

		$data = json_decode($json, true);

		return is_array($data) ? $data : null;
	}
}
