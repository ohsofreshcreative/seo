<?php

declare(strict_types=1);

namespace OsfSeo;

use LogicException;

/**
 * Minimalny kontener usług: leniwe singletony tworzone przez fabryki.
 * Celowo bez autowiringu — zależności są jawnie opisane w Plugin::createContainer().
 */
final class Container
{
	/** @var array<string, callable(self): object> */
	private array $factories = [];

	/** @var array<string, object> */
	private array $instances = [];

	/** @var array<string, true> */
	private array $resolving = [];

	/**
	 * @param callable(self): object $factory
	 */
	public function singleton(string $id, callable $factory): void
	{
		$this->factories[$id] = $factory;
		unset($this->instances[$id]);
	}

	public function has(string $id): bool
	{
		return isset($this->factories[$id]);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get(string $id): object
	{
		if (isset($this->instances[$id])) {
			return $this->instances[$id];
		}

		if (! isset($this->factories[$id])) {
			throw new ServiceNotFound(sprintf('Service "%s" is not registered in the OSF SEO container.', $id));
		}

		if (isset($this->resolving[$id])) {
			throw new LogicException(sprintf('Circular dependency while resolving service "%s".', $id));
		}

		$this->resolving[$id] = true;

		try {
			$instance = ($this->factories[$id])($this);
		} finally {
			unset($this->resolving[$id]);
		}

		return $this->instances[$id] = $instance;
	}
}
