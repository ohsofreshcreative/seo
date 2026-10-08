<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\PageIntelligence;

use OsfSeo\PageIntelligence\Fetch\CurlPageFetcher;
use OsfSeo\PageIntelligence\Fetch\FetchRequest;
use OsfSeo\PageIntelligence\Fetch\FetchResult;
use OsfSeo\PageIntelligence\Fetch\UrlSafetyPolicy;
use OsfSeo\Tests\Support\FixtureNetwork;
use OsfSeo\Tests\Support\FixtureServers;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Transport Page Intelligence na PRAWDZIWYM ext-curl i lokalnych serwerach (bez internetu, bez atrapy HTTP): przypięcie zweryfikowanego
 * adresu IP (DNS rebinding), przekierowania na adresy wewnętrzne i poza zakres, limit łańcucha, TLS z weryfikacją nazwy hosta przy
 * połączeniu z przypiętym IP, timeout, limit rozmiaru (także po dekompresji), typ treści, kody HTTP, brak proxy i ciasteczek.
 *
 * 127.0.0.1 gra rolę adresu publicznego (polityka testowa), 127.0.0.2 — sieci wewnętrznej (zawsze zablokowany). Oba serwery słuchają
 * na TYM SAMYM porcie: gdyby transport rozwiązał nazwę ponownie i połączył się z 127.0.0.2, odpowiedziałby serwer „internal”.
 */
#[Group('transport')]
final class CurlPageFetcherTest extends TestCase
{
	private static int $port;

	private static int $tlsPort;

	private static string $ca;

	private FixtureNetwork $network;

	public static function setUpBeforeClass(): void
	{
		if (! CurlPageFetcher::available() || ! FixtureServers::supported() || ! extension_loaded('openssl')) {
			self::markTestSkipped('ext-curl z CURLOPT_RESOLVE, proc_open i ext-openssl są wymagane do testów transportu.');
		}

		self::$port = FixtureServers::http('127.0.0.1', 'public');
		FixtureServers::http('127.0.0.2', 'internal', [], self::$port);
		[self::$tlsPort, self::$ca] = FixtureServers::tls(self::$port);
	}

	public static function tearDownAfterClass(): void
	{
		FixtureServers::stopAll();
	}

	protected function setUp(): void
	{
		$this->network = new FixtureNetwork([
			'www.fixture.example' => ['127.0.0.1'],
			'secure.fixture.example' => ['127.0.0.1'],
			'other.fixture.example' => ['127.0.0.1'],
			'internal.fixture.example' => ['127.0.0.2'],
			'mixed.fixture.example' => ['127.0.0.1', '10.0.0.5'],
			'metadata.fixture.example' => ['169.254.169.254'],
			'v6.fixture.example' => ['fd00::1'],
			// DNS rebinding: pierwsza odpowiedź publiczna, każda kolejna — adres wewnętrzny.
			'rebind.fixture.example' => static fn (int $lookup): array => $lookup === 1 ? ['127.0.0.1'] : ['127.0.0.2'],
		], [self::$port, self::$tlsPort]);
	}

	public function test_1_valid_https_connects_to_pinned_ip_and_verifies_certificate_for_original_hostname(): void
	{
		$result = $this->fetcher(self::$ca)->fetch($this->request('https://secure.fixture.example:' . self::$tlsPort . '/'));

		self::assertSame(FetchResult::OK, $result->outcome, (string) $result->errorCode);
		self::assertSame('127.0.0.1', $result->primaryIp);
		self::assertSame('text/html', $result->contentType);
		self::assertStringContainsString('TLS OK', (string) $result->body);
		self::assertSame(1, $this->network->lookups['secure.fixture.example']);
	}

	public function test_10_invalid_or_mismatched_certificate_is_rejected_without_disabling_tls(): void
	{
		// Certyfikat testowego CA — spoza domyślnego magazynu zaufania.
		$untrusted = $this->fetcher()->fetch($this->request('https://secure.fixture.example:' . self::$tlsPort . '/'));
		self::assertSame([FetchResult::FAILED, 'tls_error'], [$untrusted->outcome, $untrusted->errorCode]);
		self::assertNull($untrusted->body);

		// Zaufane CA, ale certyfikat wystawiony dla innej nazwy niż host z adresu (połączenie z tym samym przypiętym IP).
		$mismatch = $this->fetcher(self::$ca)->fetch($this->request('https://other.fixture.example:' . self::$tlsPort . '/'));
		self::assertSame([FetchResult::FAILED, 'tls_error'], [$mismatch->outcome, $mismatch->errorCode]);
	}

	public function test_7_dns_rebinding_cannot_redirect_the_connection_to_an_internal_address(): void
	{
		$result = $this->fetcher()->fetch($this->request('http://rebind.fixture.example:' . self::$port . '/identity'));

		self::assertSame(FetchResult::OK, $result->outcome, (string) $result->errorCode);
		self::assertStringContainsString('Serwer public', (string) $result->body, 'Odpowiedział serwer przypiętego adresu.');
		self::assertStringNotContainsString('internal', (string) $result->body);
		self::assertSame('127.0.0.1', $result->primaryIp);
		self::assertSame(1, $this->network->lookups['rebind.fixture.example'], 'Jedno zapytanie DNS na hop — curl nie rozwiązuje nazwy ponownie.');

		// Nazwa nieznana systemowemu DNS (*.fixture.example) — połączenie było możliwe tylko dzięki przypięciu.
		self::assertFalse(gethostbynamel('rebind.fixture.example'));
	}

	public function test_3_4_6_internal_private_and_metadata_addresses_are_refused_before_connecting(): void
	{
		foreach ([
			'internal.fixture.example' => 'ip_loopback',
			'mixed.fixture.example' => 'ip_private',
			'metadata.fixture.example' => 'ip_link_local',
			'v6.fixture.example' => 'ip_private',
		] as $host => $reason) {
			$result = $this->fetcher()->fetch($this->request('http://' . $host . ':' . self::$port . '/identity'));
			self::assertSame([FetchResult::REFUSED, $reason], [$result->outcome, $result->errorCode], $host);
			self::assertFalse($result->network, $host . ': żadnego połączenia.');
		}

		foreach (['http://127.0.0.1:' . self::$port . '/' => 'ip_literal_not_allowed', 'http://169.254.169.254/latest/meta-data/' => 'ip_literal_not_allowed', 'http://localhost:' . self::$port . '/' => 'host_not_allowed', 'file:///etc/passwd' => 'scheme_not_allowed', 'gopher://www.fixture.example/' => 'scheme_not_allowed'] as $url => $reason) {
			$result = $this->fetcher()->fetch($this->request($url));
			self::assertSame([FetchResult::REFUSED, $reason], [$result->outcome, $result->errorCode], $url);
		}
	}

	public function test_8_every_redirect_is_validated_again(): void
	{
		$base = 'http://www.fixture.example:' . self::$port;
		$cases = [
			'/redirect?to=' . rawurlencode('http://internal.fixture.example:' . self::$port . '/identity') => 'redirect_ip_loopback',
			'/redirect?to=' . rawurlencode('http://metadata.fixture.example:' . self::$port . '/') => 'redirect_ip_link_local',
			'/redirect?to=' . rawurlencode('http://127.0.0.2:' . self::$port . '/identity') => 'redirect_ip_literal_not_allowed',
			'/redirect?to=' . rawurlencode('http://169.254.169.254/latest/meta-data/') => 'redirect_ip_literal_not_allowed',
			'/redirect?to=' . rawurlencode('http://evil.example:' . self::$port . '/') => 'redirect_outside_scope',
			'/redirect?to=' . rawurlencode('file:///etc/passwd') => 'redirect_scheme_not_allowed',
			'/redirect?to=' . rawurlencode('http://user:pass@www.fixture.example:' . self::$port . '/') => 'redirect_credentials_not_allowed',
		];

		foreach ($cases as $path => $reason) {
			$result = $this->fetcher()->fetch($this->request($base . $path));
			self::assertSame([FetchResult::REFUSED, $reason], [$result->outcome, $result->errorCode], $path);
			self::assertCount(1, $result->redirects);
		}

		// Przekierowanie w zakresie — obserwowany łańcuch i adres końcowy.
		$ok = $this->fetcher()->fetch($this->request($base . '/redirect?code=301&to=' . rawurlencode('/redirect?to=/identity')));
		self::assertSame(FetchResult::OK, $ok->outcome);
		self::assertSame([301, 302], array_column($ok->redirects, 'status'));
		self::assertSame($base . '/identity', $ok->finalUrl);

		// https → http: odmowa (bez obniżania ochrony transportu).
		$downgrade = $this->fetcher(self::$ca)->fetch($this->request('https://secure.fixture.example:' . self::$tlsPort . '/to-http'));
		self::assertSame([FetchResult::REFUSED, 'redirect_downgrade'], [$downgrade->outcome, $downgrade->errorCode]);
	}

	public function test_9_redirect_chain_over_the_limit_is_stopped(): void
	{
		$result = $this->fetcher()->fetch($this->request('http://www.fixture.example:' . self::$port . '/chain/1', maxRedirects: 3));

		self::assertSame([FetchResult::REFUSED, 'too_many_redirects'], [$result->outcome, $result->errorCode]);
		self::assertCount(4, $result->redirects);
	}

	public function test_11_total_timeout_is_enforced(): void
	{
		$started = microtime(true);
		$result = $this->fetcher()->fetch($this->request('http://www.fixture.example:' . self::$port . '/slow?seconds=4', timeout: 1));

		self::assertSame([FetchResult::FAILED, 'timeout'], [$result->outcome, $result->errorCode]);
		self::assertLessThan(3.0, microtime(true) - $started);
	}

	public function test_12_oversized_responses_are_aborted_also_after_decompression(): void
	{
		$big = $this->fetcher()->fetch($this->request('http://www.fixture.example:' . self::$port . '/big?kb=3072', maxBytes: 1024 * 1024));
		self::assertSame([FetchResult::FAILED, 'too_large'], [$big->outcome, $big->errorCode]);
		self::assertNull($big->body);
		self::assertLessThanOrEqual(1024 * 1024 + 70000, $big->bytes);

		$bomb = $this->fetcher()->fetch($this->request('http://www.fixture.example:' . self::$port . '/gzip-bomb', maxBytes: 1024 * 1024));
		self::assertSame([FetchResult::FAILED, 'too_large'], [$bomb->outcome, $bomb->errorCode], 'Limit liczony po dekompresji.');
	}

	public function test_13_unsupported_content_type_is_aborted_after_headers(): void
	{
		foreach (['/pdf', '/no-type'] as $path) {
			$result = $this->fetcher()->fetch($this->request('http://www.fixture.example:' . self::$port . $path));
			self::assertSame([FetchResult::FAILED, 'unsupported_content_type'], [$result->outcome, $result->errorCode], $path);
			self::assertNull($result->body);
		}
	}

	public function test_14_http_errors_are_classified_with_retry_after(): void
	{
		foreach ([403, 404, 429, 500] as $status) {
			$result = $this->fetcher()->fetch($this->request('http://www.fixture.example:' . self::$port . '/status/' . $status));
			self::assertSame([FetchResult::HTTP_ERROR, 'http_' . $status, $status], [$result->outcome, $result->errorCode, $result->httpStatus]);
			self::assertNull($result->body, 'Treść odpowiedzi błędu nie jest zachowywana.');
			self::assertSame($status === 429 ? 120 : null, $result->retryAfter);
		}
	}

	public function test_no_proxy_no_cookies_identified_user_agent_conditional_requests_and_charset(): void
	{
		// Środowisko może mieć zmienne proxy — transport łączy się bezpośrednio z przypiętym adresem.
		$result = $this->fetcher()->fetch($this->request('http://www.fixture.example:' . self::$port . '/headers'));
		self::assertSame(FetchResult::OK, $result->outcome, (string) $result->errorCode);
		$seen = json_decode(html_entity_decode((string) preg_replace('#^.*<pre>(.*)</pre>.*$#s', '$1', (string) $result->body)), true);
		self::assertStringStartsWith('Whack-a-mole/', (string) $seen['user_agent']);
		self::assertSame('www.fixture.example:' . self::$port, $seen['host']);
		self::assertNull($seen['cookie']);
		self::assertNull($seen['authorization']);
		self::assertArrayNotHasKey('set-cookie', $result->headers);

		$first = $this->fetcher()->fetch($this->request('http://www.fixture.example:' . self::$port . '/conditional'));
		self::assertSame('"v1"', $first->headers['etag']);
		$second = $this->fetcher()->fetch(new FetchRequest('http://www.fixture.example:' . self::$port . '/conditional', ['fixture.example'], 1_000_000, 5, 2, 3, 'Whack-a-mole/test', FetchRequest::ACCEPT_HTML, '"v1"'));
		self::assertSame([FetchResult::NOT_MODIFIED, 304], [$second->outcome, $second->httpStatus]);

		$latin = $this->fetcher()->fetch($this->request('http://www.fixture.example:' . self::$port . '/latin2'));
		self::assertSame('iso-8859-2', $latin->charset);
	}

	private function fetcher(?string $ca = null): CurlPageFetcher
	{
		return new CurlPageFetcher(new UrlSafetyPolicy($this->network, $this->network), $ca ?? '/nonexistent-ca.pem');
	}

	private function request(string $url, int $timeout = 5, int $maxBytes = 2_000_000, int $maxRedirects = 3): FetchRequest
	{
		return new FetchRequest($url, ['fixture.example'], $maxBytes, $timeout, 2, $maxRedirects, 'Whack-a-mole/test (+https://example.test/; page-intelligence)');
	}
}
