<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Wynik z SERP zapisywany w pomiarze: wynik organiczny albo wyróżniony fragment (featured snippet — osobny typ,
 * nigdy nie zamieniany na pozycję organiczną #1).
 *
 * `rankGroup` — pozycja wśród wyników tego samego typu (dla wyników organicznych: „Pozycja SERP”),
 * `rankAbsolute` — pozycja wśród wszystkich elementów strony (zmienia się, gdy Google dodaje reklamy i moduły).
 */
final class SerpItem
{
	public const TYPE_ORGANIC = 1;

	public const TYPE_FEATURED_SNIPPET = 2;

	public const FLAG_FEATURED = 1;

	public const FLAG_AMP = 2;

	public const FLAG_IMAGE = 4;

	public const FLAG_VIDEO = 8;

	public const FLAG_WEB_STORY = 16;

	public const FLAG_MALICIOUS = 32;

	public const FLAG_HIGHLY_CITED = 64;

	public const FLAG_RATING = 128;

	public const FLAG_PRICE = 256;

	public const FLAG_SITELINKS = 512;

	public const FLAG_RELATED = 1024;

	/**
	 * @param array<string, mixed> $extra dodatkowe pola prezentacji (pre/extended snippet, ocena, cena, linki, data publikacji)
	 */
	public function __construct(
		public readonly int $type,
		public readonly int $rankGroup,
		public readonly int $rankAbsolute,
		public readonly ?int $page,
		public readonly string $host,
		public readonly string $url,
		public readonly ?string $title,
		public readonly ?string $description,
		public readonly ?string $breadcrumb,
		public readonly ?string $websiteName,
		public readonly int $flags = 0,
		public readonly array $extra = [],
	) {
	}

	public function isOrganic(): bool
	{
		return $this->type === self::TYPE_ORGANIC;
	}

	/** Treść prezentacji wyniku (deduplikowana w słowniku `serp_snippets`) — null, gdy wynik nie ma żadnej treści. */
	public function snippetHash(): ?string
	{
		if ($this->title === null && $this->description === null && $this->breadcrumb === null && $this->websiteName === null && $this->extra === []) {
			return null;
		}

		return md5((string) json_encode([$this->title, $this->description, $this->breadcrumb, $this->websiteName, $this->extra], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	public function urlHash(): string
	{
		return md5($this->url);
	}

	public static function typeLabel(int $type): string
	{
		return $type === self::TYPE_FEATURED_SNIPPET ? 'Wyróżniony fragment' : 'Organiczny';
	}
}
