<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Support\Config;

/**
 * Konfiguracja Strategii (STEP 16, docs/ARCHITECTURE.md, sekcja 15.7): stałe w wp-config.php lub zmienne środowiskowe,
 * wartości spoza zakresu są przycinane. Strategia nie ma własnego budżetu kosztów ani czasu — płatne analizy SERP (od fazy B)
 * liczą się do wspólnych limitów DataForSEO.
 */
final class StrategyConfig
{
	public const MAX_KEYWORDS = 'OSF_SEO_STRATEGY_MAX_KEYWORDS';

	public const SERP_MAX_PER_RUN = 'OSF_SEO_STRATEGY_SERP_MAX_PER_RUN';

	public const WINDOW_DAYS = 'OSF_SEO_STRATEGY_WINDOW_DAYS';

	public const GSC_MIN_IMPRESSIONS = 'OSF_SEO_STRATEGY_GSC_MIN_IMPRESSIONS';

	public const GSC_MAX_POSITION = 'OSF_SEO_STRATEGY_GSC_MAX_POSITION';

	public const DISCOVERY_MIN_PRIORITY = 'OSF_SEO_STRATEGY_DISCOVERY_MIN_PRIORITY';

	public const GAP_MIN_PRIORITY = 'OSF_SEO_STRATEGY_GAP_MIN_PRIORITY';

	/** Strony GSC frazy zapisywane w dowodach (największy udział wyświetleń). */
	public const EVIDENCE_PAGES = 5;

	/** Szanse SEO zapisywane w dowodach frazy (najsilniejsze powiązanie, potem priorytet). */
	public const EVIDENCE_OPPORTUNITIES = 10;

	/** Spadek Pozycji SERP o co najmniej tyle pozycji (porównywalny pomiar) to sygnał spadku. */
	public const SERP_DECLINE_POSITIONS = 5;

	public function __construct(private readonly Config $config = new Config())
	{
	}

	/** Maks. aktywnych kandydatów w projekcie — nadmiar jest liczony i pokazywany, nie zapisywany jako aktywny. */
	public function maxKeywords(): int
	{
		return $this->int(self::MAX_KEYWORDS, 5000, 100, 50000);
	}

	/** Maks. fraz w jednej płatnej analizie SERP Strategii (od fazy B). */
	public function serpMaxPerRun(): int
	{
		return $this->int(self::SERP_MAX_PER_RUN, 100, 1, 1000);
	}

	/** Okno GSC (dni do ostatniej zaimportowanej daty) — fakty kandydatów i źródło GSC. */
	public function windowDays(): int
	{
		return $this->int(self::WINDOW_DAYS, 90, 28, 480);
	}

	/** Źródło GSC: minimalne wyświetlenia frazy w oknie. */
	public function gscMinImpressions(): int
	{
		return $this->int(self::GSC_MIN_IMPRESSIONS, 50, 1, 100000);
	}

	/** Źródło GSC: maksymalna średnia pozycja (GSC) frazy w oknie. */
	public function gscMaxPosition(): float
	{
		return $this->float(self::GSC_MAX_POSITION, 50.0, 1.0, 100.0);
	}

	/** Nowe frazy (nowe / do analizy) wchodzą do Strategii od tego priorytetu odkrycia (zaakceptowane — zawsze). */
	public function discoveryMinPriority(): int
	{
		return $this->int(self::DISCOVERY_MIN_PRIORITY, 50, 0, 100);
	}

	/** Luki fraz wchodzą do Strategii od tego priorytetu luki. */
	public function gapMinPriority(): int
	{
		return $this->int(self::GAP_MIN_PRIORITY, 40, 0, 100);
	}

	/**
	 * @return array<string, int|float>
	 */
	public function effective(): array
	{
		return [
			'max_keywords' => $this->maxKeywords(),
			'serp_max_per_run' => $this->serpMaxPerRun(),
			'window_days' => $this->windowDays(),
			'gsc_min_impressions' => $this->gscMinImpressions(),
			'gsc_max_position' => $this->gscMaxPosition(),
			'discovery_min_priority' => $this->discoveryMinPriority(),
			'gap_min_priority' => $this->gapMinPriority(),
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
