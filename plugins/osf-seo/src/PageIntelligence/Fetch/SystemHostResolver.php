<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Fetch;

/**
 * Resolver systemowy: IPv4 przez `gethostbynamel` (resolver systemu, także /etc/hosts), IPv6 z rekordów AAAA.
 */
final class SystemHostResolver implements HostResolver
{
	public function resolve(string $host): array
	{
		$ips = [];

		foreach (gethostbynamel($host) ?: [] as $ip) {
			$ips[] = $ip;
		}

		foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
			if (is_array($record) && is_string($record['ipv6'] ?? null)) {
				$ips[] = $record['ipv6'];
			}
		}

		return array_values(array_unique($ips));
	}
}
