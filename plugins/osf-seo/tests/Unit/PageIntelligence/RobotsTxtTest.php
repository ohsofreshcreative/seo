<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\PageIntelligence;

use OsfSeo\PageIntelligence\Fetch\FetchRequest;
use OsfSeo\PageIntelligence\Fetch\FetchResult;
use OsfSeo\PageIntelligence\Fetch\PageFetcher;
use OsfSeo\PageIntelligence\Robots\RobotsCache;
use OsfSeo\PageIntelligence\Robots\RobotsPolicy;
use OsfSeo\PageIntelligence\Robots\RobotsTxt;
use PHPUnit\Framework\TestCase;

/**
 * robots.txt (RFC 9309): grupa naszego tokenu przed `*`, najdłuższe dopasowanie, `allow` przy remisie, wzorce `*` i `$`, crawl-delay;
 * brak pliku (4xx) = dozwolone, nieosiągalny (429, 5xx, sieć) = zabronione; reguły w pamięci (jedno żądanie na origin).
 */
final class RobotsTxtTest extends TestCase
{
	public function test_rules_and_groups(): void
	{
		$content = "User-agent: *\nDisallow: /\n\nUser-agent: Whack-a-mole\nUser-agent: otherbot\nDisallow: /panel/\nAllow: /panel/publiczny\nDisallow: /*.pdf$\nCrawl-delay: 5\n# komentarz\nSitemap: https://example.pl/sitemap.xml";
		$robots = RobotsTxt::parse($content, 'whack-a-mole');

		self::assertSame('specific', $robots->group);
		self::assertTrue($robots->allows('/oferta/'));
		self::assertFalse($robots->allows('/panel/ustawienia'));
		self::assertTrue($robots->allows('/panel/publiczny/strona'));
		self::assertFalse($robots->allows('/pliki/cennik.pdf'));
		self::assertTrue($robots->allows('/pliki/cennik.pdf?x=1'));
		self::assertSame(5.0, $robots->crawlDelay);

		$star = RobotsTxt::parse("User-agent: *\nDisallow: /tmp\nAllow: /tmp\n", 'whack-a-mole');
		self::assertSame('*', $star->group);
		self::assertTrue($star->allows('/tmp/plik'), 'Remis — allow.');
		self::assertFalse(RobotsTxt::parse("User-agent: *\nDisallow: /\n", 'whack-a-mole')->allows('/'));
		self::assertTrue(RobotsTxt::parse("User-agent: googlebot\nDisallow: /\n", 'whack-a-mole')->allows('/'), 'Brak pasującej grupy — dozwolone.');
		self::assertTrue(RobotsTxt::parse("User-agent: *\nDisallow:\n", 'whack-a-mole')->allows('/'), 'Pusty disallow nic nie blokuje.');
		self::assertTrue(RobotsTxt::parse("User-agent: *\nDisallow: /%7Ejan/\n", 'whack-a-mole')->allows('/~janek'));
		self::assertFalse(RobotsTxt::parse("User-agent: *\nDisallow: /%7Ejan/\n", 'whack-a-mole')->allows('/~jan/'));
	}

	/** Faza E (audyt): błąd dopasowania wrogiego wzorca (limit backtrackingu) nie może otworzyć dostępu — brak zgody na pobranie. */
	public function test_pattern_errors_fail_closed(): void
	{
		$robots = RobotsTxt::parse("User-agent: *\nDisallow: /*a*a*a*a*a*a*a*a*a*a*a*a*c\n", 'whack-a-mole');
		$limit = ini_get('pcre.backtrack_limit');
		$jit = ini_get('pcre.jit');
		ini_set('pcre.jit', '0');
		ini_set('pcre.backtrack_limit', '10000');

		try {
			self::assertFalse($robots->allows('/c' . str_repeat('a', 2000)));
		} finally {
			ini_set('pcre.backtrack_limit', (string) $limit);
			ini_set('pcre.jit', (string) $jit);
		}

		self::assertTrue($robots->allows('/oferta/'), 'Ścieżka bez dopasowania pozostaje dozwolona.');
		self::assertFalse(RobotsTxt::parse("User-agent: *\nDisallow: /**/tajne\n", 'whack-a-mole')->allows('/x/tajne'), 'Kolejne gwiazdki = jedna.');
	}

	public function test_policy_fetches_robots_once_and_treats_unreachable_as_disallowed(): void
	{
		$cache = new class implements RobotsCache {
			/** @var array<string, array<string, mixed>> */
			public array $items = [];

			public function get(string $key): ?array
			{
				return $this->items[$key] ?? null;
			}

			public function set(string $key, array $value, int $ttl): void
			{
				$this->items[$key] = $value;
			}
		};
		$responses = [
			'https://a.example.pl/robots.txt' => new FetchResult(FetchResult::OK, null, 200, '', null, 'text/plain', null, [], "User-agent: *\nDisallow: /prywatne/\n", 40, 5, []),
			'https://b.example.pl/robots.txt' => new FetchResult(FetchResult::HTTP_ERROR, 'http_404', 404, '', null, null, null, [], null, 0, 5, []),
			'https://c.example.pl/robots.txt' => new FetchResult(FetchResult::HTTP_ERROR, 'http_503', 503, '', null, null, null, [], null, 0, 5, []),
			'https://d.example.pl/robots.txt' => new FetchResult(FetchResult::FAILED, 'timeout', null, '', null, null, null, [], null, 0, 5, []),
			'https://e.example.pl/robots.txt' => new FetchResult(FetchResult::HTTP_ERROR, 'http_429', 429, '', null, null, null, [], null, 0, 5, []),
			'https://f.example.pl/robots.txt' => new FetchResult(FetchResult::REFUSED, 'ip_private', null, '', null, null, null, [], null, 0, 0, [], network: false),
		];
		$fetcher = new class ($responses) implements PageFetcher {
			/** @var list<string> */
			public array $urls = [];

			/** @param array<string, FetchResult> $responses */
			public function __construct(private readonly array $responses)
			{
			}

			public function fetch(FetchRequest $request): FetchResult
			{
				$this->urls[] = $request->url;

				return $this->responses[$request->url];
			}
		};
		$policy = new RobotsPolicy($fetcher, $cache);
		$request = static fn (string $url): FetchRequest => new FetchRequest($url, ['example.pl'], 1000, 5, 2, 3, 'Whack-a-mole/test');

		self::assertTrue($policy->check($request('https://a.example.pl/oferta/'))['allowed']);
		self::assertSame(['allowed' => false, 'status' => 'disallowed', 'crawl_delay' => null, 'network' => false], $policy->check($request('https://a.example.pl/prywatne/x')));
		self::assertSame(['https://a.example.pl/robots.txt'], $fetcher->urls, 'robots.txt pobrany raz dla origin.');
		self::assertSame(['allowed' => true, 'status' => 'missing'], array_intersect_key($policy->check($request('https://b.example.pl/')), ['allowed' => 1, 'status' => 1]));

		foreach (['c', 'd', 'e'] as $host) {
			self::assertSame(['allowed' => false, 'status' => 'unreachable'], array_intersect_key($policy->check($request('https://' . $host . '.example.pl/')), ['allowed' => 1, 'status' => 1]), $host);
		}

		// Polityka odrzuciła już robots.txt (adres wewnętrzny) — strona też nie zostanie pobrana, z powodem polityki.
		self::assertSame(['allowed' => false, 'status' => 'refused', 'reason' => 'ip_private'], array_intersect_key($policy->check($request('https://f.example.pl/')), ['allowed' => 1, 'status' => 1, 'reason' => 1]));
	}
}
