<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Discovery\ExclusionList;
use OsfSeo\Market\Market;
use OsfSeo\Serp\Competitor;

/**
 * Dane wejściowe jednego przeliczenia Luk SEO projektu.
 */
final class RefreshContext
{
	/**
	 * @param array<int, Competitor> $competitorsByDataset id zbioru domeny → aktywny konkurent
	 * @param list<BrandMatcher> $competitorBrands
	 * @param array{0: string, 1: string}|null $window okno GSC (null — projekt bez danych GSC)
	 */
	public function __construct(
		public readonly int $projectId,
		public readonly Market $market,
		public readonly GapSettings $settings,
		public readonly array $competitorsByDataset,
		public readonly ?GapDomain $baseline,
		public readonly ExclusionList $exclusions,
		public readonly ExclusionList $includes,
		public readonly BrandMatcher $ownBrand,
		public readonly array $competitorBrands,
		public readonly ?array $window,
		public readonly string $serpSince,
		public readonly string $marker,
	) {
	}
}
