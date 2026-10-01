<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Support\Config;

/**
 * Wszystkie progi wykrywania szans w jednym miejscu (docs/ARCHITECTURE.md, sekcja 10).
 *
 * Progi ilościowe (wyświetlenia, kliknięcia) są zdefiniowane dla okresu 28 dni i skalowane liniowo
 * do długości okresu (7/90 dni) z dolną granicą — krótki okres nie obniża progów do pojedynczych wyświetleń.
 * Każdy próg można nadpisać stałą w wp-config.php albo zmienną środowiskową `OSF_SEO_OPP_<NAZWA>`
 * (np. `OSF_SEO_OPP_LOW_CTR_MIN_IMPRESSIONS=150`); wartości spoza zakresu są przycinane.
 */
final class OpportunityConfig
{
	/** Zmiana reguł/formatu dowodów = nowa wersja (wymusza ponowną analizę wszystkich projektów). */
	public const ANALYSIS_VERSION = 1;

	public const PERIODS = [28, 7, 90];

	/** Okres kanoniczny: jego dowody są „ostatnim snapshotem” szansy (historia po zniknięciu sygnału). */
	public const CANONICAL_DAYS = 28;

	public const ENV_PREFIX = 'OSF_SEO_OPP_';

	/**
	 * Progi ilościowe dla 28 dni: nazwa => [domyślna wartość, dolna granica po skalowaniu].
	 */
	private const VOLUME = [
		// Wczytanie fraz do analizy (frazy poniżej progu w obu okresach to szum).
		'keyword_min_impressions' => [10, 3],
		'pair_min_impressions' => [5, 1],
		// Referencyjny CTR: frazy z co najmniej tyloma wyświetleniami w okresie.
		'reference_min_impressions' => [30, 10],
		// A. Niski CTR.
		'low_ctr_min_impressions' => [100, 30],
		'low_ctr_min_click_gap' => [5, 2],
		// B. Blisko TOP 3 / TOP 10.
		'near_top_min_impressions' => [50, 15],
		// C. Duża widoczność, słaba pozycja.
		'weak_position_min_impressions' => [200, 50],
		// D. Spadki (fraza).
		'decline_min_previous_impressions' => [100, 30],
		'decline_min_previous_clicks' => [10, 4],
		'decline_min_click_loss' => [5, 3],
		'decline_min_impression_loss' => [100, 30],
		'decline_min_current_impressions_for_position' => [50, 15],
		// D. Spadki (cała podstrona).
		'decline_page_min_previous_clicks' => [20, 6],
		'decline_page_min_click_loss' => [10, 4],
		'decline_page_min_impression_loss' => [300, 60],
		// E. Możliwa kanibalizacja.
		'cannibalization_min_impressions' => [100, 30],
		'cannibalization_min_url_impressions' => [20, 6],
		// Pewność: wielkość próby.
		'confidence_high_impressions' => [1000, 250],
		'confidence_medium_impressions' => [300, 80],
		// Priorytet: wartości, przy których składnik osiąga maksimum (skala logarytmiczna).
		'demand_cap' => [11200, 2800],
		'clicks_cap' => [280, 70],
	];

	/**
	 * Progi bez skalowania: nazwa => [domyślna wartość, minimum, maksimum].
	 */
	private const RATIOS = [
		'low_ctr_max_ratio' => [0.6, 0.05, 1.0],
		'low_ctr_max_position' => [20.0, 1.0, 100.0],
		'decline_min_relative' => [0.3, 0.05, 1.0],
		'decline_min_position_drop' => [2.0, 0.5, 50.0],
		'decline_relative_position_drop' => [0.2, 0.0, 1.0],
		'cannibalization_min_share' => [0.15, 0.05, 0.5],
		'cannibalization_top_position' => [3.0, 1.0, 10.0],
		'reference_min_keywords' => [8.0, 3.0, 1000.0],
		'max_groups_per_type' => [300.0, 10.0, 2000.0],
		'evidence_keywords' => [25.0, 5.0, 200.0],
	];

	/** @var array<string, float> */
	private array $cache = [];

	/**
	 * @param array<string, int|float> $overrides jawne wartości progów (testy) — pierwszeństwo przed stałymi/zmiennymi
	 */
	public function __construct(
		private readonly Config $config = new Config(),
		private readonly array $overrides = [],
	) {
	}

	/** Próg ilościowy dla okresu $days (skalowany z wartości dla 28 dni, nie mniej niż dolna granica). */
	public function volume(string $name, int $days): int
	{
		if (! isset(self::VOLUME[$name])) {
			throw new \InvalidArgumentException("Unknown opportunity threshold {$name}.");
		}

		[, $floor] = self::VOLUME[$name];
		$base = $this->value($name);

		return (int) max(min($floor, $base), round($base * $days / 28));
	}

	public function ratio(string $name): float
	{
		if (! isset(self::RATIOS[$name])) {
			throw new \InvalidArgumentException("Unknown opportunity threshold {$name}.");
		}

		return $this->value($name);
	}

	public function int(string $name): int
	{
		return (int) $this->ratio($name);
	}

	/**
	 * Efektywne wartości (po nadpisaniach) — do dokumentacji, CLI i klucza danych analizy.
	 *
	 * @return array<string, float>
	 */
	public function effective(): array
	{
		$values = [];

		foreach (array_keys(self::VOLUME + self::RATIOS) as $name) {
			$values[$name] = $this->value($name);
		}

		return $values;
	}

	/** Skrót konfiguracji: zmiana progu wymusza ponowną analizę. */
	public function hash(): string
	{
		return md5((string) json_encode([self::ANALYSIS_VERSION, $this->effective()]));
	}

	private function value(string $name): float
	{
		if (isset($this->cache[$name])) {
			return $this->cache[$name];
		}

		[$default, $min, $max] = isset(self::VOLUME[$name])
			? [(float) self::VOLUME[$name][0], 0.0, 1000000000.0]
			: self::RATIOS[$name];
		$raw = array_key_exists($name, $this->overrides) ? (string) $this->overrides[$name] : $this->config->get(self::ENV_PREFIX . strtoupper($name));
		$value = $raw !== null && is_numeric(trim($raw)) ? max($min, min($max, (float) trim($raw))) : (float) $default;

		return $this->cache[$name] = $value;
	}
}
