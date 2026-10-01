<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Support\Config;

/**
 * Ustawienia wyszukiwania nowych fraz (stałe w wp-config.php lub zmienne środowiskowe; wartości spoza zakresu są przycinane).
 * Limity kosztów są wspólne z danymi rynkowymi (STEP 12: `OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT` itd.) — tu tylko zakres
 * przebiegu wyszukiwania i reguły klasyfikacji.
 */
final class DiscoveryConfig
{
	public const TTL_DAYS = 'OSF_SEO_DISCOVERY_TTL_DAYS';

	public const MAX_SEEDS = 'OSF_SEO_DISCOVERY_MAX_SEEDS';

	public const MAX_CANDIDATES = 'OSF_SEO_DISCOVERY_MAX_CANDIDATES';

	public const MIN_VOLUME = 'OSF_SEO_DISCOVERY_MIN_VOLUME';

	public const WINDOW_DAYS = 'OSF_SEO_DISCOVERY_WINDOW_DAYS';

	public const MIN_IMPRESSIONS = 'OSF_SEO_DISCOVERY_MIN_IMPRESSIONS';

	public const VISIBLE_POSITION = 'OSF_SEO_DISCOVERY_VISIBLE_POSITION';

	public const VISIBLE_SHARE = 'OSF_SEO_DISCOVERY_VISIBLE_SHARE';

	/** Limity kandydatów do wyboru w panelu (górną granicę przycina `maxCandidates()`). */
	public const CANDIDATE_OPTIONS = [100, 250, 500, 1000];

	public const DEFAULT_CANDIDATES = 250;

	public const DEFAULT_DEPTH = 2;

	/** Głębokości Related Keywords dostępne w panelu i CLI (4 = do ~4680 fraz na seed — celowo pominięta). */
	public const DEPTHS = [1, 2, 3];

	/** Maksymalna liczba stron (żądań) na seed — górna granica paginacji. */
	public const MAX_PAGES_PER_SEED = 5;

	/** Minimalny odstęp ręcznych uruchomień w projekcie (s) — podwójne kliknięcie ≠ podwójna opłata. */
	public const MANUAL_COOLDOWN = 60;

	/** Po ilu godzinach seed „w trakcie” uznajemy za przerwany (proces padł w trakcie żądania). */
	public const INTERRUPTED_HOURS = 1;

	/** Ponowienia seeda po jawnym limicie żądań dostawcy (nic nie zostało wykonane ani opłacone). */
	public const RATE_LIMIT_ATTEMPTS = 3;

	public function __construct(private readonly Config $config = new Config())
	{
	}

	/** Seed × metoda × rynek pobrany w projekcie w ciągu tylu dni nie jest pobierany ponownie (bez `force`). */
	public function ttlDays(): int
	{
		return $this->int(self::TTL_DAYS, 30, 1, 365);
	}

	public function maxSeeds(): int
	{
		return $this->int(self::MAX_SEEDS, 20, 1, 50);
	}

	/** Górna granica kandydatów (i zwracanych elementów) na przebieg. */
	public function maxCandidates(): int
	{
		return $this->int(self::MAX_CANDIDATES, 1000, 10, 5000);
	}

	/** Domyślny minimalny wolumen (filtr po stronie dostawcy). */
	public function minVolume(): int
	{
		return $this->int(self::MIN_VOLUME, 10, 0, 100000);
	}

	/** Okno danych GSC do klasyfikacji widoczności (dni do ostatniej zaimportowanej daty). */
	public function windowDays(): int
	{
		return $this->int(self::WINDOW_DAYS, 90, 28, 480);
	}

	/** Minimalna liczba wyświetleń GSC w oknie, od której fraza ma jakąkolwiek widoczność. */
	public function minImpressions(): int
	{
		return $this->int(self::MIN_IMPRESSIONS, 10, 1, 100000);
	}

	/** Średnia pozycja (GSC), do której fraza jest „już widoczna” (TOP 10). */
	public function visiblePosition(): float
	{
		return $this->float(self::VISIBLE_POSITION, 10.0, 1.0, 100.0);
	}

	/** Minimalny udział wyświetleń w wolumenie okresu, żeby pozycję ≤ progu uznać za stałą widoczność. */
	public function visibleShare(): float
	{
		return $this->float(self::VISIBLE_SHARE, 0.1, 0.0, 1.0);
	}

	/**
	 * Limity kandydatów do wyboru w panelu (w granicy `maxCandidates()`).
	 *
	 * @return list<int>
	 */
	public function candidateOptions(): array
	{
		$max = $this->maxCandidates();
		$options = array_values(array_filter(self::CANDIDATE_OPTIONS, static fn (int $option): bool => $option <= $max));

		return $options === [] ? [$max] : $options;
	}

	/**
	 * @return array<string, int|float>
	 */
	public function effective(): array
	{
		return [
			'ttl_days' => $this->ttlDays(),
			'max_seeds' => $this->maxSeeds(),
			'max_candidates' => $this->maxCandidates(),
			'min_volume' => $this->minVolume(),
			'window_days' => $this->windowDays(),
			'min_impressions' => $this->minImpressions(),
			'visible_position' => $this->visiblePosition(),
			'visible_share' => $this->visibleShare(),
		];
	}

	private function int(string $name, int $default, int $min, int $max): int
	{
		$value = $this->config->get($name);

		if ($value === null || ! is_numeric(trim($value))) {
			return $default;
		}

		return max($min, min($max, (int) round((float) $value)));
	}

	private function float(string $name, float $default, float $min, float $max): float
	{
		$value = $this->config->get($name);

		if ($value === null || ! is_numeric(trim($value))) {
			return $default;
		}

		return max($min, min($max, (float) $value));
	}
}
