<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

/**
 * Co pobrać: adresy podane ręcznie (strona projektu albo konkurenta dozwolonego przez politykę), stronę docelową tematu Strategii albo
 * wybrane organiczne wyniki zapisanego pomiaru SERP frazy (pozycje). Nigdy „cały SERP” ani lista bez limitu.
 */
final class PageSelection
{
	public const URLS = 'urls';

	public const TOPIC = 'topic';

	public const SERP = 'serp';

	/**
	 * @param list<string> $urls
	 * @param list<int> $ranks
	 */
	private function __construct(
		public readonly string $type,
		public readonly array $urls = [],
		public readonly ?string $topic = null,
		public readonly ?string $keyword = null,
		public readonly array $ranks = [],
	) {
	}

	/**
	 * @param list<string> $urls
	 */
	public static function urls(array $urls): self
	{
		return new self(self::URLS, array_values(array_unique(array_filter(array_map('trim', $urls), static fn (string $url): bool => $url !== ''))));
	}

	public static function topic(string $topic): self
	{
		return new self(self::TOPIC, [], trim($topic));
	}

	/**
	 * @param list<int> $ranks pozycje wyników organicznych (rank_group)
	 */
	public static function serp(string $keyword, array $ranks): self
	{
		return new self(self::SERP, [], null, trim($keyword), array_values(array_unique(array_filter(array_map('intval', $ranks), static fn (int $rank): bool => $rank >= 1 && $rank <= 100))));
	}
}
