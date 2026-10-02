<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Support\Config;

/**
 * Konfiguracja Luk SEO (STEP 15): stałe w wp-config.php lub zmienne środowiskowe (wartości przycinane do zakresów)
 * oraz progi heurystyk w jednym miejscu (docs/ARCHITECTURE.md, sekcja 14). Limity kosztów są wspólne z danymi rynkowymi
 * (`OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT` itd.) — tu nie ma drugiego budżetu.
 */
final class GapConfig
{
	public const TTL_DAYS = 'OSF_SEO_GAP_TTL_DAYS';

	public const MAX_REQUESTS_PER_TICK = 'OSF_SEO_GAP_MAX_REQUESTS_PER_TICK';

	public const WINDOW_DAYS = 'OSF_SEO_GAP_WINDOW_DAYS';

	public const MIN_IMPRESSIONS = 'OSF_SEO_GAP_MIN_IMPRESSIONS';

	public const VISIBLE_POSITION = 'OSF_SEO_GAP_VISIBLE_POSITION';

	public const VISIBLE_SHARE = 'OSF_SEO_GAP_VISIBLE_SHARE';

	public const SERP_FRESH_DAYS = 'OSF_SEO_GAP_SERP_FRESH_DAYS';

	public const MAX_CLUSTER_KEYWORDS = 'OSF_SEO_GAP_MAX_CLUSTER_KEYWORDS';

	/** Zakres pobierania: najgorsza pozycja konkurenta (filtr dostawcy). */
	public const FETCH_RANKS = [10, 20, 30, 50, 100];

	/** Próg „znaczącej” pozycji konkurenta (fraza jest kandydatem luki). */
	public const COMPETITOR_RANKS = [10, 20, 30, 50];

	public const DEFAULT_FETCH_MAX_RANK = 30;

	public const DEFAULT_FETCH_MIN_VOLUME = 10;

	public const DEFAULT_MAX_ROWS = 10000;

	public const DEFAULT_COMPETITOR_MAX_RANK = 20;

	public const DEFAULT_MIN_VOLUME = 10;

	/** Punkt odniesienia projektu: pełne TOP100 (słaba widoczność projektu to pozycje 11–100). */
	public const BASELINE_MAX_RANK = 100;

	/** Presety zakresu (Szybki / Standard / Pełny): najgorsza pozycja, minimalny wolumen, maks. fraz na domenę. */
	public const PRESETS = [
		'quick' => [10, 50, 2000],
		'standard' => [30, 10, 10000],
		'full' => [100, 0, 10000],
	];

	/** Projekt słabszy o co najmniej tyle pozycji od najlepszego konkurenta (poza TOP 10) = „Słaba widoczność”. */
	public const WEAK_DELTA = 5;

	/** Zdarzenia zmiany pozycji: co najmniej tyle pozycji albo zmiana przedziału TOP. */
	public const EVENT_MIN_DELTA = 5;

	/** Przedziały TOP (zdarzenia, statystyki importu). */
	public const BANDS = [3, 10, 20, 50, 100];

	/** Minimalny odstęp ręcznych uruchomień w projekcie (s) — podwójne kliknięcie ≠ podwójna opłata. */
	public const MANUAL_COOLDOWN = 60;

	/** Ponowienia strony po jawnym limicie żądań dostawcy (nic nie zostało wykonane ani opłacone). */
	public const RATE_LIMIT_ATTEMPTS = 3;

	public const RATE_LIMIT_RETRY = 300;

	/** Po ilu godzinach żądanie „w trakcie” uznajemy za przerwane (proces padł) — bez automatycznego ponowienia. */
	public const INTERRUPTED_HOURS = 1;

	/** Przebieg wstrzymany limitem kosztów dłużej niż tyle dni kończy się jako częściowy. */
	public const PAUSED_EXPIRE_DAYS = 7;

	/** Luka treści: udział wyświetleń strony w wyświetleniach grupy, od którego strona jest docelowa (GSC). */
	public const TARGET_SHARE = 0.6;

	/** Druga strona z co najmniej takim udziałem przy braku dominującej = strony rozproszone. */
	public const SCATTER_SHARE = 0.2;

	/** Strona projektu pasuje adresem, gdy slug pokrywa co najmniej taki udział wyrazów frazy wiodącej. */
	public const SLUG_COVERAGE = 0.6;

	/** Strona konkurenta jest „dedykowana”, gdy slug pokrywa co najmniej taki udział wyrazów frazy. */
	public const DEDICATED_SLUG_COVERAGE = 0.5;

	/** Minimalny wolumen luk grupy, przy którym brak strony docelowej jest „Potencjalną luką treści”. */
	public const MIN_CONTENT_GAP_VOLUME = 50;

	/** Adres-hub (np. kategoria zbiorcza) nie łączy fraz w grupy: ponad tyle fraz albo taki udział fraz konkurenta. */
	public const HUB_MIN_KEYWORDS = 300;

	public const HUB_SHARE = 0.2;

	/** Wspólne adresy w TOP10 naszych migawek SERP (STEP 14), od których frazy monitorowane należą do jednej grupy. */
	public const SERP_OVERLAP_URLS = 4;

	public function __construct(private readonly Config $config = new Config())
	{
	}

	/** Zbiór fraz domeny jest świeży przez tyle dni (Labs: zbiór co tydzień, SERP-y fraz co 30–90 dni). */
	public function ttlDays(): int
	{
		return $this->int(self::TTL_DAYS, 30, 7, 180);
	}

	/** Najwięcej płatnych żądań w jednym kroku tła (kolejne w następnych krokach). */
	public function maxRequestsPerTick(): int
	{
		return $this->int(self::MAX_REQUESTS_PER_TICK, 10, 1, 100);
	}

	/** Okno danych GSC do widoczności projektu (dni do ostatniej zaimportowanej daty). */
	public function windowDays(): int
	{
		return $this->int(self::WINDOW_DAYS, 90, 28, 480);
	}

	public function minImpressions(): int
	{
		return $this->int(self::MIN_IMPRESSIONS, 10, 1, 100000);
	}

	public function visiblePosition(): float
	{
		return $this->float(self::VISIBLE_POSITION, 10.0, 1.0, 100.0);
	}

	public function visibleShare(): float
	{
		return $this->float(self::VISIBLE_SHARE, 0.1, 0.0, 1.0);
	}

	/** Pomiar SERP (STEP 14) jest dowodem widoczności projektu, jeśli nie jest starszy niż tyle dni. */
	public function serpFreshDays(): int
	{
		return $this->int(self::SERP_FRESH_DAYS, 30, 1, 180);
	}

	/** Najwięcej fraz luk projektu grupowanych w klastry (wg priorytetu); reszta pozostaje bez grupy. */
	public function maxClusterKeywords(): int
	{
		return $this->int(self::MAX_CLUSTER_KEYWORDS, 20000, 100, 100000);
	}

	/**
	 * @return array<string, int|float>
	 */
	public function effective(): array
	{
		return [
			'ttl_days' => $this->ttlDays(),
			'max_requests_per_tick' => $this->maxRequestsPerTick(),
			'window_days' => $this->windowDays(),
			'min_impressions' => $this->minImpressions(),
			'visible_position' => $this->visiblePosition(),
			'visible_share' => $this->visibleShare(),
			'serp_fresh_days' => $this->serpFreshDays(),
			'max_cluster_keywords' => $this->maxClusterKeywords(),
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
