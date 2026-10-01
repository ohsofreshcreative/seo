<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use RuntimeException;

/**
 * Błąd dostawcy danych rynkowych. Komunikat nigdy nie zawiera danych logowania, nagłówków ani treści żądania —
 * wyłącznie kategorię, kod statusu dostawcy i jego krótki komunikat.
 */
final class ProviderException extends RuntimeException
{
	public function __construct(
		private readonly ProviderErrorCategory $category,
		string $message,
		private readonly ?int $statusCode = null,
		private readonly ?int $retryAfter = null,
	) {
		parent::__construct($message);
	}

	public function category(): ProviderErrorCategory
	{
		return $this->category;
	}

	/** Kod statusu dostawcy (np. DataForSEO 40210) albo HTTP, gdy brak treści. */
	public function statusCode(): ?int
	{
		return $this->statusCode;
	}

	/** Sekundy z nagłówka Retry-After (jeśli był). */
	public function retryAfter(): ?int
	{
		return $this->retryAfter;
	}

	public function isRetryable(): bool
	{
		return $this->category->isRetryable();
	}
}
