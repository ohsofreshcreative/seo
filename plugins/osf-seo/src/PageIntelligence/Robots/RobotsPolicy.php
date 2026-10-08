<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Robots;

use OsfSeo\PageIntelligence\Fetch\FetchRequest;
use OsfSeo\PageIntelligence\Fetch\FetchResult;
use OsfSeo\PageIntelligence\Fetch\PageFetcher;

/**
 * Respektowanie robots.txt (RFC 9309) przed pobraniem strony: plik pobierany tym samym bezpiecznym transportem (te same limity i ochrona),
 * reguły w pamięci per origin (24 h; nieosiągalny — 1 h). Brak pliku (4xx poza 429) = wszystko dozwolone; nieosiągalny (429, 5xx, sieć,
 * timeout, TLS) = wszystko zabronione — jak wymaga RFC. Bez obchodzenia blokad.
 */
final class RobotsPolicy
{
	public const TOKEN = 'whack-a-mole';

	public const TTL = 86400;

	public const UNREACHABLE_TTL = 3600;

	public function __construct(
		private readonly PageFetcher $fetcher,
		private readonly RobotsCache $cache,
	) {
	}

	/**
	 * @param FetchRequest $page żądanie strony (zakres, limity, User-Agent)
	 * Status: `allowed` / `disallowed` (reguły), `missing` (4xx — wszystko dozwolone), `unreachable` (429, 5xx, sieć — nic nie pobieramy),
	 * `refused` (polityka bezpieczeństwa odrzuciła już żądanie robots.txt — `reason` to jej powód, np. adres wewnętrzny), `invalid_url`.
	 *
	 * @return array{allowed: bool, status: string, crawl_delay: ?float, network: bool, reason?: string}
	 */
	public function check(FetchRequest $page): array
	{
		$parts = parse_url($page->url);

		if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
			return ['allowed' => false, 'status' => 'invalid_url', 'crawl_delay' => null, 'network' => false];
		}

		$origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
		$path = (string) ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
		$network = false;
		$entry = $this->cache->get($origin);

		if ($entry === null) {
			$network = true;
			$entry = $this->load($origin, $page);
			$this->cache->set($origin, $entry, in_array($entry['status'], ['unreachable', 'refused'], true) ? self::UNREACHABLE_TTL : self::TTL);
		}

		$status = (string) ($entry['status'] ?? 'unreachable');

		if ($status === 'refused') {
			return ['allowed' => false, 'status' => $status, 'crawl_delay' => null, 'network' => $network, 'reason' => (string) ($entry['reason'] ?? 'refused')];
		}

		if ($status !== 'rules') {
			return ['allowed' => $status === 'missing', 'status' => $status, 'crawl_delay' => null, 'network' => $network];
		}

		$robots = RobotsTxt::parse((string) ($entry['content'] ?? ''), self::TOKEN);

		return ['allowed' => $robots->allows($path), 'status' => $robots->allows($path) ? 'allowed' : 'disallowed', 'crawl_delay' => $robots->crawlDelay, 'network' => $network];
	}

	/**
	 * @return array{status: string, content?: string, reason?: string}
	 */
	private function load(string $origin, FetchRequest $page): array
	{
		$result = $this->fetcher->fetch(new FetchRequest(
			$origin . '/robots.txt',
			$page->families,
			RobotsTxt::MAX_BYTES,
			$page->timeout,
			$page->connectTimeout,
			$page->maxRedirects,
			$page->userAgent,
			['text/plain', 'text/html'],
		));

		if ($result->outcome === FetchResult::OK) {
			return ['status' => 'rules', 'content' => (string) $result->body];
		}

		if ($result->outcome === FetchResult::REFUSED) {
			return ['status' => 'refused', 'reason' => (string) $result->errorCode];
		}

		if ($result->outcome === FetchResult::HTTP_ERROR && $result->httpStatus !== null && $result->httpStatus >= 400 && $result->httpStatus < 500 && $result->httpStatus !== 429) {
			return ['status' => 'missing'];
		}

		return ['status' => 'unreachable'];
	}
}
