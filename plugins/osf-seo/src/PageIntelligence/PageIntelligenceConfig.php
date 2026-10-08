<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

use OsfSeo\Plugin;
use OsfSeo\Support\Config;

/**
 * Konfiguracja Page Intelligence (STEP 17, faza B — docs/ARCHITECTURE.md, sekcja 23.9): wyłącznie stałe w wp-config.php albo zmienne
 * środowiskowe, konserwatywne wartości domyślne i twarde granice (wartości spoza zakresu są przycinane).
 */
final class PageIntelligenceConfig
{
	public const PROJECT_ENABLED = 'OSF_SEO_PAGES_PROJECT_ENABLED';

	public const COMPETITORS_ENABLED = 'OSF_SEO_PAGES_COMPETITORS_ENABLED';

	public const TTL_HOURS = 'OSF_SEO_PAGES_TTL_HOURS';

	public const MAX_BYTES = 'OSF_SEO_PAGES_MAX_BYTES';

	public const TIMEOUT = 'OSF_SEO_PAGES_TIMEOUT';

	public const MAX_REDIRECTS = 'OSF_SEO_PAGES_MAX_REDIRECTS';

	public const MAX_URLS = 'OSF_SEO_PAGES_MAX_URLS';

	public const DOMAIN_INTERVAL = 'OSF_SEO_PAGES_DOMAIN_INTERVAL';

	public const DOMAIN_DAILY_LIMIT = 'OSF_SEO_PAGES_DOMAIN_DAILY_LIMIT';

	public const RETENTION_DAYS = 'OSF_SEO_PAGES_RETENTION_DAYS';

	public const MAX_SNAPSHOTS = 'OSF_SEO_PAGES_MAX_SNAPSHOTS';

	public const CA_BUNDLE = 'OSF_SEO_PAGES_CA_BUNDLE';

	public const CONNECT_TIMEOUT = 5;

	public function __construct(private readonly Config $config = new Config())
	{
	}

	/** Pobieranie stron projektu (domyślnie włączone; zawsze jawną akcją administratora). */
	public function projectEnabled(): bool
	{
		return $this->flag(self::PROJECT_ENABLED, true);
	}

	/** Pobieranie stron konkurencji z wyników SERP — wyłączane niezależnie od stron projektu. */
	public function competitorsEnabled(): bool
	{
		return $this->flag(self::COMPETITORS_ENABLED, true);
	}

	/** Snapshot świeży przez tyle godzin (ponowne pobranie wcześniej tylko z `force`). */
	public function ttlHours(): int
	{
		return $this->int(self::TTL_HOURS, 24, 1, 720);
	}

	public function maxBytes(): int
	{
		return $this->int(self::MAX_BYTES, 2 * 1024 * 1024, 65536, 5 * 1024 * 1024);
	}

	/** Całkowity limit czasu pobrania (cały łańcuch przekierowań). */
	public function timeout(): int
	{
		return $this->int(self::TIMEOUT, 15, 3, 60);
	}

	public function connectTimeout(): int
	{
		return min(self::CONNECT_TIMEOUT, $this->timeout());
	}

	public function maxRedirects(): int
	{
		return $this->int(self::MAX_REDIRECTS, 3, 0, 5);
	}

	/** Najwięcej adresów w jednym zleceniu pobrania. */
	public function maxUrls(): int
	{
		return $this->int(self::MAX_URLS, 5, 1, 10);
	}

	/** Odstęp między żądaniami do tego samego hosta (wszystkie projekty), sekundy. */
	public function domainInterval(): int
	{
		return $this->int(self::DOMAIN_INTERVAL, 10, 2, 3600);
	}

	/** Najwięcej pobrań z jednego hosta na dobę (wszystkie projekty). */
	public function domainDailyLimit(): int
	{
		return $this->int(self::DOMAIN_DAILY_LIMIT, 30, 1, 500);
	}

	/** Retencja zapisanych treści stron (snapshotów) i dziennika prób. */
	public function retentionDays(): int
	{
		return $this->int(self::RETENTION_DAYS, 90, 7, 730);
	}

	/** Najwięcej snapshotów jednej strony (starsze usuwane). */
	public function maxSnapshots(): int
	{
		return $this->int(self::MAX_SNAPSHOTS, 5, 1, 50);
	}

	/** Magazyn zaufanych CA: stała, inaczej pakiet WordPressa, inaczej domyślny curl. */
	public function caBundle(): ?string
	{
		$configured = trim((string) $this->config->get(self::CA_BUNDLE, ''));

		if ($configured !== '') {
			return $configured;
		}

		if (defined('ABSPATH') && defined('WPINC')) {
			$bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';

			return is_file($bundle) ? $bundle : null;
		}

		return null;
	}

	public function userAgent(): string
	{
		$home = function_exists('home_url') ? (string) home_url('/') : '';

		return 'Whack-a-mole/' . Plugin::VERSION . ' (Page Intelligence' . ($home !== '' ? '; +' . $home : '') . ')';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function effective(): array
	{
		return [
			'project_enabled' => $this->projectEnabled(),
			'competitors_enabled' => $this->competitorsEnabled(),
			'ttl_hours' => $this->ttlHours(),
			'max_bytes' => $this->maxBytes(),
			'timeout' => $this->timeout(),
			'connect_timeout' => $this->connectTimeout(),
			'max_redirects' => $this->maxRedirects(),
			'max_urls' => $this->maxUrls(),
			'domain_interval' => $this->domainInterval(),
			'domain_daily_limit' => $this->domainDailyLimit(),
			'retention_days' => $this->retentionDays(),
			'max_snapshots' => $this->maxSnapshots(),
			'user_agent' => $this->userAgent(),
		];
	}

	private function flag(string $name, bool $default): bool
	{
		$value = strtolower(trim((string) $this->config->get($name, '')));

		return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
	}

	private function int(string $name, int $default, int $min, int $max): int
	{
		$value = trim((string) $this->config->get($name, ''));

		return preg_match('/^\d+$/', $value) === 1 ? max($min, min($max, (int) $value)) : $default;
	}
}
