<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Fraza × podstrona (dane `query_page_daily`, adres bez fragmentu) w bieżącym i poprzednim okresie.
 */
final class PagePair
{
	public function __construct(
		public readonly int $keywordId,
		public readonly string $url,
		public readonly Stats $current,
		public readonly Stats $previous,
	) {
	}
}
