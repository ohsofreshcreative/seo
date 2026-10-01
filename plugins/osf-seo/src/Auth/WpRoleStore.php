<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

final class WpRoleStore implements RoleStore
{
	public function capabilities(string $role): ?array
	{
		$wpRole = get_role($role);

		if ($wpRole === null) {
			return null;
		}

		return array_map(static fn (mixed $granted): bool => (bool) $granted, $wpRole->capabilities);
	}

	public function create(string $role, string $label, array $capabilities): void
	{
		add_role($role, $label, array_fill_keys($capabilities, true));
	}

	public function grant(string $role, string $capability): void
	{
		get_role($role)?->add_cap($capability, true);
	}

	public function revoke(string $role, string $capability): void
	{
		get_role($role)?->remove_cap($capability);
	}
}
