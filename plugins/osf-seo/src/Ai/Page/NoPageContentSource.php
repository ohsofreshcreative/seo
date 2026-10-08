<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Page;

use OsfSeo\Auth\ProjectContext;

/**
 * Faza A: treść stron nie jest pobierana — kontekst AI zawsze oznacza ją jako niedostępną (`not_fetched`).
 */
final class NoPageContentSource implements PageContentSource
{
	public function snapshot(ProjectContext $context, string $url): ?PageSnapshot
	{
		return null;
	}
}
