<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Wynik zadania SERP od dostawcy po parsowaniu: metadane strony wyników i zapisywane elementy w kolejności dostawcy.
 */
final class SerpPage
{
	/**
	 * @param list<SerpItem> $items wyniki organiczne i wyróżnione fragmenty
	 */
	public function __construct(
		public readonly array $items,
		public readonly ?string $checkedAt = null,
		public readonly ?string $seDomain = null,
		public readonly ?int $seResultsCount = null,
		public readonly ?int $pagesCount = null,
		public readonly ?int $itemsCount = null,
		public readonly int $itemTypes = 0,
		public readonly ?string $spellType = null,
		public readonly ?string $spellKeyword = null,
		public readonly int $skipped = 0,
	) {
	}

	public function organicCount(): int
	{
		return count(array_filter($this->items, static fn (SerpItem $item): bool => $item->isOrganic()));
	}
}
