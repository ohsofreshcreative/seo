<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Discovery\ExclusionList;
use OsfSeo\Gap\BrandMatcher;

/**
 * Filtry kandydatów (poza frazami dodanymi ręcznie): marka projektu, marki aktywnych konkurentów i wykluczenia projektu
 * (wspólne z Nowymi frazami i Lukami SEO). Te same reguły co w Lukach SEO — frazy markowe nie są pracą strategiczną.
 */
final class CandidateFilter
{
	public const BRAND_OWN = 'brand_own';

	public const BRAND_COMPETITOR = 'brand_competitor';

	public const EXCLUDED = 'excluded';

	/**
	 * @param list<BrandMatcher> $competitorBrands
	 */
	public function __construct(
		private readonly BrandMatcher $ownBrand,
		private readonly array $competitorBrands,
		private readonly ExclusionList $exclusions,
	) {
	}

	public static function none(): self
	{
		return new self(BrandMatcher::build([], []), [], ExclusionList::parse(null));
	}

	/** Powód pominięcia frazy (postać znormalizowana) albo null. */
	public function reason(string $keyword, ?string $intent): ?string
	{
		if ($this->ownBrand->match($keyword, $intent) !== null) {
			return self::BRAND_OWN;
		}

		foreach ($this->competitorBrands as $brand) {
			if ($brand->match($keyword, $intent) !== null) {
				return self::BRAND_COMPETITOR;
			}
		}

		return $this->exclusions->match($keyword) === null ? null : self::EXCLUDED;
	}
}
