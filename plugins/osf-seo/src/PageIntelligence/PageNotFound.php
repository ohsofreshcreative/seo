<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

use RuntimeException;

/** Strona albo snapshot nie istnieje w tym projekcie (także: należy do innego projektu — bez rozróżnienia). */
final class PageNotFound extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Page not found.');
	}
}
