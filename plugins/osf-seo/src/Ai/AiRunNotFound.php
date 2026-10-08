<?php

declare(strict_types=1);

namespace OsfSeo\Ai;

use RuntimeException;

/** Uruchomienie AI nie istnieje w tym projekcie (także: należy do innego projektu — bez rozróżnienia). */
final class AiRunNotFound extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('AI run not found.');
	}
}
