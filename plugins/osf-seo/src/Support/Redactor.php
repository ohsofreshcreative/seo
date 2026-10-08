<?php

declare(strict_types=1);

namespace OsfSeo\Support;

use Stringable;
use Throwable;

/**
 * Maskuje sekrety przed zapisem do logów: wartości pod wrażliwymi kluczami oraz ciągi
 * wyglądające jak tokeny Google, sekrety klienta OAuth, nagłówki Bearer, JWT i klucze prywatne.
 *
 * Nadmiarowe maskowanie jest akceptowalne — przeciek sekretu nie.
 */
final class Redactor
{
	public const MASK = '[REDACTED]';

	private const MAX_DEPTH = 5;

	/**
	 * Klucze kontekstu (bez rozróżniania wielkości liter), których wartość zawsze maskujemy.
	 * `code` to kod autoryzacyjny OAuth — kody błędów/HTTP logujemy pod innymi nazwami (`error_code`, `status`);
	 * `state` to jednorazowy parametr OAuth (CSRF).
	 */
	private const SENSITIVE_KEYS = [
		'api_key',
		'apikey',
		'authorization',
		'basic_auth',
		'credentials',
		'code',
		'code_verifier',
		'cookie',
		'encryption_key',
		'pass',
		'passwd',
		'password',
		'private_key',
		'secret',
		'secret_key',
		'set-cookie',
		'state',
		'token',
		'x-api-key',
	];

	/** Pokrywają m.in. access_token, refresh_token, id_token i client_secret. */
	private const SENSITIVE_SUFFIXES = ['_password', '_secret', '_token'];

	private const VALUE_PATTERNS = [
		'/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|$)/s',
		'/\bya29\.[A-Za-z0-9._\-]+/',
		'/(?<![A-Za-z0-9\/])1\/\/[A-Za-z0-9._\-]{16,}/',
		'/\bGOCSPX-[A-Za-z0-9_\-]+/',
		'/\bBearer\s+[A-Za-z0-9._~+\/\-]+=*/i',
		// Basic Auth (DataForSEO: Base64 z login:hasło).
		'/\bBasic\s+[A-Za-z0-9+\/]{6,}=*/i',
		'/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/',
		// Klucze API OpenAI (sk-…, sk-proj-…) i Anthropic (sk-ant-…).
		'/\bsk-[A-Za-z0-9_\-]{20,}/',
	];

	public function string(string $value): string
	{
		return (string) preg_replace(self::VALUE_PATTERNS, self::MASK, $value);
	}

	/**
	 * @param array<array-key, mixed> $context
	 * @return array<array-key, mixed>
	 */
	public function context(array $context): array
	{
		return $this->redactArray($context, 0);
	}

	public function isSensitiveKey(string $key): bool
	{
		$key = strtolower($key);

		if (in_array($key, self::SENSITIVE_KEYS, true)) {
			return true;
		}

		foreach (self::SENSITIVE_SUFFIXES as $suffix) {
			if (str_ends_with($key, $suffix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<array-key, mixed> $values
	 * @return array<array-key, mixed>
	 */
	private function redactArray(array $values, int $depth): array
	{
		$result = [];

		foreach ($values as $key => $value) {
			$result[$key] = is_string($key) && $this->isSensitiveKey($key)
				? self::MASK
				: $this->redactValue($value, $depth);
		}

		return $result;
	}

	private function redactValue(mixed $value, int $depth): mixed
	{
		return match (true) {
			is_string($value) => $this->string($value),
			is_array($value) => $depth >= self::MAX_DEPTH ? '[array]' : $this->redactArray($value, $depth + 1),
			$value instanceof Throwable => [
				'exception' => $value::class,
				'message' => $this->string($value->getMessage()),
				'code' => $value->getCode(),
			],
			$value instanceof Stringable => $this->string((string) $value),
			is_object($value) => '[object ' . $value::class . ']',
			is_resource($value) => '[resource]',
			default => $value,
		};
	}
}
