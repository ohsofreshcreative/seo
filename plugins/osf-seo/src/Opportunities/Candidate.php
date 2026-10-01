<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Szansa wykryta w jednej analizie (jeden projekt, jeden okres) — przed zapisem.
 */
final class Candidate
{
	/**
	 * @param array<string, mixed> $evidence dowody (JSON): okresy, metryki, frazy/adresy, składniki priorytetu, pewność
	 */
	public function __construct(
		public readonly OpportunityType $type,
		public readonly string $fingerprint,
		public readonly ?string $pageUrl,
		public readonly ?string $keyword,
		public readonly int $priority,
		public readonly Confidence $confidence,
		public readonly int $impressions,
		public readonly int $clicks,
		public readonly string $searchText,
		public readonly array $evidence,
	) {
	}
}
