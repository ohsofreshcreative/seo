<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Support\Config;

/**
 * Ustawienia śledzenia pozycji SERP (stałe w wp-config.php lub zmienne środowiskowe; wartości spoza zakresu są przycinane).
 * Limity kosztów są wspólne z danymi rynkowymi i wyszukiwaniem fraz (`OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT` itd.).
 */
final class SerpConfig
{
	/** Miękki limit monitorowanych fraz w projekcie (komunikat walidacji, bez obcinania). */
	public const MAX_KEYWORDS = 'OSF_SEO_SERP_MAX_KEYWORDS';

	/** Fraza nie jest sprawdzana ponownie, jeśli pomiar zlecono w ciągu tylu godzin (nakładanie się crona i ręcznych pomiarów). */
	public const MIN_RECHECK_HOURS = 'OSF_SEO_SERP_MIN_RECHECK_HOURS';

	/** Płatne POST-y (po maks. 100 zadań) w jednym przebiegu tła — przebieg pomiaru jest wznawiany w kolejnych. */
	public const MAX_POSTS_PER_RUN = 'OSF_SEO_SERP_MAX_POSTS_PER_RUN';

	/** Bezpłatne odbiory wyników (`task_get`) w jednym przebiegu tła. */
	public const COLLECT_PER_RUN = 'OSF_SEO_SERP_COLLECT_PER_RUN';

	/** Po ilu godzinach nieodebrane zadanie uznajemy za wygasłe. */
	public const EXPIRE_HOURS = 'OSF_SEO_SERP_EXPIRE_HOURS';

	public const DEFAULT_MAX_KEYWORDS = 500;

	/** Głębokości do wyboru (wyniki organiczne). */
	public const DEPTHS = [10, 20, 50, 100];

	public const DEFAULT_DEPTH = 100;

	/** Maks. zadań w jednym POST (limit DataForSEO). */
	public const TASKS_PER_POST = 100;

	/** Minimalny odstęp ręcznych pomiarów projektu (s). */
	public const MANUAL_COOLDOWN = 900;

	/** Okres GSC do porównania ze średnią pozycją (dni do ostatniej zaimportowanej daty). */
	public const GSC_DAYS = 28;

	public function __construct(private readonly Config $config = new Config())
	{
	}

	public function maxKeywords(): int
	{
		return $this->int(self::MAX_KEYWORDS, self::DEFAULT_MAX_KEYWORDS, 1, 1000000);
	}

	public function minRecheckHours(): int
	{
		return $this->int(self::MIN_RECHECK_HOURS, 6, 1, 168);
	}

	public function maxPostsPerRun(): int
	{
		return $this->int(self::MAX_POSTS_PER_RUN, 20, 1, 500);
	}

	public function collectPerRun(): int
	{
		return $this->int(self::COLLECT_PER_RUN, 200, 1, 10000);
	}

	public function expireHours(): int
	{
		return $this->int(self::EXPIRE_HOURS, 72, 24, 720);
	}

	/**
	 * @return array<string, int>
	 */
	public function effective(): array
	{
		return [
			'max_keywords' => $this->maxKeywords(),
			'min_recheck_hours' => $this->minRecheckHours(),
			'max_posts_per_run' => $this->maxPostsPerRun(),
			'collect_per_run' => $this->collectPerRun(),
			'expire_hours' => $this->expireHours(),
		];
	}

	public static function validDepth(int $depth): bool
	{
		return in_array($depth, self::DEPTHS, true);
	}

	private function int(string $name, int $default, int $min, int $max): int
	{
		$value = $this->config->get($name);

		if ($value === null || ! is_numeric(trim($value))) {
			return $default;
		}

		return max($min, min($max, (int) round((float) $value)));
	}
}
