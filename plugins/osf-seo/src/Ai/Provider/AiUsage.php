<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Provider;

/**
 * Zużycie tokenów zgłoszone przez dostawcę. `cachedTokens` (odczyt z cache) i `cacheWriteTokens` (zapis do cache) są częścią
 * `inputTokens`, tokeny rozumowania — częścią `outputTokens` (rozliczane jak wyjście).
 */
final class AiUsage
{
	public function __construct(
		public readonly int $inputTokens,
		public readonly int $outputTokens,
		public readonly int $cachedTokens = 0,
		public readonly int $reasoningTokens = 0,
		public readonly int $cacheWriteTokens = 0,
	) {
	}

	/**
	 * @param mixed $usage pole `usage` odpowiedzi (format Responses API)
	 */
	public static function fromArray(mixed $usage): ?self
	{
		if (! is_array($usage) || ! is_int($usage['input_tokens'] ?? null) || ! is_int($usage['output_tokens'] ?? null)
			|| $usage['input_tokens'] < 0 || $usage['output_tokens'] < 0) {
			return null;
		}

		$cached = $usage['input_tokens_details']['cached_tokens'] ?? 0;
		$written = $usage['input_tokens_details']['cache_write_tokens'] ?? 0;
		$reasoning = $usage['output_tokens_details']['reasoning_tokens'] ?? 0;
		$cached = is_int($cached) ? max(0, min($cached, $usage['input_tokens'])) : 0;

		return new self(
			$usage['input_tokens'],
			$usage['output_tokens'],
			$cached,
			is_int($reasoning) ? max(0, $reasoning) : 0,
			is_int($written) ? max(0, min($written, $usage['input_tokens'] - $cached)) : 0,
		);
	}

	/**
	 * @return array{input: int, cached: int, cache_write: int, output: int, reasoning: int}
	 */
	public function toArray(): array
	{
		return ['input' => $this->inputTokens, 'cached' => $this->cachedTokens, 'cache_write' => $this->cacheWriteTokens, 'output' => $this->outputTokens, 'reasoning' => $this->reasoningTokens];
	}
}
