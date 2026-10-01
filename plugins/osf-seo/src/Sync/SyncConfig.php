<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Gsc\Dataset;
use OsfSeo\Support\Config;

/**
 * Parametry synchronizacji (docs/ARCHITECTURE.md, sekcja 9). Część można zmienić stałymi
 * w wp-config.php albo zmiennymi środowiskowymi (wartości spoza zakresu są przycinane).
 */
final class SyncConfig
{
	/** Historia GSC (Google udostępnia ok. 16 miesięcy; nie zakładamy, że to stała gwarancja). */
	public const HISTORY_MONTHS = 16;

	/** Okno kroczące odświeżania ostatnich dni (dane GSC dopracowują się po pierwszym pojawieniu). */
	public const REFRESH_DAYS = 7;

	/** Odświeżanie „codzienne”: nie częściej niż co tyle godzin. */
	public const REFRESH_INTERVAL_HOURS = 20;

	/** Cel liczby wierszy na jedno zadanie (≈ 2 strony API) — z niego wynika szerokość okna dat. */
	public const TARGET_ROWS_PER_JOB = 50000;

	/** Ponowienia zadania (s) po błędach przejściowych: 1 min, 5 min, 30 min, 2 h; 5. błąd → failed. */
	public const RETRY_DELAYS = [60, 300, 1800, 7200];

	/** Przerwa planowania datasetu po wyczerpaniu ponowień / po błędzie trwałym. */
	public const COOLDOWN_AFTER_RETRIES = 6 * 3600;

	public const COOLDOWN_AFTER_PERMANENT_ERROR = 24 * 3600;

	/** „Synchronizuj teraz” — minimalny odstęp dla projektu. */
	public const MANUAL_COOLDOWN = 300;

	/** Lease zadania (s) — zadanie dłużej „running” bez żywego runnera wraca do kolejki. */
	public const JOB_LEASE = 900;

	/** Budżet czasu jednego uruchomienia kolejki z WP-Cron i limit zadań. */
	public const TIME_BUDGET = 20;

	public const MAX_JOBS_PER_RUN = 25;

	/** Planowanie wszystkich projektów w ticku nie częściej niż co tyle sekund. */
	public const PLAN_INTERVAL = 300;

	/** Retencja historii zadań. */
	public const RUN_RETENTION_DAYS = 90;

	/** Dni „ostatnich” danych — backfill tego zakresu ma wyższy priorytet (2 × 28 dni porównań). */
	public const RECENT_DAYS = 56;

	public function __construct(private readonly Config $config = new Config())
	{
	}

	public function historyMonths(): int
	{
		return $this->int('OSF_SEO_GSC_HISTORY_MONTHS', self::HISTORY_MONTHS, 1, 16);
	}

	public function refreshDays(): int
	{
		return $this->int('OSF_SEO_SYNC_REFRESH_DAYS', self::REFRESH_DAYS, 3, 30);
	}

	public function timeBudget(): int
	{
		return $this->int('OSF_SEO_SYNC_TIME_BUDGET', self::TIME_BUDGET, 5, 300);
	}

	/** Domyślna i maksymalna szerokość okna (dni) datasetu z frazami. */
	public static function defaultWindowDays(Dataset $dataset): int
	{
		return $dataset === Dataset::QueryPage ? 3 : 7;
	}

	public static function maxWindowDays(Dataset $dataset): int
	{
		return $dataset === Dataset::QueryPage ? 14 : 31;
	}

	/**
	 * Szerokość okna z gęstości danych (wiersze/dzień z ostatniego udanego importu): duże serwisy — wąskie okna,
	 * małe — szerokie (mniej zadań). Bez pomiaru — wartość domyślna.
	 */
	public static function windowDays(Dataset $dataset, ?float $rowsPerDay): int
	{
		if ($rowsPerDay === null || $rowsPerDay <= 0) {
			return self::defaultWindowDays($dataset);
		}

		return max(1, min(self::maxWindowDays($dataset), (int) floor(self::TARGET_ROWS_PER_JOB / $rowsPerDay)));
	}

	/** Opóźnienie (s) przed próbą numer $attempt + 1; null = koniec ponowień. */
	public static function retryDelay(int $attempt, ?int $retryAfter = null): ?int
	{
		$delay = self::RETRY_DELAYS[$attempt - 1] ?? null;

		if ($delay === null) {
			return null;
		}

		return $retryAfter !== null ? min(max($delay, $retryAfter), self::COOLDOWN_AFTER_RETRIES) : $delay;
	}

	public static function maxAttempts(): int
	{
		return count(self::RETRY_DELAYS) + 1;
	}

	private function int(string $name, int $default, int $min, int $max): int
	{
		$value = $this->config->get($name);

		if ($value === null || preg_match('/^\d+$/', trim($value)) !== 1) {
			return $default;
		}

		return max($min, min($max, (int) $value));
	}
}
