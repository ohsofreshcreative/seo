<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use Closure;
use OsfSeo\Http\HttpResponse;
use OsfSeo\Http\HttpTransport;
use OsfSeo\Http\TransportException;

/** Transport HTTP do testów jednostkowych: zapisuje żądania, odpowiedzi z kolejki albo handlera. */
final class FakeHttpTransport implements HttpTransport
{
	/** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
	public array $requests = [];

	/** @var list<HttpResponse|TransportException> */
	private array $queue = [];

	/**
	 * @param (Closure(string, string, array<string, string>, ?string): HttpResponse)|null $handler
	 */
	public function __construct(private readonly ?Closure $handler = null)
	{
	}

	public function push(HttpResponse|TransportException $response): self
	{
		$this->queue[] = $response;

		return $this;
	}

	/**
	 * @param array<string, mixed> $json
	 */
	public function pushJson(int $status, array $json): self
	{
		return $this->push(new HttpResponse($status, (string) json_encode($json)));
	}

	public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
	{
		$this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

		if ($this->handler !== null) {
			return ($this->handler)($method, $url, $headers, $body);
		}

		$next = array_shift($this->queue) ?? throw new \LogicException('Unexpected HTTP request to ' . $url);

		if ($next instanceof TransportException) {
			throw $next;
		}

		return $next;
	}

	/**
	 * @return array<string, string>
	 */
	public function formBody(int $index): array
	{
		parse_str((string) $this->requests[$index]['body'], $fields);

		return $fields;
	}
}
