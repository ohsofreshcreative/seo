<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use RuntimeException;

/**
 * Szansy nie ma w autoryzowanym projekcie — także gdy istnieje w innym projekcie (nieodróżnialne → 404).
 */
final class OpportunityNotFound extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Opportunity not found.');
	}
}
