<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use Closure;
use OsfSeo\PageIntelligence\Fetch\HostResolver;
use OsfSeo\PageIntelligence\Fetch\NetworkPolicy;
use OsfSeo\PageIntelligence\Fetch\UrlSafetyPolicy;

/**
 * Sieć testów transportu: polityka traktuje WYŁĄCZNIE 127.0.0.1 jak adres publiczny (kontrolowany serwer testowy) i dopuszcza tylko porty
 * serwerów testowych; każdy inny adres ocenia polityka produkcyjna (127.0.0.2, prywatne, metadane — zablokowane). Resolver mapuje nazwy
 * testowe na adresy (także sekwencje — DNS rebinding) i liczy zapytania.
 */
final class FixtureNetwork implements NetworkPolicy, HostResolver
{
	/** @var array<string, int> */
	public array $lookups = [];

	/**
	 * @param array<string, list<string>|Closure(int): list<string>> $hosts host → adresy albo funkcja numeru zapytania
	 * @param list<int> $ports
	 */
	public function __construct(private array $hosts, private readonly array $ports)
	{
	}

	public function addressReason(string $ip): ?string
	{
		return $ip === '127.0.0.1' ? null : UrlSafetyPolicy::ipReason($ip);
	}

	public function allowedPorts(): array
	{
		return $this->ports;
	}

	public function resolve(string $host): array
	{
		$count = $this->lookups[$host] = ($this->lookups[$host] ?? 0) + 1;
		$entry = $this->hosts[$host] ?? [];

		return $entry instanceof Closure ? $entry($count) : $entry;
	}

	/**
	 * @param list<string>|Closure(int): list<string> $addresses
	 */
	public function map(string $host, array|Closure $addresses): void
	{
		$this->hosts[$host] = $addresses;
	}
}
