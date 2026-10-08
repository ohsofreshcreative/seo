<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

use RuntimeException;

/** Odmowa całego zlecenia Page Intelligence przed jakimkolwiek żądaniem (limit adresów, wyłączony rodzaj, brak transportu). */
final class PageRefused extends RuntimeException
{
	public function __construct(private readonly string $reason)
	{
		parent::__construct('Page fetch refused: ' . $reason . '.');
	}

	public function reason(): string
	{
		return $this->reason;
	}
}
