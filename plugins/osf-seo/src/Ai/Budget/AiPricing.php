<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Budget;

use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\Provider\AiUsage;

/**
 * Wycena wywołań AI wyłącznie z cen w konfiguracji (USD za 1 mln tokenów — D88; w kodzie nie ma żadnego cennika).
 *
 * Szacunek przed wywołaniem jest ostrożny (górna granica): wejście = bajty instrukcji, wejścia i schematu / 2,5 + narzut (polski tekst
 * w UTF-8 tokenizuje się gorzej niż angielski), wyjście = pełny limit `max_output_tokens` (obejmuje tokeny rozumowania), bez rabatu za cache.
 * Rozliczenie po odpowiedzi — ze zgłoszonego zużycia (wejście z cache po cenie cache, jeśli ustawiona).
 */
final class AiPricing
{
	public const BYTES_PER_TOKEN = 2.5;

	public const OVERHEAD_TOKENS = 300;

	public function __construct(private readonly AiConfig $config)
	{
	}

	/** Czy da się wycenić wywołanie (ceny wejścia i wyjścia ustawione). */
	public function known(): bool
	{
		return $this->config->priceInput() !== null && $this->config->priceOutput() !== null;
	}

	/**
	 * Ceny z konfiguracji (USD za 1 mln tokenów) — część odcisku planu (zmiana cen = nowy plan do zatwierdzenia).
	 *
	 * @return array{input: ?float, cached_input: ?float, output: ?float}
	 */
	public function prices(): array
	{
		$prices = ['input' => $this->config->priceInput(), 'cached_input' => $this->config->priceCachedInput(), 'output' => $this->config->priceOutput()];

		// Cena zapisu do cache tylko, gdy ustawiona — odcisk planu bez niej pozostaje taki jak przed fazą E.
		if ($this->config->priceCacheWrite() !== null) {
			$prices['cache_write'] = $this->config->priceCacheWrite();
		}

		return $prices;
	}

	public static function estimateInputTokens(string ...$parts): int
	{
		$bytes = array_sum(array_map('strlen', $parts));

		return (int) ceil($bytes / self::BYTES_PER_TOKEN) + self::OVERHEAD_TOKENS;
	}

	/** Maksymalny koszt (USD) albo null, gdy cen nie ustawiono. */
	public function maxCost(int $inputTokens, int $maxOutputTokens): ?float
	{
		// Górna granica: całe wejście po wyższej z cen zwykłego wejścia i zapisu do cache.
		$cacheWrite = $this->config->priceCacheWrite();
		$input = $this->config->priceInput();

		return $this->cost($inputTokens, 0, $maxOutputTokens, $cacheWrite !== null && $input !== null && $cacheWrite > $input ? $inputTokens : 0);
	}

	/** Koszt ze zgłoszonego zużycia albo null bez cen. */
	public function actualCost(AiUsage $usage): ?float
	{
		return $this->cost($usage->inputTokens, $usage->cachedTokens, $usage->outputTokens, $usage->cacheWriteTokens);
	}

	/** Wejście: zwykłe, odczyt z cache i zapis do cache (obie kategorie są częścią `input_tokens`). */
	private function cost(int $input, int $cached, int $output, int $cacheWrite = 0): ?float
	{
		$priceInput = $this->config->priceInput();
		$priceCached = $this->config->priceCachedInput();
		$priceOutput = $this->config->priceOutput();

		if ($priceInput === null || $priceOutput === null || $priceCached === null) {
			return null;
		}

		$priceWrite = $this->config->priceCacheWrite() ?? $priceInput;
		$cached = max(0, min($cached, $input));
		$cacheWrite = max(0, min($cacheWrite, $input - $cached));

		return round((($input - $cached - $cacheWrite) * $priceInput + $cached * $priceCached + $cacheWrite * $priceWrite + $output * $priceOutput) / 1_000_000, 6);
	}
}
