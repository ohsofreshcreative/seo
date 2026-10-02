<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Market\ProviderErrorCategory;

/**
 * Odpowiedź dostawcy dla jednego zadania w zleceniu: przyjęte (identyfikator zadania, koszt) albo odrzucone (błąd zadania).
 */
final class SerpSubmission
{
	public function __construct(
		public readonly string $tag,
		public readonly ?string $taskId,
		public readonly ?float $cost,
		public readonly ?ProviderErrorCategory $error = null,
		public readonly ?int $statusCode = null,
		public readonly ?string $message = null,
	) {
	}

	public function accepted(): bool
	{
		return $this->taskId !== null && $this->error === null;
	}
}
