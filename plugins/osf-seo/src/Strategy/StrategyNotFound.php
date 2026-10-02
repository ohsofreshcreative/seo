<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use RuntimeException;

/**
 * Kandydat Strategii nie istnieje albo należy do innego projektu — nieodróżnialne (404).
 */
final class StrategyNotFound extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Strategy candidate not found.');
	}
}
