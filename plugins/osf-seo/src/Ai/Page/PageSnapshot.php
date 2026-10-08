<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Page;

use OsfSeo\Ai\Context\TextSanitizer;

/**
 * Kontrakt danych Page Intelligence (przygotowanie do fazy B — docs/ARCHITECTURE.md, sekcja 22.10): zapisana migawka strony projektu.
 * W fazie A nic jej nie tworzy (brak crawlera i pobierania) — kontekst AI zawsze mówi „treść niepobrana”.
 *
 * Treść strony to dane zewnętrzne (niezaufane): w kontekście AI wyłącznie przycięte pola tekstowe w bloku niezaufanym.
 */
final class PageSnapshot
{
	public const MAX_HEADINGS = 30;

	public const MAX_LINKS = 30;

	public const MAX_MAIN_TEXT = 6000;

	/**
	 * @param list<array{level: int, text: string}> $headings H1–H6 w kolejności dokumentu
	 * @param list<array{url: string, anchor: string}> $internalLinks
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $finalUrl,
		public readonly int $httpStatus,
		public readonly string $fetchedAt,
		public readonly ?string $title,
		public readonly ?string $metaDescription,
		public readonly ?string $canonical,
		public readonly ?string $robots,
		public readonly ?bool $indexable,
		public readonly array $headings,
		public readonly ?string $mainText,
		public readonly int $wordCount,
		public readonly array $internalLinks,
		public readonly string $contentHash,
		public readonly bool $truncated = false,
	) {
	}

	/**
	 * Postać do kontekstu AI (limity pól i elementów).
	 *
	 * @return array<string, mixed>
	 */
	public function toContext(): array
	{
		return [
			'available' => true,
			'url' => TextSanitizer::line($this->url, 300),
			'final_url' => TextSanitizer::line($this->finalUrl, 300),
			'http_status' => $this->httpStatus,
			'fetched_at' => $this->fetchedAt,
			'title' => TextSanitizer::line($this->title, 160),
			'meta_description' => TextSanitizer::line($this->metaDescription, 300),
			'canonical' => TextSanitizer::line($this->canonical, 300),
			'robots' => TextSanitizer::line($this->robots, 100),
			'indexable' => $this->indexable,
			'headings' => array_map(static fn (array $heading): array => ['level' => $heading['level'], 'text' => TextSanitizer::line($heading['text'], 160)], array_slice($this->headings, 0, self::MAX_HEADINGS)),
			'main_text' => $this->mainText === null ? null : TextSanitizer::text($this->mainText, self::MAX_MAIN_TEXT),
			'word_count' => $this->wordCount,
			'internal_links' => array_map(static fn (array $link): array => ['url' => TextSanitizer::line($link['url'], 300), 'anchor' => TextSanitizer::line($link['anchor'], 120)], array_slice($this->internalLinks, 0, self::MAX_LINKS)),
			'content_hash' => $this->contentHash,
			'truncated' => $this->truncated || count($this->headings) > self::MAX_HEADINGS || count($this->internalLinks) > self::MAX_LINKS,
		];
	}
}
