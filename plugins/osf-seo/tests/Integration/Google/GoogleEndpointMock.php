<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Google;

use Closure;
use WP_Error;

/**
 * Atrapa endpointów Google na poziomie WP HTTP API (filtr `pre_http_request`) — kod produkcyjny
 * (WpHttpTransport → wp_remote_request) działa bez zmian, a żaden request nie wychodzi do sieci.
 */
final class GoogleEndpointMock
{
	/** @var list<array{method: string, url: string, headers: array<string, string>, body: string, form: array<string, mixed>}> */
	public array $requests = [];

	/** @var array<string, list<array{status: int, json: array<string, mixed>}|WP_Error|Closure>> */
	private array $routes = [];

	private ?Closure $filter = null;

	public function register(): void
	{
		$this->filter = fn (mixed $pre, array $args, string $url): array|WP_Error => $this->handle($args, $url);
		add_filter('pre_http_request', $this->filter, 10, 3);
	}

	public function unregister(): void
	{
		if ($this->filter !== null) {
			remove_filter('pre_http_request', $this->filter, 10);
			$this->filter = null;
		}
	}

	/**
	 * Kolejna odpowiedź dla adresu: [status, json], WP_Error (błąd sieci) albo Closure(array $request): array|WP_Error.
	 *
	 * @param array{status: int, json: array<string, mixed>}|WP_Error|Closure $response
	 */
	public function on(string $url, array|WP_Error|Closure $response): self
	{
		$this->routes[$url][] = $response;

		return $this;
	}

	/**
	 * @param array<string, mixed> $json
	 */
	public function json(string $url, int $status, array $json): self
	{
		return $this->on($url, ['status' => $status, 'json' => $json]);
	}

	/**
	 * @return list<array{method: string, url: string, headers: array<string, string>, body: string, form: array<string, mixed>}>
	 */
	public function requestsTo(string $url): array
	{
		return array_values(array_filter($this->requests, static fn (array $request): bool => $request['url'] === $url));
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private function handle(array $args, string $url): array|WP_Error
	{
		parse_str(is_string($args['body'] ?? null) ? $args['body'] : '', $form);
		$request = [
			'method' => (string) ($args['method'] ?? 'GET'),
			'url' => $url,
			'headers' => (array) ($args['headers'] ?? []),
			'body' => is_string($args['body'] ?? null) ? $args['body'] : '',
			'form' => $form,
		];
		$this->requests[] = $request;

		$response = isset($this->routes[$url]) && $this->routes[$url] !== [] ? array_shift($this->routes[$url]) : null;

		if ($response === null) {
			return new WP_Error('http_request_failed', 'Unexpected request in test.');
		}

		if ($response instanceof Closure) {
			$response = $response($request);
		}

		if ($response instanceof WP_Error) {
			return $response;
		}

		return [
			'headers' => ['content-type' => 'application/json'],
			'body' => (string) json_encode($response['json']),
			'response' => ['code' => $response['status'], 'message' => ''],
			'cookies' => [],
			'filename' => null,
		];
	}
}
