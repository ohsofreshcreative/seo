<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use OsfSeo\Auth\RoleStore;

/**
 * Magazyn ról w pamięci — odwzorowuje zachowanie ról WordPressa na potrzeby testów jednostkowych.
 */
final class InMemoryRoleStore implements RoleStore
{
	/** @var array<string, array{label: string, capabilities: array<string, bool>}> */
	public array $roles = [];

	/**
	 * @param array<string, array<string, bool>> $roles
	 */
	public function __construct(array $roles = [])
	{
		foreach ($roles as $role => $capabilities) {
			$this->roles[$role] = ['label' => $role, 'capabilities' => $capabilities];
		}
	}

	public function capabilities(string $role): ?array
	{
		return $this->roles[$role]['capabilities'] ?? null;
	}

	public function create(string $role, string $label, array $capabilities): void
	{
		$this->roles[$role] = ['label' => $label, 'capabilities' => array_fill_keys($capabilities, true)];
	}

	public function grant(string $role, string $capability): void
	{
		if (isset($this->roles[$role])) {
			$this->roles[$role]['capabilities'][$capability] = true;
		}
	}

	public function revoke(string $role, string $capability): void
	{
		unset($this->roles[$role]['capabilities'][$capability]);
	}
}
