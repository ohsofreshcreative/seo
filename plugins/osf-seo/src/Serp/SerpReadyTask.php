<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Zadanie gotowe do odbioru (lista dostawcy, bezpłatnie): identyfikator i `tag`.
 */
final class SerpReadyTask
{
	public function __construct(
		public readonly string $id,
		public readonly ?string $tag,
	) {
	}
}
