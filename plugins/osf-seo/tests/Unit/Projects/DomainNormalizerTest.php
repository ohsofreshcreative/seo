<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Projects;

use InvalidArgumentException;
use OsfSeo\Projects\DomainNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainNormalizerTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function validDomains(): iterable
	{
		yield 'plain' => ['example.pl', 'example.pl'];
		yield 'uppercase and spaces' => ['  OhSoFresh.PL ', 'ohsofresh.pl'];
		yield 'url with path and query' => ['https://www.example.pl/strony-internetowe/?utm=1#x', 'example.pl'];
		yield 'www without scheme' => ['www.example.com.pl', 'example.com.pl'];
		yield 'subdomain kept' => ['blog.example.pl', 'blog.example.pl'];
		yield 'port and credentials' => ['http://user:pass@example.pl:8080/path', 'example.pl'];
		yield 'trailing dot' => ['example.pl.', 'example.pl'];
		yield 'gsc domain property' => ['sc-domain:example.pl', 'example.pl'];
		yield 'url-prefix property' => ['https://example.pl/', 'example.pl'];
		yield 'idn' => ['zażółć.pl', 'xn--zaó-ixa1a1z.pl'];
	}

	#[DataProvider('validDomains')]
	public function test_normalizes_valid_domains(string $input, string $expected): void
	{
		if (str_starts_with($expected, 'xn--')) {
			if (! function_exists('idn_to_ascii')) {
				self::markTestSkipped('Rozszerzenie intl niedostępne.');
			}

			$expected = (string) idn_to_ascii('zażółć', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) . '.pl';
		}

		self::assertSame($expected, DomainNormalizer::normalize($input));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidDomains(): iterable
	{
		yield 'empty' => [''];
		yield 'single label' => ['localhost'];
		yield 'ipv4' => ['192.168.1.10'];
		yield 'ipv4 url' => ['http://10.0.0.1/admin'];
		yield 'spaces inside' => ['exa mple.pl'];
		yield 'underscore' => ['exa_mple.pl'];
		yield 'leading hyphen' => ['-example.pl'];
		yield 'html' => ['<script>.pl'];
		yield 'too long label' => [str_repeat('a', 64) . '.pl'];
		yield 'numeric tld' => ['example.123'];
	}

	#[DataProvider('invalidDomains')]
	public function test_rejects_invalid_domains(string $input): void
	{
		$this->expectException(InvalidArgumentException::class);

		DomainNormalizer::normalize($input);
	}
}
