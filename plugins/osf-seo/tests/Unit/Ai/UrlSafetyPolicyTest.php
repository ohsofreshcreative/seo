<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Ai;

use OsfSeo\Ai\Page\UrlSafetyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Polityka adresów przyszłego pobierania stron (faza B; w fazie A nic nie jest pobierane): ochrona przed SSRF bez sieci (atrapa DNS).
 */
final class UrlSafetyPolicyTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, string|null, list<string>}>
	 */
	public static function urls(): iterable
	{
		yield 'public https' => ['https://example.pl/oferta/', null, ['93.184.216.34']];
		yield 'subdomain' => ['http://blog.example.pl/wpis', null, ['2606:2800:220:1:248:1893:25c8:1946']];
		yield 'ftp' => ['ftp://example.pl/', 'scheme_not_allowed', ['93.184.216.34']];
		yield 'file' => ['file:///etc/passwd', 'invalid_url', []];
		yield 'credentials' => ['https://user:pass@example.pl/', 'credentials_not_allowed', ['93.184.216.34']];
		yield 'port' => ['https://example.pl:8443/', 'port_not_allowed', ['93.184.216.34']];
		yield 'ip literal' => ['http://127.0.0.1/', 'ip_literal_not_allowed', []];
		yield 'ipv6 literal' => ['http://[::1]/', 'ip_literal_not_allowed', []];
		yield 'decimal ip' => ['http://2130706433/', 'ip_literal_not_allowed', []];
		yield 'localhost' => ['http://localhost/', 'host_not_allowed', ['127.0.0.1']];
		yield 'internal suffix' => ['http://metadata.google.internal/', 'host_not_allowed', ['169.254.169.254']];
		yield 'other domain' => ['https://konkurent.pl/', 'outside_project', ['93.184.216.34']];
		yield 'lookalike domain' => ['https://notexample.pl/', 'outside_project', ['93.184.216.34']];
		yield 'dns rebinding to loopback' => ['https://example.pl/', 'ip_loopback', ['127.0.0.1']];
		yield 'private network' => ['https://example.pl/', 'ip_private', ['10.0.0.5']];
		yield 'cgnat' => ['https://example.pl/', 'ip_private', ['100.64.1.1']];
		yield 'cloud metadata' => ['https://example.pl/', 'ip_link_local', ['169.254.169.254']];
		yield 'any blocked address wins' => ['https://example.pl/', 'ip_private', ['93.184.216.34', '192.168.1.10']];
		yield 'ipv4 mapped ipv6' => ['https://example.pl/', 'ip_loopback', ['::ffff:127.0.0.1']];
		yield 'ipv6 unique local' => ['https://example.pl/', 'ip_private', ['fd00::1']];
		yield 'ipv6 link local' => ['https://example.pl/', 'ip_link_local', ['fe80::1']];
		yield 'multicast' => ['https://example.pl/', 'ip_multicast', ['224.0.0.1']];
		yield 'dns failure' => ['https://example.pl/', 'dns_failed', []];
	}

	/**
	 * @param list<string> $ips
	 */
	#[DataProvider('urls')]
	public function test_url_policy(string $url, ?string $reason, array $ips): void
	{
		$policy = new UrlSafetyPolicy(static fn (string $host): array => $ips);
		$result = $policy->check($url, 'example.pl');

		self::assertSame($reason, $result['reason']);
		self::assertSame($reason === null, $result['allowed']);
	}

	public function test_redirect_targets_are_resolved_for_a_new_check(): void
	{
		self::assertSame('https://example.pl/nowa/', UrlSafetyPolicy::redirectTarget('https://example.pl/stara/strona', '/nowa/'));
		self::assertSame('https://example.pl/stara/inna', UrlSafetyPolicy::redirectTarget('https://example.pl/stara/strona', 'inna'));
		self::assertSame('https://evil.example/', UrlSafetyPolicy::redirectTarget('https://example.pl/', '//evil.example/'));
		self::assertSame('http://169.254.169.254/latest/meta-data/', UrlSafetyPolicy::redirectTarget('https://example.pl/', 'http://169.254.169.254/latest/meta-data/'));
		self::assertNull(UrlSafetyPolicy::redirectTarget('https://example.pl/', "https://example.pl/\r\nX-Injected: 1"));

		$policy = new UrlSafetyPolicy(static fn (string $host): array => ['93.184.216.34']);
		self::assertSame('outside_project', $policy->check((string) UrlSafetyPolicy::redirectTarget('https://example.pl/', '//evil.example/'), 'example.pl')['reason']);
		self::assertSame('ip_literal_not_allowed', $policy->check((string) UrlSafetyPolicy::redirectTarget('https://example.pl/', 'http://169.254.169.254/latest/meta-data/'), 'example.pl')['reason']);
		self::assertLessThanOrEqual(5, UrlSafetyPolicy::MAX_REDIRECTS);
	}
}
