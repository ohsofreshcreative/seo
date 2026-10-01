<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

use RuntimeException;

/**
 * Projekt nie istnieje ALBO użytkownik nie ma do niego dostępu — celowo nierozróżnialne
 * (ta sama klasa, komunikat i kod), żeby nie ujawniać istnienia cudzych projektów. W HTTP: 404.
 */
final class ProjectNotFound extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Project not found.', 404);
	}
}
