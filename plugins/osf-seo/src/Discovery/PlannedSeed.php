<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Seed w planie przebiegu: liczba płatnych żądań (stron), maksymalna liczba elementów i koszt; seed pobrany niedawno
 * (cache) ma 0 żądań i koszt 0.
 */
final class PlannedSeed
{
	public function __construct(
		public readonly string $seed,
		public readonly string $source,
		public readonly int $requests,
		public readonly int $maxItems,
		public readonly float $estimatedCost,
		/** Kiedy seed był ostatnio pobrany z tymi lub szerszymi parametrami (null — nie jest w cache). */
		public readonly ?string $cachedAt = null,
	) {
	}

	public function isCached(): bool
	{
		return $this->cachedAt !== null;
	}
}
