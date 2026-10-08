<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Provider;

/**
 * Rejestr dostawców AI (identyfikator → adapter). Nieznany identyfikator → null (wywołujący odmawia: `provider_unknown`).
 */
final class AiProviderRegistry
{
	/** @var array<string, AiProvider> */
	private array $providers = [];

	/**
	 * @param iterable<AiProvider> $providers
	 */
	public function __construct(iterable $providers)
	{
		foreach ($providers as $provider) {
			$this->providers[$provider->id()] = $provider;
		}

		ksort($this->providers);
	}

	public function get(string $id): ?AiProvider
	{
		return $this->providers[strtolower(trim($id))] ?? null;
	}

	/**
	 * @return list<string>
	 */
	public function ids(): array
	{
		return array_keys($this->providers);
	}

	/**
	 * @return list<AiProvider>
	 */
	public function all(): array
	{
		return array_values($this->providers);
	}
}
