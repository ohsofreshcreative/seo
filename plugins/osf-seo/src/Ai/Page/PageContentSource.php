<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Page;

use OsfSeo\Auth\ProjectContext;

/**
 * Źródło zapisanych migawek stron projektu dla kontekstu AI (punkt rozszerzenia fazy B). Kontrakt: wyłącznie odczyt zapisanego stanu
 * w obrębie projektu — nigdy pobieranie strony w trakcie budowania kontekstu ani w żądaniu WWW.
 */
interface PageContentSource
{
	public function snapshot(ProjectContext $context, string $url): ?PageSnapshot;
}
