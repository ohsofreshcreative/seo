<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Contract;

/**
 * Wynik walidacji odpowiedzi modelu: znormalizowany wynik (tylko gdy poprawny) i lista błędów (ścieżka + kod, bez treści odpowiedzi).
 */
final class ValidationResult
{
	/**
	 * @param array<string, mixed>|null $result
	 * @param list<array{path: string, code: string}> $errors
	 */
	public function __construct(
		public readonly ?array $result,
		public readonly array $errors,
	) {
	}

	public function valid(): bool
	{
		return $this->errors === [] && $this->result !== null;
	}
}
