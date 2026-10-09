<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Fetch;

use OsfSeo\Serp\DomainFamily;

/**
 * Polityka bezpieczeństwa adresów pobierania stron (STEP 17, faza B — docs/ARCHITECTURE.md, sekcja 23.4). Trzy poziomy:
 *
 * 1. `inspect` — wyłącznie składnia (bez DNS i bez HTTP; plan pobrania): `http`/`https`, dozwolony port, bez danych logowania w adresie,
 *    bez literałów IP w każdym zapisie (dziesiętny, ósemkowy, szesnastkowy, IPv6), bez `localhost` i nazw wewnętrznych.
 * 2. `resolve` — DNS i KAŻDY zwrócony adres przez politykę sieci (prywatne, loopback, link-local z metadanymi chmury, CGNAT, multicast,
 *    zarezerwowane, IPv4 zagnieżdżony w IPv6) — jeden zablokowany adres blokuje cały host.
 * 3. Połączenie wyłącznie z adresami zwróconymi przez `resolve` (`CurlPageFetcher` — przypięcie IP, ochrona przed DNS rebinding).
 *    Sama ta klasa NIE chroni przed rebindingiem — chroni dopiero transport łączący się z tymi adresami.
 *
 * Zakres hostów (rodzina domeny projektu albo strony konkurenta) sprawdza wywołujący (`inScope`). Każde przekierowanie przechodzi
 * wszystkie poziomy od nowa (`redirectTarget`).
 */
final class UrlSafetyPolicy
{
	public const MAX_URL_LENGTH = 2048;

	public const SCHEMES = ['http', 'https'];

	/** Nazwy i sufiksy hostów wewnętrznych. */
	private const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal', 'metadata', 'instance-data', 'kubernetes.default'];

	private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.intranet', '.corp', '.localdomain', '.svc', '.cluster.local'];

	/** Zablokowane sieci (CIDR) → powód. */
	private const BLOCKED_RANGES = [
		'0.0.0.0/8' => 'ip_unspecified',
		'10.0.0.0/8' => 'ip_private',
		'100.64.0.0/10' => 'ip_private',
		'127.0.0.0/8' => 'ip_loopback',
		'169.254.0.0/16' => 'ip_link_local',
		'172.16.0.0/12' => 'ip_private',
		'192.0.0.0/24' => 'ip_reserved',
		'192.0.2.0/24' => 'ip_reserved',
		'192.88.99.0/24' => 'ip_reserved',
		'192.168.0.0/16' => 'ip_private',
		'198.18.0.0/15' => 'ip_reserved',
		'198.51.100.0/24' => 'ip_reserved',
		'203.0.113.0/24' => 'ip_reserved',
		'224.0.0.0/4' => 'ip_multicast',
		'240.0.0.0/4' => 'ip_reserved',
		'::/128' => 'ip_unspecified',
		'::1/128' => 'ip_loopback',
		'::ffff:0:0:0/96' => 'ip_embedded',
		'64:ff9b::/96' => 'ip_embedded',
		'64:ff9b:1::/48' => 'ip_reserved',
		'100::/64' => 'ip_reserved',
		'2001::/23' => 'ip_reserved',
		'2001:db8::/32' => 'ip_reserved',
		'2002::/16' => 'ip_embedded',
		'fc00::/7' => 'ip_private',
		'fe80::/10' => 'ip_link_local',
		'fec0::/10' => 'ip_private',
		'ff00::/8' => 'ip_multicast',
	];

	private readonly HostResolver $resolver;

	public function __construct(
		private readonly NetworkPolicy $network = new PublicNetworkPolicy(),
		?HostResolver $resolver = null,
	) {
		$this->resolver = $resolver ?? new SystemHostResolver();
	}

	/**
	 * Kontrola składni adresu (bez DNS i bez HTTP).
	 *
	 * @return array{allowed: bool, reason: ?string, url: ?string, scheme: ?string, host: ?string, port: ?int, family: ?string}
	 */
	public function inspect(string $url): array
	{
		$deny = static fn (string $reason, ?string $host = null): array => ['allowed' => false, 'reason' => $reason, 'url' => null, 'scheme' => null, 'host' => $host, 'port' => null, 'family' => null];
		$url = trim($url);

		if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
			return $deny('invalid_url');
		}

		$parts = parse_url($url);

		if (! is_array($parts) || ! isset($parts['scheme'])) {
			return $deny('invalid_url');
		}

		$scheme = strtolower($parts['scheme']);

		if (! in_array($scheme, self::SCHEMES, true)) {
			return $deny('scheme_not_allowed');
		}

		if (! isset($parts['host']) || $parts['host'] === '') {
			return $deny('invalid_url');
		}

		if (isset($parts['user']) || isset($parts['pass'])) {
			return $deny('credentials_not_allowed');
		}

		$host = rtrim(strtolower($parts['host']), '.');

		// Literał IP w dowolnym zapisie (także mieszanym: `127.0x1`, `0x7f.1`) — etykieta szesnastkowa albo ostatnia etykieta z samych cyfr
		// (prawdziwa domena najwyższego poziomu nigdy nie jest liczbą).
		if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^[0-9.]+$|(^|\.)0x[0-9a-f]*(\.|$)|(^|\.)[0-9]+$/i', $host) === 1) {
			return $deny('ip_literal_not_allowed', $host);
		}

		if (preg_match('/[^\x21-\x7e]/', $host) === 1) {
			$ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;

			if (! is_string($ascii) || $ascii === '') {
				return $deny('invalid_host', $host);
			}

			$host = strtolower($ascii);
		}

		if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
			return $deny(str_contains($host, '.') ? 'invalid_host' : 'host_not_allowed', $host);
		}

		if (in_array($host, self::BLOCKED_HOSTS, true)) {
			return $deny('host_not_allowed', $host);
		}

		foreach (self::BLOCKED_SUFFIXES as $suffix) {
			if (str_ends_with($host, $suffix)) {
				return $deny('host_not_allowed', $host);
			}
		}

		// Ostatnia etykieta liczbowa (np. `example.123`) — możliwy zapis IP w resolverze.
		if (preg_match('/\.[0-9]+$/', $host) === 1) {
			return $deny('ip_literal_not_allowed', $host);
		}

		$port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

		if (! in_array($port, $this->network->allowedPorts(), true)) {
			return $deny('port_not_allowed', $host);
		}

		$path = (string) ($parts['path'] ?? '/');
		$default = $scheme === 'https' ? 443 : 80;
		$normalized = $scheme . '://' . $host . ($port !== $default ? ':' . $port : '') . ($path === '' ? '/' : $path) . (isset($parts['query']) ? '?' . $parts['query'] : '');

		return [
			'allowed' => true,
			'reason' => null,
			'url' => $normalized,
			'scheme' => $scheme,
			'host' => $host,
			'port' => $port,
			'family' => DomainFamily::normalize($host),
		];
	}

	/**
	 * DNS + kontrola każdego adresu. Pusta lista adresów = `dns_failed`; jeden zablokowany adres blokuje host.
	 *
	 * @return array{ips: list<string>, reason: ?string}
	 */
	public function resolve(string $host): array
	{
		$ips = array_values(array_unique(array_filter($this->resolver->resolve($host), 'is_string')));

		if ($ips === []) {
			return ['ips' => [], 'reason' => 'dns_failed'];
		}

		foreach ($ips as $ip) {
			$reason = $this->network->addressReason($ip);

			if ($reason !== null) {
				return ['ips' => $ips, 'reason' => $reason];
			}
		}

		return ['ips' => $ips, 'reason' => null];
	}

	/**
	 * Diagnostyka (CLI `pages:check-url`): składnia, zakres i DNS z kontrolą adresów — bez HTTP.
	 *
	 * @param list<string> $families dozwolone rodziny domen
	 * @return array{allowed: bool, reason: ?string, host: ?string, ips: list<string>}
	 */
	public function check(string $url, array $families): array
	{
		$parsed = $this->inspect($url);

		if (! $parsed['allowed']) {
			return ['allowed' => false, 'reason' => $parsed['reason'], 'host' => $parsed['host'], 'ips' => []];
		}

		if (! self::inScope((string) $parsed['host'], $families)) {
			return ['allowed' => false, 'reason' => 'outside_scope', 'host' => $parsed['host'], 'ips' => []];
		}

		$resolved = $this->resolve((string) $parsed['host']);

		return ['allowed' => $resolved['reason'] === null, 'reason' => $resolved['reason'], 'host' => $parsed['host'], 'ips' => $resolved['ips']];
	}

	public function network(): NetworkPolicy
	{
		return $this->network;
	}

	/**
	 * Host należy do jednej z rodzin domen (dopasowanie na granicy etykiet, `www.` bez znaczenia).
	 *
	 * @param list<string> $families
	 */
	public static function inScope(string $host, array $families): bool
	{
		$normalized = DomainFamily::normalize($host);

		if ($normalized === null) {
			return false;
		}

		foreach ($families as $family) {
			if ($family !== '' && DomainFamily::matches($normalized, $family)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adres przekierowania (nagłówek Location) względem adresu bieżącego — do pełnej ponownej kontroli. Null = niepoprawny.
	 */
	public static function redirectTarget(string $current, string $location): ?string
	{
		$location = trim($location);

		if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location) === 1) {
			return null;
		}

		if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $location) === 1) {
			return $location;
		}

		$base = parse_url($current);

		if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
			return null;
		}

		$origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

		if (str_starts_with($location, '//')) {
			return $base['scheme'] . ':' . $location;
		}

		if (str_starts_with($location, '/')) {
			return $origin . $location;
		}

		if (str_starts_with($location, '?')) {
			return $origin . (string) ($base['path'] ?? '/') . $location;
		}

		$path = (string) ($base['path'] ?? '/');

		return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
	}

	/**
	 * Powód blokady adresu IP (publiczna polityka) albo null.
	 */
	public static function ipReason(string $ip): ?string
	{
		$binary = @inet_pton(trim($ip, '[]'));

		if ($binary === false) {
			return 'invalid_ip';
		}

		// IPv4 zagnieżdżony w IPv6 (::ffff:a.b.c.d — zawsze, także ::ffff:0.0.0.0, który Linux kieruje na loopback; ::a.b.c.d — poza
		// `::` i `::1`, sprawdzanymi niżej jako IPv6) — kontrola jak IPv4, a adres publiczny i tak blokowany jako zagnieżdżony.
		if (strlen($binary) === 16) {
			$mapped = str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff");
			$compatible = str_starts_with($binary, str_repeat("\0", 12)) && ! in_array(substr($binary, 12), ["\0\0\0\0", "\0\0\0\1"], true);

			if ($mapped || $compatible) {
				return self::ipReason((string) inet_ntop(substr($binary, 12))) ?? 'ip_embedded';
			}
		}

		foreach (self::BLOCKED_RANGES as $cidr => $reason) {
			[$network, $bits] = explode('/', $cidr);
			$prefix = (string) inet_pton($network);

			if (strlen($prefix) === strlen($binary) && self::inRange($binary, $prefix, (int) $bits)) {
				return $reason;
			}
		}

		return null;
	}

	/** Ten sam adres IP niezależnie od zapisu (IPv6 skrócony / pełny). */
	public static function sameIp(string $a, string $b): bool
	{
		$first = @inet_pton(trim($a, '[]'));
		$second = @inet_pton(trim($b, '[]'));

		return $first !== false && $first === $second;
	}

	private static function inRange(string $address, string $network, int $bits): bool
	{
		$bytes = intdiv($bits, 8);

		if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
			return false;
		}

		$rest = $bits % 8;

		if ($rest === 0) {
			return true;
		}

		$mask = (0xFF << (8 - $rest)) & 0xFF;

		return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
	}
}
