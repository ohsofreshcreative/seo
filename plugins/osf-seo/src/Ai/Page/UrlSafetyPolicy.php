<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Page;

use Closure;
use OsfSeo\Serp\DomainFamily;

/**
 * Polityka bezpieczeństwa adresów dla przyszłego pobierania stron (faza B; w fazie A nic nie pobiera — docs/ARCHITECTURE.md, sekcja 22.10).
 * Ochrona przed SSRF: wyłącznie http(s) na portach 80/443, bez danych logowania w adresie, bez localhost i nazw wewnętrznych, wyłącznie
 * host z rodziny domeny projektu, a KAŻDY adres IP z DNS (IPv4 i IPv6, także IPv4 zagnieżdżony w IPv6) poza sieciami prywatnymi,
 * loopback, link-local (w tym metadane chmury 169.254.169.254), CGNAT, multicast i zarezerwowanymi. Każde przekierowanie przechodzi
 * tę samą kontrolę od nowa (`redirectTarget` + `check`); limity czasu, rozmiaru i liczby przekierowań — stałe poniżej.
 *
 * Uwaga dla fazy B: połączenie musi iść do zweryfikowanego adresu IP (pinning), inaczej zmiana odpowiedzi DNS między kontrolą
 * a połączeniem (DNS rebinding) omija politykę.
 */
final class UrlSafetyPolicy
{
	public const MAX_REDIRECTS = 3;

	public const TIMEOUT = 10;

	public const MAX_BYTES = 2 * 1024 * 1024;

	public const ALLOWED_SCHEMES = ['http', 'https'];

	public const ALLOWED_PORTS = [80, 443];

	/** Nazwy i sufiksy hostów wewnętrznych. */
	private const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal', 'metadata', 'instance-data'];

	private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.intranet', '.corp'];

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

	/**
	 * @param (Closure(string): list<string>)|null $resolver host → adresy IP (testy: atrapa; domyślnie DNS A i AAAA)
	 */
	public function __construct(private readonly ?Closure $resolver = null)
	{
	}

	/**
	 * Kontrola adresu przed pobraniem (i po każdym przekierowaniu).
	 *
	 * @return array{allowed: bool, reason: ?string, host: ?string, ips: list<string>}
	 */
	public function check(string $url, string $projectDomain): array
	{
		$deny = static fn (string $reason, ?string $host = null, array $ips = []): array => ['allowed' => false, 'reason' => $reason, 'host' => $host, 'ips' => $ips];

		if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
			return $deny('invalid_url');
		}

		$parts = parse_url($url);

		if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
			return $deny('invalid_url');
		}

		if (! in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
			return $deny('scheme_not_allowed');
		}

		if (isset($parts['user']) || isset($parts['pass'])) {
			return $deny('credentials_not_allowed');
		}

		$port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);

		if (! in_array($port, self::ALLOWED_PORTS, true)) {
			return $deny('port_not_allowed');
		}

		$host = rtrim(strtolower($parts['host']), '.');

		if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^[0-9.]+$|^0x/i', $host) === 1) {
			return $deny('ip_literal_not_allowed', $host);
		}

		if ($host === '' || ! str_contains($host, '.') || in_array($host, self::BLOCKED_HOSTS, true)) {
			return $deny('host_not_allowed', $host);
		}

		foreach (self::BLOCKED_SUFFIXES as $suffix) {
			if (str_ends_with($host, $suffix)) {
				return $deny('host_not_allowed', $host);
			}
		}

		$family = DomainFamily::normalize($projectDomain);

		if ($family === null || ! DomainFamily::matches($host, $family)) {
			return $deny('outside_project', $host);
		}

		$ips = $this->resolve($host);

		if ($ips === []) {
			return $deny('dns_failed', $host);
		}

		foreach ($ips as $ip) {
			$reason = self::ipReason($ip);

			if ($reason !== null) {
				return $deny($reason, $host, $ips);
			}
		}

		return ['allowed' => true, 'reason' => null, 'host' => $host, 'ips' => $ips];
	}

	/**
	 * Adres przekierowania (nagłówek Location) względem adresu bieżącego — do ponownej kontroli `check`. Null = niepoprawny.
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

		$path = (string) ($base['path'] ?? '/');

		return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
	}

	/**
	 * Powód blokady adresu IP albo null (dozwolony adres publiczny).
	 */
	public static function ipReason(string $ip): ?string
	{
		$binary = @inet_pton($ip);

		if ($binary === false) {
			return 'invalid_ip';
		}

		// IPv4 zagnieżdżony w IPv6 (::ffff:a.b.c.d, ::a.b.c.d) — kontrola jak IPv4.
		if (strlen($binary) === 16 && (str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($binary, str_repeat("\0", 12)))
			&& substr($binary, 12) !== "\0\0\0\0" && substr($binary, 12) !== "\0\0\0\1") {
			return self::ipReason((string) inet_ntop(substr($binary, 12))) ?? 'ip_embedded';
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

	/**
	 * @return list<string>
	 */
	private function resolve(string $host): array
	{
		if ($this->resolver !== null) {
			return array_values(array_filter(($this->resolver)($host), 'is_string'));
		}

		$ips = gethostbynamel($host) ?: [];

		foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
			if (isset($record['ipv6']) && is_string($record['ipv6'])) {
				$ips[] = $record['ipv6'];
			}
		}

		return array_values(array_unique($ips));
	}
}
