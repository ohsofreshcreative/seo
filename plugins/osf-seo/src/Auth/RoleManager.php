<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

/**
 * Utrzymuje role i uprawnienia zgodne z definicją w kodzie (Roles, Capabilities):
 *
 * - role osf_seo_* mają dokładnie zdefiniowany zestaw uprawnień — brakujące są dodawane,
 *   nadmiarowe usuwane (definicja w kodzie jest źródłem prawdy, least privilege),
 * - administrator WordPressa dostaje wszystkie uprawnienia aplikacji; jego pozostałe
 *   uprawnienia zostają nietknięte.
 *
 * Niczego nie usuwa poza nadmiarowymi uprawnieniami ról pluginu — ról nie kasuje.
 */
final class RoleManager
{
	public function __construct(private readonly RoleStore $store)
	{
	}

	/**
	 * Doprowadza role do stanu z definicji.
	 *
	 * @return array{created: list<string>, granted: list<string>, revoked: list<string>}
	 */
	public function sync(): array
	{
		$changes = ['created' => [], 'granted' => [], 'revoked' => []];

		foreach ($this->pendingOperations() as $operation) {
			switch ($operation['type']) {
				case 'create':
					$definition = Roles::definitions()[$operation['role']];
					$this->store->create($operation['role'], $definition['label'], $definition['capabilities']);
					$changes['created'][] = $operation['role'];
					break;

				case 'grant':
					$this->store->grant($operation['role'], $operation['capability']);
					$changes['granted'][] = $operation['role'] . ':' . $operation['capability'];
					break;

				case 'revoke':
					$this->store->revoke($operation['role'], $operation['capability']);
					$changes['revoked'][] = $operation['role'] . ':' . $operation['capability'];
					break;
			}
		}

		return $changes;
	}

	/**
	 * Rozbieżności między stanem ról a definicją; pusta lista oznacza pełną zgodność.
	 *
	 * @return list<string>
	 */
	public function problems(): array
	{
		return array_map(static fn (array $operation): string => match ($operation['type']) {
			'create' => sprintf('missing role %s', $operation['role']),
			'grant' => sprintf('role %s lacks %s', $operation['role'], $operation['capability']),
			'revoke' => sprintf('role %s has unexpected %s', $operation['role'], $operation['capability']),
		}, $this->pendingOperations());
	}

	/**
	 * @return list<array{type: 'create', role: string}|array{type: 'grant'|'revoke', role: string, capability: string}>
	 */
	private function pendingOperations(): array
	{
		$operations = [];

		foreach (Roles::definitions() as $role => $definition) {
			$current = $this->store->capabilities($role);

			if ($current === null) {
				$operations[] = ['type' => 'create', 'role' => $role];

				continue;
			}

			foreach ($definition['capabilities'] as $capability) {
				if (($current[$capability] ?? false) !== true) {
					$operations[] = ['type' => 'grant', 'role' => $role, 'capability' => $capability];
				}
			}

			foreach (array_keys($current) as $capability) {
				if (! in_array($capability, $definition['capabilities'], true)) {
					$operations[] = ['type' => 'revoke', 'role' => $role, 'capability' => $capability];
				}
			}
		}

		$administrator = $this->store->capabilities(Roles::WP_ADMINISTRATOR);

		if ($administrator !== null) {
			foreach (Capabilities::all() as $capability) {
				if (($administrator[$capability] ?? false) !== true) {
					$operations[] = ['type' => 'grant', 'role' => Roles::WP_ADMINISTRATOR, 'capability' => $capability];
				}
			}
		}

		return $operations;
	}
}
