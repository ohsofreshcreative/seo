<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\PageIntelligence;

use OsfSeo\PageIntelligence\Fetch\PublicNetworkPolicy;
use OsfSeo\PageIntelligence\Fetch\UrlSafetyPolicy;
use OsfSeo\Tests\Support\FixtureNetwork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Polityka adresów (produkcyjna polityka sieci, atrapa DNS): składnia, nietypowe zapisy IP, hosty wewnętrzne, zakres domeny, adresy
 * prywatne i zarezerwowane IPv4/IPv6. Ochronę samego połączenia (przypięcie IP) sprawdza `CurlPageFetcherTest` na prawdziwym transporcie.
 */
final class UrlSafetyPolicyTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, string|null, list<string>}>
	 */
	public static function urls(): iterable
	{
		yield 'public https' => ['https://example.pl/oferta/', null, ['93.184.216.34']];
		yield 'subdomain over ipv6' => ['http://blog.example.pl/wpis', null, ['2606:2800:220:1:248:1893:25c8:1946']];
		yield 'idn host' => ['https://łódź.example.pl/', null, ['93.184.216.34']];
		yield 'ftp' => ['ftp://example.pl/', 'scheme_not_allowed', []];
		yield 'file' => ['file:///etc/passwd', 'scheme_not_allowed', []];
		yield 'javascript' => ['javascript:alert(1)', 'scheme_not_allowed', []];
		yield 'credentials' => ['https://user:' . 'x@example.pl/', 'credentials_not_allowed', []];
		yield 'user only' => ['https://evil.com@example.pl/', 'credentials_not_allowed', []];
		yield 'backslash trick' => ['https://example.pl\\@evil.com/', 'invalid_url', []];
		yield 'port' => ['https://example.pl:8443/', 'port_not_allowed', []];
		yield 'ip literal' => ['http://127.0.0.1/', 'ip_literal_not_allowed', []];
		yield 'ipv6 literal' => ['http://[::1]/', 'ip_literal_not_allowed', []];
		yield 'ipv6 mapped literal' => ['http://[::ffff:127.0.0.1]/', 'ip_literal_not_allowed', []];
		yield 'decimal ip' => ['http://2130706433/', 'ip_literal_not_allowed', []];
		yield 'octal ip' => ['http://0177.0.0.1/', 'ip_literal_not_allowed', []];
		yield 'hex ip' => ['http://0x7f.0.0.1/', 'ip_literal_not_allowed', []];
		yield 'short ip' => ['http://127.1/', 'ip_literal_not_allowed', []];
		yield 'numeric tld' => ['http://example.123/', 'ip_literal_not_allowed', []];
		yield 'localhost' => ['http://localhost/', 'host_not_allowed', ['127.0.0.1']];
		yield 'localhost trailing dot' => ['http://localhost./', 'host_not_allowed', ['127.0.0.1']];
		yield 'single label' => ['http://intranet/', 'host_not_allowed', []];
		yield 'internal suffix' => ['http://metadata.google.internal/', 'host_not_allowed', ['169.254.169.254']];
		yield 'local suffix' => ['http://printer.local/', 'host_not_allowed', []];
		yield 'other domain' => ['https://konkurent.pl/', 'outside_scope', ['93.184.216.34']];
		yield 'lookalike domain' => ['https://notexample.pl/', 'outside_scope', ['93.184.216.34']];
		yield 'suffix trick' => ['https://example.pl.evil.com/', 'outside_scope', ['93.184.216.34']];
		yield 'dns rebinding to loopback' => ['https://example.pl/', 'ip_loopback', ['127.0.0.1']];
		yield 'private network' => ['https://example.pl/', 'ip_private', ['10.0.0.5']];
		yield 'cgnat' => ['https://example.pl/', 'ip_private', ['100.64.1.1']];
		yield 'cloud metadata' => ['https://example.pl/', 'ip_link_local', ['169.254.169.254']];
		yield 'any blocked address wins' => ['https://example.pl/', 'ip_private', ['93.184.216.34', '192.168.1.10']];
		yield 'ipv4 mapped ipv6' => ['https://example.pl/', 'ip_loopback', ['::ffff:127.0.0.1']];
		yield 'ipv4 compatible ipv6' => ['https://example.pl/', 'ip_private', ['::10.0.0.1']];
		yield 'nat64' => ['https://example.pl/', 'ip_embedded', ['64:ff9b::7f00:1']];
		yield 'ipv6 loopback' => ['https://example.pl/', 'ip_loopback', ['::1']];
		yield 'ipv6 unique local' => ['https://example.pl/', 'ip_private', ['fd00::1']];
		yield 'ipv6 link local' => ['https://example.pl/', 'ip_link_local', ['fe80::1']];
		yield 'multicast' => ['https://example.pl/', 'ip_multicast', ['224.0.0.1']];
		yield 'unspecified' => ['https://example.pl/', 'ip_unspecified', ['0.0.0.0']];
		yield 'documentation range' => ['https://example.pl/', 'ip_reserved', ['203.0.113.7']];
		yield 'dns failure' => ['https://example.pl/', 'dns_failed', []];
	}

	/**
	 * @param list<string> $ips
	 */
	#[DataProvider('urls')]
	public function test_url_policy(string $url, ?string $reason, array $ips): void
	{
		$host = (string) parse_url($url, PHP_URL_HOST);
		$ascii = (string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
		$resolver = new FixtureNetwork([$host => $ips, $ascii => $ips], []);
		$result = (new UrlSafetyPolicy(new PublicNetworkPolicy(), $resolver))->check($url, ['example.pl']);

		self::assertSame($reason, $result['reason']);
		self::assertSame($reason === null, $result['allowed']);
	}

	public function test_inspect_normalizes_without_dns(): void
	{
		$resolver = new FixtureNetwork([], []);
		$policy = new UrlSafetyPolicy(new PublicNetworkPolicy(), $resolver);

		self::assertSame('https://www.example.pl/oferta/?a=1', $policy->inspect('HTTPS://WWW.Example.PL./oferta/?a=1#sekcja')['url']);
		self::assertSame('http://example.pl/', $policy->inspect('http://example.pl')['url']);
		self::assertSame('example.pl', $policy->inspect('https://www.example.pl/')['family']);
		self::assertSame([], $resolver->lookups, 'Kontrola składni nie wykonuje zapytań DNS.');
	}

	public function test_redirect_targets_are_resolved_for_a_new_check(): void
	{
		self::assertSame('https://example.pl/nowa/', UrlSafetyPolicy::redirectTarget('https://example.pl/stara/strona', '/nowa/'));
		self::assertSame('https://example.pl/stara/inna', UrlSafetyPolicy::redirectTarget('https://example.pl/stara/strona', 'inna'));
		self::assertSame('https://example.pl/stara/strona?x=1', UrlSafetyPolicy::redirectTarget('https://example.pl/stara/strona', '?x=1'));
		self::assertSame('https://evil.example/', UrlSafetyPolicy::redirectTarget('https://example.pl/', '//evil.example/'));
		self::assertNull(UrlSafetyPolicy::redirectTarget('https://example.pl/', "https://example.pl/\r\nX-Injected: 1"));
		self::assertTrue(UrlSafetyPolicy::sameIp('::1', '0:0:0:0:0:0:0:1'));
		self::assertFalse(UrlSafetyPolicy::sameIp('127.0.0.1', '127.0.0.2'));
	}
}
