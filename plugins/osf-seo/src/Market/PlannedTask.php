<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Jedno płatne zadanie planu synchronizacji: wolumen (paczka do 1000 fraz) albo trudność SEO.
 */
final class PlannedTask
{
	public const VOLUME = 'volume';

	public const DIFFICULTY = 'difficulty';

	/**
	 * @param list<string> $keywords postacie znormalizowane
	 */
	public function __construct(
		public readonly string $type,
		public readonly ProviderEndpoint $endpoint,
		public readonly array $keywords,
		public readonly float $estimatedCost,
		/** `ok` albo powód, dla którego zadanie nie zostanie wysłane w tym przebiegu (limit zadań/kosztów). */
		public readonly string $blockedBy = CostBudget::OK,
	) {
	}

	public function isAllowed(): bool
	{
		return $this->blockedBy === CostBudget::OK;
	}
}
