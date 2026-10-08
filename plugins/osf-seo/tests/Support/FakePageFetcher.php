<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use Closure;
use OsfSeo\PageIntelligence\Fetch\FetchRequest;
use OsfSeo\PageIntelligence\Fetch\FetchResult;
use OsfSeo\PageIntelligence\Fetch\PageFetcher;

/**
 * Atrapa transportu stron w testach usługi (logika cache, snapshotów, limitów, uprawnień): odpowiedzi według adresu, zapis żądań.
 * Bezpieczeństwo połączenia (przypięcie IP, przekierowania, TLS) testuje `CurlPageFetcherTest` na prawdziwym ext-curl.
 * Adres bez odpowiedzi → `connect_failed`, a robots.txt bez odpowiedzi → 404 (wszystko dozwolone).
 */
final class FakePageFetcher implements PageFetcher
{
	/** @var list<FetchRequest> */
	public array $requests = [];

	/** @var array<string, FetchResult|Closure(FetchRequest): FetchResult> */
	private array $responses = [];

	public function fetch(FetchRequest $request): FetchResult
	{
		$this->requests[] = $request;
		$response = $this->responses[$request->url] ?? null;

		if ($response instanceof Closure) {
			return $response($request);
		}

		if ($response !== null) {
			return $response;
		}

		return str_ends_with($request->url, '/robots.txt')
			? self::result($request->url, FetchResult::HTTP_ERROR, 'http_404', 404)
			: self::result($request->url, FetchResult::FAILED, 'connect_failed', null, network: true);
	}

	/**
	 * @param array<string, string> $headers
	 */
	public function html(string $url, string $html, array $headers = [], int $status = 200): void
	{
		$this->responses[$url] = self::result($url, FetchResult::OK, null, $status, $html, ['content-type' => 'text/html; charset=utf-8'] + $headers);
	}

	public function robots(string $origin, string $content): void
	{
		$url = rtrim($origin, '/') . '/robots.txt';
		$this->responses[$url] = self::result($url, FetchResult::OK, null, 200, $content, ['content-type' => 'text/plain'], 'text/plain');
	}

	public function status(string $url, int $status, ?int $retryAfter = null): void
	{
		$this->responses[$url] = self::result($url, FetchResult::HTTP_ERROR, 'http_' . $status, $status, retryAfter: $retryAfter);
	}

	public function failure(string $url, string $code, string $outcome = FetchResult::FAILED): void
	{
		$this->responses[$url] = self::result($url, $outcome, $code, null, network: $outcome !== FetchResult::REFUSED);
	}

	/**
	 * @param Closure(FetchRequest): FetchResult $responder
	 */
	public function respond(string $url, Closure $responder): void
	{
		$this->responses[$url] = $responder;
	}

	/**
	 * Żądania stron (bez robots.txt).
	 *
	 * @return list<FetchRequest>
	 */
	public function pageRequests(): array
	{
		return array_values(array_filter($this->requests, static fn (FetchRequest $request): bool => ! str_ends_with($request->url, '/robots.txt')));
	}

	/**
	 * @param array<string, string> $headers
	 */
	public static function result(string $url, string $outcome, ?string $error, ?int $status, ?string $body = null, array $headers = [], string $type = 'text/html', ?int $retryAfter = null, bool $network = true): FetchResult
	{
		return new FetchResult(
			$outcome,
			$error,
			$status,
			$url,
			$outcome === FetchResult::OK || $outcome === FetchResult::NOT_MODIFIED ? $url : null,
			$outcome === FetchResult::OK ? $type : null,
			$outcome === FetchResult::OK ? 'utf-8' : null,
			$headers,
			$body,
			strlen((string) $body),
			15,
			[],
			null,
			$retryAfter,
			$network,
		);
	}
}
