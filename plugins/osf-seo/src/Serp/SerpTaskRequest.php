<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Jedno zadanie SERP do wysłania: fraza w kontekście; `tag` = ULID pomiaru (identyfikuje wynik także wtedy,
 * gdy odpowiedź na zlecenie nie dotarła).
 */
final class SerpTaskRequest
{
	public function __construct(
		public readonly string $tag,
		public readonly string $keyword,
		public readonly SerpContext $context,
	) {
	}
}
