<?php

declare(strict_types=1);

namespace OsfSeo\Support;

use InvalidArgumentException;

/**
 * Błędy walidacji danych wejściowych: pole => komunikat (po polsku, do pokazania w UI).
 */
final class ValidationException extends InvalidArgumentException
{
	/**
	 * @param array<string, string> $errors
	 */
	public function __construct(private readonly array $errors)
	{
		parent::__construct('Validation failed: ' . implode(', ', array_keys($errors)));
	}

	/**
	 * @return array<string, string>
	 */
	public function errors(): array
	{
		return $this->errors;
	}
}
