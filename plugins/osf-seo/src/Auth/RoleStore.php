<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

/**
 * Dostęp do ról WordPressa. Oddzielony od RoleManager, żeby logikę synchronizacji
 * dało się testować jednostkowo bez WordPressa.
 */
interface RoleStore
{
	/**
	 * Uprawnienia roli (capability => przyznane) albo null, gdy rola nie istnieje.
	 *
	 * @return array<string, bool>|null
	 */
	public function capabilities(string $role): ?array;

	/**
	 * @param list<string> $capabilities
	 */
	public function create(string $role, string $label, array $capabilities): void;

	public function grant(string $role, string $capability): void;

	public function revoke(string $role, string $capability): void;
}
