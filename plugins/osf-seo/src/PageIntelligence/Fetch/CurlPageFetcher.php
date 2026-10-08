<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Fetch;

/**
 * Bezpieczny transport pobierania stron (STEP 17, faza B — docs/ARCHITECTURE.md, sekcja 23.4) na ext-curl, z pominięciem WordPress HTTP API:
 * WP (`wp_safe_remote_get`) sprawdza adres IP, ale łączy się ponownie po nazwie hosta (drugie zapytanie DNS — okno na DNS rebinding)
 * i może użyć transportu strumieni bez możliwości przypięcia adresu.
 *
 * Każdy hop (żądanie i każde przekierowanie, ręcznie — bez FOLLOWLOCATION):
 * 1. składnia (`UrlSafetyPolicy::inspect`), zakaz zmiany https → http, zakres hostów (`inScope`),
 * 2. DNS raz i kontrola KAŻDEGO adresu (`UrlSafetyPolicy::resolve`),
 * 3. połączenie wyłącznie z tymi adresami (`CURLOPT_RESOLVE` — curl nie pyta DNS), weryfikacja `CURLINFO_PRIMARY_IP` po połączeniu,
 * 4. TLS: weryfikacja łańcucha i nazwy hosta względem ORYGINALNEJ nazwy (SNI i certyfikat jak przy zwykłym połączeniu) — nigdy wyłączana,
 * 5. bez proxy (proxy rozwiązywałoby nazwę samo — przypięcie nie miałoby sensu), bez ciasteczek, bez ponownego użycia połączeń,
 * 6. limity: czas połączenia i całkowity (wspólny dla całego łańcucha), rozmiar (także po dekompresji — przerwanie strumienia),
 *    nagłówki, typ treści (przerwanie po nagłówkach), liczba przekierowań.
 *
 * Bez ext-curl albo bez `CURLOPT_RESOLVE` — odmowa (`transport_unavailable`), nigdy cichy powrót do niechronionego transportu.
 */
final class CurlPageFetcher implements PageFetcher
{
	private const REDIRECTS = [301, 302, 303, 307, 308];

	/** Nagłówki odpowiedzi zachowywane (reszta, w tym ciasteczka, jest pomijana). */
	private const KEEP_HEADERS = ['content-type', 'content-length', 'location', 'etag', 'last-modified', 'x-robots-tag', 'retry-after', 'content-language'];

	private const MAX_HEADER_LINES = 200;

	private const MAX_HEADER_BYTES = 65536;

	/** Kody błędów curl związane z TLS (handshake, certyfikat, CA). */
	private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

	public function __construct(
		private readonly UrlSafetyPolicy $policy,
		private readonly ?string $caBundle = null,
	) {
	}

	public static function available(): bool
	{
		return function_exists('curl_init') && defined('CURLOPT_RESOLVE') && defined('CURLINFO_PRIMARY_IP');
	}

	public function fetch(FetchRequest $request): FetchResult
	{
		$started = microtime(true);
		$deadline = $started + $request->timeout;
		$redirects = [];
		$url = $request->url;
		$previousScheme = null;
		$network = false;
		// Domknięcie przez referencję: łańcuch przekierowań i znacznik ruchu sieciowego zmieniają się w pętli.
		$finish = static function (string $outcome, ?string $error, array $extra = []) use ($request, $started, &$redirects, &$network): FetchResult {
			return new FetchResult(
				$outcome,
				$error,
				$extra['status'] ?? null,
				$request->url,
				$extra['final'] ?? null,
				$extra['type'] ?? null,
				$extra['charset'] ?? null,
				$extra['headers'] ?? [],
				$extra['body'] ?? null,
				$extra['bytes'] ?? 0,
				(int) round((microtime(true) - $started) * 1000),
				$redirects,
				$extra['ip'] ?? null,
				$extra['retry_after'] ?? null,
				$network,
			);
		};

		if (! self::available()) {
			return $finish(FetchResult::REFUSED, 'transport_unavailable');
		}

		for ($hop = 0; $hop <= $request->maxRedirects; $hop++) {
			$parsed = $this->policy->inspect($url);

			if (! $parsed['allowed']) {
				return $finish(FetchResult::REFUSED, ($hop > 0 ? 'redirect_' : '') . $parsed['reason']);
			}

			if ($previousScheme === 'https' && $parsed['scheme'] === 'http') {
				return $finish(FetchResult::REFUSED, 'redirect_downgrade');
			}

			if (! UrlSafetyPolicy::inScope((string) $parsed['host'], $request->families)) {
				return $finish(FetchResult::REFUSED, $hop > 0 ? 'redirect_outside_scope' : 'outside_scope');
			}

			$resolved = $this->policy->resolve((string) $parsed['host']);

			if ($resolved['reason'] === 'dns_failed') {
				return $finish(FetchResult::FAILED, 'dns_failed');
			}

			if ($resolved['reason'] !== null) {
				return $finish(FetchResult::REFUSED, ($hop > 0 ? 'redirect_' : '') . $resolved['reason']);
			}

			$remaining = $deadline - microtime(true);

			if ($remaining < 0.05) {
				return $finish(FetchResult::FAILED, 'timeout');
			}

			$network = true;
			$response = $this->request($parsed, $resolved['ips'], $request, $remaining, $hop === 0);

			if ($response['error'] !== null) {
				return $finish($response['error'] === 'pinning_violation' ? FetchResult::REFUSED : FetchResult::FAILED, $response['error'], ['status' => $response['status'], 'final' => $parsed['url'], 'bytes' => $response['bytes']]);
			}

			$status = (int) $response['status'];
			$headers = $response['headers'];

			if (in_array($status, self::REDIRECTS, true)) {
				$next = isset($headers['location']) ? UrlSafetyPolicy::redirectTarget((string) $parsed['url'], $headers['location']) : null;

				if ($next === null) {
					return $finish(FetchResult::HTTP_ERROR, 'redirect_without_location', ['status' => $status, 'final' => $parsed['url']]);
				}

				$redirects[] = ['url' => (string) $parsed['url'], 'status' => $status];
				$previousScheme = $parsed['scheme'];
				$url = $next;

				continue;
			}

			[$type, $charset] = self::contentType($headers['content-type'] ?? null);
			$extra = ['status' => $status, 'final' => $parsed['url'], 'headers' => $headers, 'type' => $type, 'charset' => $charset, 'bytes' => $response['bytes'], 'ip' => $response['ip']];

			if ($status === 304) {
				return $finish(FetchResult::NOT_MODIFIED, null, $extra);
			}

			if ($status >= 200 && $status < 300) {
				return $finish(FetchResult::OK, null, $extra + ['body' => $response['body']]);
			}

			return $finish(FetchResult::HTTP_ERROR, 'http_' . $status, $extra + ['retry_after' => self::retryAfter($headers['retry-after'] ?? null)]);
		}

		return $finish(FetchResult::REFUSED, 'too_many_redirects');
	}

	/**
	 * Jedno żądanie do przypiętych adresów.
	 *
	 * @param array{url: ?string, scheme: ?string, host: ?string, port: ?int} $parsed
	 * @param list<string> $ips adresy zweryfikowane przez politykę
	 * @return array{status: ?int, headers: array<string, string>, body: string, bytes: int, error: ?string, ip: ?string}
	 */
	private function request(array $parsed, array $ips, FetchRequest $request, float $remaining, bool $first): array
	{
		$host = (string) $parsed['host'];
		$port = (int) $parsed['port'];
		$addresses = implode(',', array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $ips));
		$status = null;
		$headers = [];
		$headerLines = 0;
		$headerBytes = 0;
		$body = '';
		$bytes = 0;
		$abort = null;
		$accept = $request->accept;
		$requestHeaders = ['Accept: ' . implode(', ', $accept) . ';q=1.0, */*;q=0.1'];

		if ($first && $request->etag !== null) {
			$requestHeaders[] = 'If-None-Match: ' . $request->etag;
		}

		if ($first && $request->lastModified !== null) {
			$requestHeaders[] = 'If-Modified-Since: ' . $request->lastModified;
		}

		$handle = curl_init();
		$timeoutMs = max(50, (int) floor($remaining * 1000));
		$options = [
			CURLOPT_URL => (string) $parsed['url'],
			CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $addresses],
			CURLOPT_PROXY => '',
			CURLOPT_NOPROXY => '*',
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_FORBID_REUSE => true,
			CURLOPT_FRESH_CONNECT => true,
			CURLOPT_NOSIGNAL => true,
			CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, max(50, $request->connectTimeout * 1000)),
			CURLOPT_TIMEOUT_MS => $timeoutMs,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT => $request->userAgent,
			CURLOPT_HTTPHEADER => $requestHeaders,
			CURLOPT_ENCODING => '',
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$status, &$headers, &$headerLines, &$headerBytes, &$abort, $accept, $request): int {
				$length = strlen($line);
				$headerBytes += $length;
				$trimmed = trim($line);

				if (++$headerLines > self::MAX_HEADER_LINES || $headerBytes > self::MAX_HEADER_BYTES) {
					$abort = 'headers_too_large';

					return 0;
				}

				if (preg_match('#^HTTP/[0-9.]+\s+([0-9]{3})#', $trimmed, $match) === 1) {
					$status = (int) $match[1];
					$headers = [];

					return $length;
				}

				if ($trimmed === '') {
					if ($status !== null && $status >= 200 && $status < 300) {
						[$type] = self::contentType($headers['content-type'] ?? null);

						if ($type === null || ! in_array($type, $accept, true)) {
							$abort = 'unsupported_content_type';

							return 0;
						}

						if (isset($headers['content-length']) && ctype_digit($headers['content-length']) && (int) $headers['content-length'] > $request->maxBytes) {
							$abort = 'too_large';

							return 0;
						}
					}

					return $length;
				}

				$colon = strpos($trimmed, ':');

				if ($colon !== false) {
					$name = strtolower(trim(substr($trimmed, 0, $colon)));
					$value = trim(substr($trimmed, $colon + 1));

					if (in_array($name, self::KEEP_HEADERS, true)) {
						$headers[$name] = isset($headers[$name]) && $name === 'x-robots-tag' ? $headers[$name] . ', ' . $value : mb_substr($value, 0, 1000);
					}
				}

				return $length;
			},
			CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$bytes, &$abort, &$status, $request): int {
				$length = strlen($chunk);
				$bytes += $length;

				if ($bytes > $request->maxBytes) {
					$abort = 'too_large';

					return 0;
				}

				if ($status !== null && $status >= 200 && $status < 300) {
					$body .= $chunk;
				}

				return $length;
			},
		];

		if (defined('CURLOPT_PROTOCOLS_STR')) {
			$options[CURLOPT_PROTOCOLS_STR] = 'http,https';
			$options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
		} else {
			$options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
			$options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
		}

		if ($this->caBundle !== null && is_file($this->caBundle)) {
			$options[CURLOPT_CAINFO] = $this->caBundle;
		}

		curl_setopt_array($handle, $options);
		curl_exec($handle);
		$errno = curl_errno($handle);
		$primary = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
		curl_close($handle);
		$result = ['status' => $status, 'headers' => $headers, 'body' => $body, 'bytes' => $bytes, 'error' => null, 'ip' => $primary !== '' ? $primary : null];

		// Obrona w głąb: połączenie musiało trafić pod przypięty adres.
		if ($primary !== '' && array_filter($ips, static fn (string $ip): bool => UrlSafetyPolicy::sameIp($ip, $primary)) === []) {
			return ['body' => ''] + ['error' => 'pinning_violation'] + $result;
		}

		if ($abort !== null) {
			return ['error' => $abort, 'body' => ''] + $result;
		}

		if ($errno !== 0) {
			return ['error' => self::curlError($errno), 'body' => ''] + $result;
		}

		if ($status === null) {
			return ['error' => 'invalid_response'] + $result;
		}

		return $result;
	}

	private static function curlError(int $errno): string
	{
		return match (true) {
			$errno === 28 => 'timeout',
			$errno === 6 => 'dns_failed',
			$errno === 7 => 'connect_failed',
			in_array($errno, self::TLS_ERRORS, true) => 'tls_error',
			$errno === 1 => 'unsupported_protocol',
			$errno === 63 => 'too_large',
			in_array($errno, [52, 56, 18, 55, 16, 92], true) => 'connection_error',
			default => 'network_error',
		};
	}

	/**
	 * @return array{0: ?string, 1: ?string} typ (małe litery, bez parametrów) i kodowanie z nagłówka
	 */
	public static function contentType(?string $header): array
	{
		if ($header === null || trim($header) === '') {
			return [null, null];
		}

		$parts = explode(';', $header);
		$type = strtolower(trim($parts[0]));
		$charset = null;

		foreach (array_slice($parts, 1) as $parameter) {
			if (preg_match('/^\s*charset\s*=\s*"?([A-Za-z0-9._:\-]{1,40})"?\s*$/i', $parameter, $match) === 1) {
				$charset = strtolower($match[1]);
			}
		}

		return [preg_match('#^[a-z0-9.+\-]+/[a-z0-9.+\-]+$#', $type) === 1 ? $type : null, $charset];
	}

	/** `Retry-After` w sekundach (liczba albo data HTTP), najwyżej doba. */
	public static function retryAfter(?string $value): ?int
	{
		if ($value === null || trim($value) === '') {
			return null;
		}

		$value = trim($value);

		if (ctype_digit($value)) {
			return min(86400, (int) $value);
		}

		$time = strtotime($value);

		return $time === false ? null : max(0, min(86400, $time - time()));
	}
}
