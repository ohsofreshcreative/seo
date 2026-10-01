<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use OsfSeo\Support\Config;

/**
 * Ustawienia danych rynkowych (stałe w wp-config.php lub zmienne środowiskowe; wartości spoza zakresu
 * są przycinane, nieprawidłowe — zastępowane domyślnymi). Domyślne wartości są celowo ostrożne:
 * płatne API nie może przez błąd wygenerować tysięcy zadań.
 */
final class MarketDataConfig
{
	public const VOLUME_TTL_DAYS = 'OSF_SEO_DATAFORSEO_VOLUME_TTL_DAYS';

	public const DIFFICULTY_TTL_DAYS = 'OSF_SEO_DATAFORSEO_DIFFICULTY_TTL_DAYS';

	public const MAX_TASKS_PER_RUN = 'OSF_SEO_DATAFORSEO_MAX_TASKS_PER_RUN';

	public const DAILY_COST_LIMIT = 'OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT';

	public const MONTHLY_COST_LIMIT = 'OSF_SEO_DATAFORSEO_MONTHLY_COST_LIMIT';

	public const MIN_IMPRESSIONS = 'OSF_SEO_DATAFORSEO_MIN_IMPRESSIONS';

	public const WINDOW_DAYS = 'OSF_SEO_DATAFORSEO_WINDOW_DAYS';

	public const SYNC_LIMIT = 'OSF_SEO_DATAFORSEO_SYNC_LIMIT';

	public const AUTO_REFRESH = 'OSF_SEO_DATAFORSEO_AUTO_REFRESH';

	/** Odstęp automatycznego sprawdzania nieaktualnych metryk projektu. */
	public const AUTO_INTERVAL_HOURS = 24;

	/** Minimalny odstęp ręcznych synchronizacji projektu (podwójne kliknięcie ≠ podwójna opłata). */
	public const MANUAL_COOLDOWN = 300;

	/** Ile czekamy na wynik zadania Standard, zanim uznamy je za przeterminowane (frazy wracają do kolejki). */
	public const PENDING_HOURS = 48;

	/** Przerwa automatyki po błędzie konta (logowanie, środki) albo błędzie trwałym. */
	public const PAUSE_AFTER_ACCOUNT_ERROR = 86400;

	/** Przerwa automatyki projektu po błędzie przejściowym. */
	public const PAUSE_AFTER_TRANSIENT_ERROR = 21600;

	public function __construct(private readonly Config $config = new Config())
	{
	}

	public function volumeTtlDays(): int
	{
		return $this->int(self::VOLUME_TTL_DAYS, 30, 7, 365);
	}

	public function difficultyTtlDays(): int
	{
		return $this->int(self::DIFFICULTY_TTL_DAYS, 30, 7, 365);
	}

	/** Twardy limit płatnych zadań (zleceń wolumenu + żądań trudności) w jednym przebiegu. */
	public function maxTasksPerRun(): int
	{
		return $this->int(self::MAX_TASKS_PER_RUN, 4, 1, 50);
	}

	/** Lokalny limit kosztów na dobę UTC (USD); 0 blokuje płatne wywołania. */
	public function dailyCostLimit(): float
	{
		return $this->float(self::DAILY_COST_LIMIT, 1.0, 0.0, 10000.0);
	}

	/** Lokalny limit kosztów na miesiąc kalendarzowy UTC (USD); 0 blokuje płatne wywołania. */
	public function monthlyCostLimit(): float
	{
		return $this->float(self::MONTHLY_COST_LIMIT, 10.0, 0.0, 100000.0);
	}

	/** Minimalna liczba wyświetleń frazy w oknie wyboru, żeby wzbogacić ją danymi rynkowymi. */
	public function minImpressions(): int
	{
		return $this->int(self::MIN_IMPRESSIONS, 50, 1, 100000000);
	}

	/** Okno wyboru fraz (dni danych GSC kończących się na ostatniej zaimportowanej dacie). */
	public function windowDays(): int
	{
		return $this->int(self::WINDOW_DAYS, 90, 7, 480);
	}

	/** Maksymalna liczba fraz w jednej synchronizacji projektu (ręcznej i automatycznej). */
	public function syncLimit(): int
	{
		return $this->int(self::SYNC_LIMIT, 1000, 1, 100000);
	}

	/** Automatyczne odświeżanie nieaktualnych metryk (tylko projekty po pierwszej jawnej synchronizacji). */
	public function autoRefresh(): bool
	{
		$value = $this->config->get(self::AUTO_REFRESH);

		return $value === null || ! in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
	}

	/**
	 * Efektywne ustawienia (do statusu i dokumentacji, bez sekretów).
	 *
	 * @return array<string, int|float|bool>
	 */
	public function effective(): array
	{
		return [
			'volume_ttl_days' => $this->volumeTtlDays(),
			'difficulty_ttl_days' => $this->difficultyTtlDays(),
			'max_tasks_per_run' => $this->maxTasksPerRun(),
			'daily_cost_limit' => $this->dailyCostLimit(),
			'monthly_cost_limit' => $this->monthlyCostLimit(),
			'min_impressions' => $this->minImpressions(),
			'window_days' => $this->windowDays(),
			'sync_limit' => $this->syncLimit(),
			'auto_refresh' => $this->autoRefresh(),
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
