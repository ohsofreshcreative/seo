<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

/**
 * Kategorie błędów Search Console API — decydują o ponowieniach (w żądaniu i na poziomie zadania)
 * i trafiają do `sync_runs.error_code`.
 */
enum ErrorCategory: string
{
	/** 429 albo 403 z powodem limitu (rateLimitExceeded, quotaExceeded…). */
	case RateLimited = 'rate_limited';

	/** 500, 502, 503, 504 — przejściowy błąd Google. */
	case Transient = 'transient';

	/** Brak odpowiedzi HTTP (DNS, timeout, TLS). */
	case Network = 'network';

	/** 401 mimo odświeżenia access tokenu. */
	case Unauthorized = 'unauthorized';

	/** 403 — konto nie ma dostępu do property. */
	case PermissionDenied = 'permission_denied';

	/** 404 — property nie istnieje (np. usunięta z Search Console). */
	case NotFound = 'not_found';

	/** 400 — nieprawidłowe żądanie (np. zakres dat). */
	case BadRequest = 'bad_request';

	/** Odpowiedź 2xx, ale treść nie pasuje do kontraktu API. */
	case Malformed = 'malformed_response';

	/** Paginacja niestabilna: powtórzona strona, zduplikowane klucze, przekroczony limit stron. */
	case Pagination = 'pagination';

	/** Inny kod HTTP. */
	case Http = 'http_error';

	/** Ponowienie ma sens (później): limit, chwilowa awaria, sieć, uszkodzona lub niestabilna odpowiedź. */
	public function isRetryable(): bool
	{
		return match ($this) {
			self::RateLimited, self::Transient, self::Network, self::Malformed, self::Pagination => true,
			default => false,
		};
	}

	/** Ponowienie w tym samym żądaniu (krótki backoff) — tylko dla limitów i chwilowych awarii. */
	public function isRetryableInRequest(): bool
	{
		return in_array($this, [self::RateLimited, self::Transient, self::Network], true);
	}

	public function userMessage(): string
	{
		return match ($this) {
			self::RateLimited => 'Google chwilowo ogranicza liczbę zapytań (limit API). Synchronizacja zostanie ponowiona.',
			self::Transient => 'Search Console API chwilowo nie odpowiada poprawnie. Synchronizacja zostanie ponowiona.',
			self::Network => 'Brak połączenia z Google (sieć lub przekroczony czas). Synchronizacja zostanie ponowiona.',
			self::Unauthorized => 'Google odrzucił autoryzację połączenia. Połącz konto Google ponownie.',
			self::PermissionDenied => 'Połączone konto Google nie ma dostępu do tej property Search Console.',
			self::NotFound => 'Property nie istnieje w Search Console połączonego konta.',
			self::BadRequest => 'Google odrzucił zapytanie jako nieprawidłowe.',
			self::Malformed => 'Google zwrócił odpowiedź w nieoczekiwanym formacie.',
			self::Pagination => 'Google zwrócił niespójne strony wyników. Synchronizacja zostanie ponowiona.',
			self::Http => 'Nieoczekiwany błąd Search Console API.',
		};
	}
}
