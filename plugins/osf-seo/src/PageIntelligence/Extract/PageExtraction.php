<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Extract;

use OsfSeo\Strategy\Topics\TopicContextBuilder;

/**
 * Wynik ekstrakcji jednej strony (zapisywany w `page_snapshots.data` — bez surowego HTML). `contentHash` = odcisk wyłącznie treści
 * (meta, dyrektywy, nagłówki, tekst, linki) — ta sama treść pobrana innego dnia daje ten sam odcisk.
 */
final class PageExtraction
{
	/**
	 * @param array<string, mixed> $meta
	 * @param array<string, mixed> $headings
	 * @param array<string, mixed> $content
	 * @param array<string, mixed> $links
	 * @param array<string, mixed> $technical
	 * @param array<string, mixed> $quality
	 * @param array<string, mixed> $limits
	 */
	public function __construct(
		public readonly int $version,
		public readonly string $charset,
		public readonly array $meta,
		public readonly array $headings,
		public readonly array $content,
		public readonly array $links,
		public readonly array $technical,
		public readonly array $quality,
		public readonly array $limits,
	) {
	}

	public static function empty(string $url, string $charset, int $bytes): self
	{
		return new self(
			HtmlExtractor::VERSION,
			$charset,
			['title' => null, 'description' => null, 'canonical' => null, 'canonical_count' => 0, 'canonical_raw' => null, 'robots' => [], 'x_robots_tag' => [], 'lang' => null, 'og' => ['title' => null, 'description' => null, 'type' => null]],
			['list' => [], 'counts' => ['h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0], 'total' => 0, 'outline' => ['missing_h1' => true, 'multiple_h1' => false, 'first_level' => null, 'skipped_levels' => 0, 'empty_headings' => 0]],
			['source' => 'none', 'text' => '', 'word_count' => 0, 'sections' => [], 'removed' => []],
			['internal' => [], 'external' => [], 'counts' => ['internal' => 0, 'external' => 0, 'ignored' => 0, 'nofollow' => 0, 'in_main' => 0, 'total' => 0]],
			['indexability' => 'unknown', 'indexability_basis' => ['unparsable_html'], 'directives' => [], 'canonical_status' => 'missing', 'note' => 'Directives only: HTML does not prove whether Google indexed the page.'],
			['level' => 'empty', 'reasons' => ['unparsable_html'], 'word_count' => 0, 'script_count' => 0, 'js_markers' => [], 'text_to_html_ratio' => 0.0, 'note' => 'Content not detected in the fetched HTML may still exist on the page (JavaScript rendering, blocked resources).'],
			['truncated' => [], 'input_bytes' => $bytes, 'parse_errors' => 0],
		);
	}

	/** Odcisk treści (bez czasu pobrania, rozmiaru odpowiedzi i diagnostyki). */
	public function contentHash(): string
	{
		$content = [
			'v' => $this->version,
			'meta' => [$this->meta['title'], $this->meta['description'], $this->meta['canonical'], $this->meta['robots'], $this->meta['x_robots_tag'], $this->meta['lang']],
			'headings' => array_map(static fn (array $heading): array => [$heading['level'], $heading['text']], $this->headings['list']),
			'text' => $this->content['text'],
			'links' => array_map(static fn (array $link): array => [$link['url'], $link['anchor']], [...$this->links['internal'], ...$this->links['external']]),
		];

		return hash('sha256', (string) json_encode(TopicContextBuilder::canonical($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	public function title(): ?string
	{
		$title = $this->meta['title'] ?? null;

		return is_string($title) && $title !== '' ? mb_substr($title, 0, 500, 'UTF-8') : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'extractor_version' => $this->version,
			'charset' => $this->charset,
			'meta' => $this->meta,
			'headings' => $this->headings,
			'content' => $this->content,
			'links' => $this->links,
			'technical' => $this->technical,
			'quality' => $this->quality,
			'limits' => $this->limits,
		];
	}
}
