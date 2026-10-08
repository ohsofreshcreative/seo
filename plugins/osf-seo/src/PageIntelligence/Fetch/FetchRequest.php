<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Fetch;

/**
 * Żądanie pobrania jednego adresu: dozwolony zakres hostów (także dla przekierowań), limity, akceptowane typy treści i opcjonalne
 * nagłówki warunkowe (ETag / Last-Modified ostatniego snapshotu).
 */
final class FetchRequest
{
	public const ACCEPT_HTML = ['text/html', 'application/xhtml+xml'];

	public const ACCEPT_ROBOTS = ['text/plain'];

	/**
	 * @param list<string> $families dozwolone rodziny domen (host i każde przekierowanie)
	 * @param list<string> $accept typy treści (bez parametrów)
	 */
	public function __construct(
		public readonly string $url,
		public readonly array $families,
		public readonly int $maxBytes,
		public readonly int $timeout,
		public readonly int $connectTimeout,
		public readonly int $maxRedirects,
		public readonly string $userAgent,
		public readonly array $accept = self::ACCEPT_HTML,
		public readonly ?string $etag = null,
		public readonly ?string $lastModified = null,
	) {
	}
}
