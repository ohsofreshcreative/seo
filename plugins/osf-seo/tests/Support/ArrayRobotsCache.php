<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use OsfSeo\PageIntelligence\Robots\RobotsCache;

/** Pamięć robots.txt w testach (bez transientów). */
final class ArrayRobotsCache implements RobotsCache
{
	/** @var array<string, array<string, mixed>> */
	public array $items = [];

	public function get(string $key): ?array
	{
		return $this->items[$key] ?? null;
	}

	public function set(string $key, array $value, int $ttl): void
	{
		$this->items[$key] = $value;
	}
}
