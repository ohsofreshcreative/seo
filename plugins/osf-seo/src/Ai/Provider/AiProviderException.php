<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Provider;

use RuntimeException;

/**
 * Błąd dostawcy AI z rodzajem decydującym o rozliczeniu (D91):
 *
 * - dostawca na pewno niczego nie wykonał → bez kosztu: `config` (np. brak klucza — żadnego żądania), `auth`, `rejected`, `rate_limited`,
 * - dostawca zgłosił zużycie (odmowa, odpowiedź niepełna, błąd modelu) → koszt ze zużycia,
 * - wynik nieznany (sieć, timeout, 5xx, uszkodzona odpowiedź bez zużycia) → niepewne: liczy się cała rezerwacja, bez ponawiania.
 *
 * Komunikat zawiera wyłącznie rodzaj, status HTTP i kod błędu dostawcy — nigdy treści żądania, odpowiedzi ani nagłówków.
 */
final class AiProviderException extends RuntimeException
{
	public const CONFIG = 'config';

	public const AUTH = 'auth';

	public const REJECTED = 'rejected';

	public const RATE_LIMITED = 'rate_limited';

	public const SERVER = 'server';

	public const TRANSPORT = 'transport';

	public const INVALID_RESPONSE = 'invalid_response';

	public const REFUSED = 'refused';

	public const INCOMPLETE = 'incomplete';

	public const FAILED = 'failed';

	/** Rodzaje, przy których dostawca na pewno niczego nie wykonał. */
	private const NOT_EXECUTED = [self::CONFIG, self::AUTH, self::REJECTED, self::RATE_LIMITED];

	public function __construct(
		private readonly string $kind,
		private readonly ?int $httpStatus = null,
		private readonly ?string $providerCode = null,
		private readonly ?AiUsage $usage = null,
		private readonly ?string $responseId = null,
	) {
		parent::__construct(sprintf('AI provider error: %s%s%s.', $kind, $httpStatus === null ? '' : ' (HTTP ' . $httpStatus . ')', $providerCode === null ? '' : ' [' . $providerCode . ']'));
	}

	public function kind(): string
	{
		return $this->kind;
	}

	public function httpStatus(): ?int
	{
		return $this->httpStatus;
	}

	/** Kod błędu dostawcy (oczyszczony, bez komunikatu). */
	public function providerCode(): ?string
	{
		return $this->providerCode;
	}

	public function usage(): ?AiUsage
	{
		return $this->usage;
	}

	public function responseId(): ?string
	{
		return $this->responseId;
	}

	/** Dostawca na pewno niczego nie wykonał (bez kosztu). */
	public function notExecuted(): bool
	{
		return in_array($this->kind, self::NOT_EXECUTED, true);
	}

	/** Wynik nieznany: bez zgłoszonego zużycia i bez pewności, że żądanie nie zostało wykonane. */
	public function uncertain(): bool
	{
		return ! $this->notExecuted() && $this->usage === null;
	}

	/** Kod błędu dostawcy do zapisu (litery, cyfry, `_ . -`, najwyżej 48 znaków) albo null. */
	public static function code(mixed $value): ?string
	{
		if (! is_string($value) || preg_match('/^[A-Za-z0-9_.\-]{1,48}$/', $value) !== 1) {
			return null;
		}

		return strtolower($value);
	}
}
