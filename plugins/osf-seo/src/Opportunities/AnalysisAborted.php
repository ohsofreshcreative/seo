<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use RuntimeException;

/**
 * Wynik analizy nie może zostać zapisany — property projektu zmieniła się w trakcie (reset danych)
 * albo projekt zniknął. Zapis szans jest wtedy wycofywany (nie mieszamy danych properties).
 */
final class AnalysisAborted extends RuntimeException
{
	public function __construct(public readonly string $reason)
	{
		parent::__construct('Opportunity analysis aborted: ' . $reason . '.');
	}
}
