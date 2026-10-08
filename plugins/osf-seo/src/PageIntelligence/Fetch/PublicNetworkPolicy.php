<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Fetch;

/**
 * Produkcyjna polityka sieci: wyłącznie publiczne adresy IP (bez sieci prywatnych, loopback, link-local i metadanych chmury, CGNAT,
 * multicast, zakresów zarezerwowanych i adresów IPv4 zagnieżdżonych w IPv6 — `UrlSafetyPolicy::ipReason`) na portach 80 i 443.
 */
final class PublicNetworkPolicy implements NetworkPolicy
{
	public const PORTS = [80, 443];

	public function addressReason(string $ip): ?string
	{
		return UrlSafetyPolicy::ipReason($ip);
	}

	public function allowedPorts(): array
	{
		return self::PORTS;
	}
}
