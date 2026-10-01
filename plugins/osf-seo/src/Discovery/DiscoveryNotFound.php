<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use RuntimeException;

/**
 * Przebiegu lub kandydata nie ma w autoryzowanym projekcie — także gdy istnieje w innym projekcie (nieodróżnialne → 404).
 */
final class DiscoveryNotFound extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Discovery record not found.');
	}
}
