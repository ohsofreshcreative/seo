<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

use RuntimeException;

/**
 * Brak uprawnienia do operacji (np. edycji), gdy sam zasób jest dla użytkownika widoczny
 * albo operacja nie dotyczy konkretnego projektu. W HTTP: 403.
 */
final class AccessDenied extends RuntimeException
{
	public function __construct(string $message = 'Access denied.')
	{
		parent::__construct($message, 403);
	}
}
