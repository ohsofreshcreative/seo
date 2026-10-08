<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Robots;

/** Pamięć reguł robots.txt per origin (produkcyjnie transienty WordPressa). */
interface RobotsCache
{
	/**
	 * @return array<string, mixed>|null
	 */
	public function get(string $key): ?array;

	/**
	 * @param array<string, mixed> $value
	 */
	public function set(string $key, array $value, int $ttl): void;
}
