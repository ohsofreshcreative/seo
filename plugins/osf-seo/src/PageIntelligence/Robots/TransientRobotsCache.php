<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Robots;

/** Reguły robots.txt w transientach (publiczne dane witryny, nie dane projektu; wygasają). */
final class TransientRobotsCache implements RobotsCache
{
	public function get(string $key): ?array
	{
		$value = get_transient('osf_seo_robots_' . md5($key));

		return is_array($value) ? $value : null;
	}

	public function set(string $key, array $value, int $ttl): void
	{
		set_transient('osf_seo_robots_' . md5($key), $value, $ttl);
	}
}
