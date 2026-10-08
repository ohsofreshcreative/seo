<?php

declare(strict_types=1);

namespace OsfSeo\Ai;

use OsfSeo\Support\Config;

/**
 * Konfiguracja analiz AI (STEP 17 — docs/ARCHITECTURE.md, sekcja 22): wyłącznie stałe w wp-config.php albo zmienne środowiskowe.
 * Klucz API dostawcy czyta wyłącznie adapter w chwili żądania — nigdy pole obiektu, baza, logi, HTML ani JS.
 *
 * Bezpieczne domyślnie: rzeczywiste wywołania wyłączone (`OSF_SEO_AI_ENABLED`), brak domyślnego modelu, brak cen (bez cen nie da się
 * oszacować kosztu → odmowa) i limity 0 USD (każde płatne wywołanie zablokowane, dopóki administrator świadomie ich nie ustawi).
 * Budżet AI jest całkowicie oddzielny od limitów DataForSEO.
 */
final class AiConfig
{
	public const ENABLED = 'OSF_SEO_AI_ENABLED';

	public const PROVIDER = 'OSF_SEO_AI_PROVIDER';

	public const MODEL = 'OSF_SEO_AI_MODEL';

	public const OPENAI_API_KEY = 'OSF_SEO_OPENAI_API_KEY';

	/** Ceny skonfigurowanego modelu w USD za 1 mln tokenów (zmienne — tylko z konfiguracji, nigdy na sztywno w kodzie). */
	public const PRICE_INPUT = 'OSF_SEO_AI_PRICE_INPUT_PER_MTOK';

	public const PRICE_CACHED_INPUT = 'OSF_SEO_AI_PRICE_CACHED_INPUT_PER_MTOK';

	public const PRICE_OUTPUT = 'OSF_SEO_AI_PRICE_OUTPUT_PER_MTOK';

	public const DAILY_LIMIT = 'OSF_SEO_AI_DAILY_LIMIT';

	public const MONTHLY_LIMIT = 'OSF_SEO_AI_MONTHLY_LIMIT';

	public const PROJECT_MONTHLY_LIMIT = 'OSF_SEO_AI_PROJECT_MONTHLY_LIMIT';

	public const MAX_RUN_COST = 'OSF_SEO_AI_MAX_RUN_COST';

	public const MAX_OUTPUT_TOKENS = 'OSF_SEO_AI_MAX_OUTPUT_TOKENS';

	public const TIMEOUT = 'OSF_SEO_AI_TIMEOUT';

	public const TEMPERATURE = 'OSF_SEO_AI_TEMPERATURE';

	public const RETENTION_DAYS = 'OSF_SEO_AI_RETENTION_DAYS';

	/** Cena tokenów wejścia zapisanych do cache dostawcy (USD / 1 mln) — opcjonalna; brak → cena zwykłego wejścia. */
	public const PRICE_CACHE_WRITE = 'OSF_SEO_AI_PRICE_CACHE_WRITE_PER_MTOK';

	/** Wysiłek rozumowania (Responses API `reasoning.effort`) — wysyłany tylko, gdy ustawiony; część odcisku planu. */
	public const REASONING_EFFORT = 'OSF_SEO_AI_REASONING_EFFORT';

	/** Tryb kontrolowanego testu (faza E): płatne analizy tylko dla wskazanych projektów (identyfikatory publiczne) i typów. */
	public const ALLOWED_PROJECTS = 'OSF_SEO_AI_ALLOWED_PROJECTS';

	public const ALLOWED_TYPES = 'OSF_SEO_AI_ALLOWED_TYPES';

	/** Wartości `reasoning.effort` ze specyfikacji Responses API (nie każdy model obsługuje każdą — odmowa dostawcy = bez kosztu). */
	public const REASONING_EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

	public const DEFAULT_MAX_OUTPUT_TOKENS = 3000;

	public const DEFAULT_TIMEOUT = 90;

	public const DEFAULT_RETENTION_DAYS = 180;

	/** Retencja nie krótsza niż okres limitu miesięcznego (rejestr kosztów to historia uruchomień). */
	public const MIN_RETENTION_DAYS = 35;

	public function __construct(private readonly Config $config = new Config())
	{
	}

	/** Główny wyłącznik rzeczywistych (płatnych) dostawców — domyślnie wyłączony. Dostawca testowy działa zawsze. */
	public function enabled(): bool
	{
		return in_array(strtolower(trim((string) $this->config->get(self::ENABLED, ''))), ['1', 'true', 'yes', 'on'], true);
	}

	/** Skonfigurowany dostawca rzeczywisty (np. `openai`) albo null. */
	public function provider(): ?string
	{
		$value = strtolower(trim((string) $this->config->get(self::PROVIDER, '')));

		return $value === '' ? null : $value;
	}

	/** Skonfigurowany model — bez wartości domyślnej (zmienne modele i ceny). Niepoprawny identyfikator → null. */
	public function model(): ?string
	{
		$value = trim((string) $this->config->get(self::MODEL, ''));

		return self::validModel($value) ? $value : null;
	}

	public static function validModel(string $model): bool
	{
		return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,99}$/', $model) === 1;
	}

	/** Czy klucz API dostawcy jest ustawiony (bez odczytu wartości do pól obiektu). */
	public function hasApiKey(string $constant): bool
	{
		return trim((string) $this->config->get($constant, '')) !== '';
	}

	/**
	 * Klucz API — wyłącznie dla adaptera w chwili budowania nagłówka żądania.
	 *
	 * @internal
	 */
	public function apiKey(string $constant): string
	{
		return trim((string) $this->config->get($constant, ''));
	}

	public function priceInput(): ?float
	{
		return $this->price(self::PRICE_INPUT);
	}

	/** Cena tokenów wejścia z cache; brak → cena zwykłego wejścia (ostrożnie). */
	public function priceCachedInput(): ?float
	{
		return $this->price(self::PRICE_CACHED_INPUT) ?? $this->priceInput();
	}

	public function priceOutput(): ?float
	{
		return $this->price(self::PRICE_OUTPUT);
	}

	/** Jawnie ustawiona cena zapisu do cache (null — liczona jak zwykłe wejście). */
	public function priceCacheWrite(): ?float
	{
		return $this->price(self::PRICE_CACHE_WRITE);
	}

	/** Ustawiony wysiłek rozumowania albo null (nieustawiony — parametr nie jest wysyłany). */
	public function reasoningEffort(): ?string
	{
		$value = strtolower(trim((string) $this->config->get(self::REASONING_EFFORT, '')));

		return in_array($value, self::REASONING_EFFORTS, true) ? $value : null;
	}

	/** Ustawiono wartość spoza listy — płatne wywołanie zablokowane (bez zgadywania). */
	public function invalidReasoningEffort(): bool
	{
		return trim((string) $this->config->get(self::REASONING_EFFORT, '')) !== '' && $this->reasoningEffort() === null;
	}

	/**
	 * Projekty dozwolone dla płatnych analiz (identyfikatory publiczne, wielkie litery); pusta lista — bez ograniczenia.
	 *
	 * @return list<string>
	 */
	public function allowedProjects(): array
	{
		return self::listOf((string) $this->config->get(self::ALLOWED_PROJECTS, ''), static fn (string $value): string => strtoupper($value));
	}

	/**
	 * Typy dozwolone dla płatnych analiz (`page_optimization`, `new_page_brief`, `content_gap`, `topic_analysis`; dopuszczalne myślniki);
	 * pusta lista — bez ograniczenia.
	 *
	 * @return list<string>
	 */
	public function allowedTypes(): array
	{
		return self::listOf((string) $this->config->get(self::ALLOWED_TYPES, ''), static fn (string $value): string => str_replace('-', '_', strtolower($value)));
	}

	/**
	 * @param \Closure(string): string $normalize
	 * @return list<string>
	 */
	private static function listOf(string $value, \Closure $normalize): array
	{
		$items = preg_split('/[\s,;]+/', trim($value)) ?: [];

		return array_values(array_unique(array_map($normalize, array_filter($items, static fn (string $item): bool => $item !== ''))));
	}

	public function dailyLimit(): float
	{
		return $this->money(self::DAILY_LIMIT);
	}

	public function monthlyLimit(): float
	{
		return $this->money(self::MONTHLY_LIMIT);
	}

	public function projectMonthlyLimit(): float
	{
		return $this->money(self::PROJECT_MONTHLY_LIMIT);
	}

	public function maxRunCost(): float
	{
		return $this->money(self::MAX_RUN_COST);
	}

	public function maxOutputTokens(): int
	{
		return $this->int(self::MAX_OUTPUT_TOKENS, self::DEFAULT_MAX_OUTPUT_TOKENS, 256, 16000);
	}

	public function timeout(): int
	{
		return $this->int(self::TIMEOUT, self::DEFAULT_TIMEOUT, 10, 300);
	}

	/** Temperatura tylko, gdy ustawiona (część modeli jej nie obsługuje — wtedy parametr nie jest wysyłany). */
	public function temperature(): ?float
	{
		$value = trim((string) $this->config->get(self::TEMPERATURE, ''));

		return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? max(0.0, min(2.0, (float) $value)) : null;
	}

	public function retentionDays(): int
	{
		return $this->int(self::RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS, self::MIN_RETENTION_DAYS, 3650);
	}

	/**
	 * Ustawienia bez sekretów (status, CLI): wartości, nazwy brakujących stałych — nigdy wartość klucza.
	 *
	 * @return array<string, mixed>
	 */
	public function effective(): array
	{
		return [
			'enabled' => $this->enabled(),
			'provider' => $this->provider(),
			'model' => $this->model(),
			'prices_per_mtok' => ['input' => $this->priceInput(), 'cached_input' => $this->priceCachedInput(), 'cache_write' => $this->priceCacheWrite(), 'output' => $this->priceOutput()],
			'limits' => [
				'daily' => $this->dailyLimit(),
				'monthly' => $this->monthlyLimit(),
				'project_monthly' => $this->projectMonthlyLimit(),
				'max_run_cost' => $this->maxRunCost(),
			],
			'max_output_tokens' => $this->maxOutputTokens(),
			'timeout' => $this->timeout(),
			'temperature' => $this->temperature(),
			'reasoning_effort' => $this->reasoningEffort(),
			'live_test' => ['projects' => $this->allowedProjects(), 'types' => $this->allowedTypes()],
			'retention_days' => $this->retentionDays(),
		];
	}

	private function price(string $name): ?float
	{
		$value = trim((string) $this->config->get($name, ''));

		return preg_match('/^\d+(\.\d+)?$/', $value) === 1 && (float) $value > 0 ? (float) $value : null;
	}

	private function money(string $name): float
	{
		$value = trim((string) $this->config->get($name, ''));

		return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? min(10000.0, (float) $value) : 0.0;
	}

	private function int(string $name, int $default, int $min, int $max): int
	{
		$value = trim((string) $this->config->get($name, ''));

		return preg_match('/^\d+$/', $value) === 1 ? max($min, min($max, (int) $value)) : $default;
	}
}
