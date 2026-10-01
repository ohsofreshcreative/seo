<?php

declare(strict_types=1);

namespace OsfSeo\Http;

final class HttpResponse
{
	/**
	 * @param array<string, string> $headers nagłówki (nazwy małymi literami)
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly array $headers = [],
	) {
	}

	public function isSuccess(): bool
	{
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function json(): ?array
	{
		$data = json_decode($this->body, true);

		return is_array($data) ? $data : null;
	}

	/** Treść odpowiedzi może zawierać tokeny — nie pokazujemy jej w zrzutach obiektu. */
	public function __debugInfo(): array
	{
		return ['status' => $this->status, 'body' => '[' . strlen($this->body) . ' bytes]'];
	}
}
