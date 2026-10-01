<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use RuntimeException;

/**
 * Import przerwany bez zapisu, bo projekt zmienił się w trakcie (inna property, reset danych,
 * usunięty projekt). Ponowienie nie ma sensu — zadanie jest anulowane.
 */
final class ImportAborted extends RuntimeException
{
	public const PROPERTY_CHANGED = 'property_changed';

	public const PROJECT_MISSING = 'project_missing';

	public function __construct(private readonly string $reason)
	{
		parent::__construct('GSC import aborted: ' . $reason . '.');
	}

	public function reason(): string
	{
		return $this->reason;
	}
}
