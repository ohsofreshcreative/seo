<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use RuntimeException;

/**
 * Frazy, pomiaru, przebiegu lub konkurenta nie ma w autoryzowanym projekcie — także gdy istnieje w innym projekcie
 * (nieodróżnialne → 404).
 */
final class SerpNotFound extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('SERP record not found.');
	}
}
