<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Fetch;

/**
 * Wynik pobrania: `ok` (2xx z dozwolonym typem treści), `not_modified` (304 na żądanie warunkowe), `http_error` (4xx/5xx/3xx bez Location),
 * `refused` (polityka bezpieczeństwa — żadne połączenie albo przerwane przekierowanie), `failed` (sieć, DNS, TLS, timeout, rozmiar, typ).
 * Bez nagłówków żądania i ciasteczek; `primaryIp` tylko do weryfikacji przypięcia (nie jest zapisywany).
 */
final class FetchResult
{
	public const OK = 'ok';

	public const NOT_MODIFIED = 'not_modified';

	public const HTTP_ERROR = 'http_error';

	public const REFUSED = 'refused';

	public const FAILED = 'failed';

	/**
	 * @param array<string, string> $headers wybrane nagłówki odpowiedzi końcowej (małe litery)
	 * @param list<array{url: string, status: int}> $redirects łańcuch przekierowań
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly ?string $errorCode,
		public readonly ?int $httpStatus,
		public readonly string $requestedUrl,
		public readonly ?string $finalUrl,
		public readonly ?string $contentType,
		public readonly ?string $charset,
		public readonly array $headers,
		public readonly ?string $body,
		public readonly int $bytes,
		public readonly int $durationMs,
		public readonly array $redirects,
		public readonly ?string $primaryIp = null,
		public readonly ?int $retryAfter = null,
		public readonly bool $network = true,
	) {
	}

	public function ok(): bool
	{
		return $this->outcome === self::OK;
	}

	/**
	 * Bezpieczna diagnostyka (bez treści i nagłówków poza wybranymi; bez adresu IP).
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics(): array
	{
		return [
			'outcome' => $this->outcome,
			'error' => $this->errorCode,
			'http_status' => $this->httpStatus,
			'content_type' => $this->contentType,
			'bytes' => $this->bytes,
			'duration_ms' => $this->durationMs,
			'redirects' => $this->redirects,
			'final_url' => $this->finalUrl,
			'retry_after' => $this->retryAfter,
		];
	}

	public function __debugInfo(): array
	{
		return ['outcome' => $this->outcome, 'error' => $this->errorCode, 'status' => $this->httpStatus, 'body' => $this->body === null ? null : '[' . strlen($this->body) . ' bytes]'];
	}
}
