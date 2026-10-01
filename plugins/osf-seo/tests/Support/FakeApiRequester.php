<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use Closure;
use OsfSeo\Google\ApiRequester;
use OsfSeo\Google\GoogleConnection;
use OsfSeo\Http\HttpResponse;
use Throwable;

/**
 * Autoryzowany klient Google do testów jednostkowych: odpowiedzi z kolejki albo z handlera,
 * zapis żądań (metoda, URL, JSON).
 */
final class FakeApiRequester implements ApiRequester
{
	/** @var list<array{method: string, url: string, json: array<string, mixed>|null}> */
	public array $requests = [];

	/** @var list<HttpResponse|Throwable> */
	private array $queue = [];

	/**
	 * @param (Closure(string, string, array<string, mixed>|null): (HttpResponse|Throwable))|null $handler
	 */
	public function __construct(private readonly ?Closure $handler = null)
	{
	}

	/**
	 * @param array<string, mixed> $json
	 * @param array<string, string> $headers
	 */
	public function json(int $status, array $json, array $headers = []): self
	{
		$this->queue[] = new HttpResponse($status, (string) json_encode($json), $headers);

		return $this;
	}

	public function push(HttpResponse|Throwable $response): self
	{
		$this->queue[] = $response;

		return $this;
	}

	public function request(GoogleConnection $connection, string $method, string $url, ?array $json = null): HttpResponse
	{
		$this->requests[] = ['method' => $method, 'url' => $url, 'json' => $json];

		$next = $this->handler !== null
			? ($this->handler)($method, $url, $json)
			: (array_shift($this->queue) ?? throw new \LogicException('Unexpected API request to ' . $url));

		if ($next instanceof Throwable) {
			throw $next;
		}

		return $next;
	}

	public static function connection(int $id = 1): GoogleConnection
	{
		return GoogleConnection::fromRow([
			'id' => $id,
			'owner_user_id' => 1,
			'google_sub' => '1000' . $id,
			'email' => 'owner@example.test',
			'status' => 'active',
			'scopes' => 'https://www.googleapis.com/auth/webmasters.readonly',
			'last_error' => null,
			'last_refreshed_at' => null,
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		]);
	}
}
