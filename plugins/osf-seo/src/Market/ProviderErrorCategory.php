<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Kategorie błędów dostawcy danych rynkowych (`market_tasks.error_code`, `market_sync_state.last_error`).
 */
enum ProviderErrorCategory: string
{
	/** Brak danych logowania w konfiguracji — żadne żądanie nie zostało wysłane. */
	case NotConfigured = 'not_configured';
	/** Błędny login/hasło API, konto niezweryfikowane lub zablokowane. */
	case Authentication = 'authentication';
	/** Brak środków / limit kosztów ustawiony u dostawcy. */
	case Billing = 'billing';
	/** Limit żądań dostawcy. */
	case RateLimited = 'rate_limited';
	/** Błędne żądanie (pole, rynek, fraza) — ponowienie nic nie zmieni. */
	case InvalidRequest = 'invalid_request';
	/** Błąd serwera dostawcy (5xx, błąd wewnętrzny). */
	case Transient = 'transient';
	/** Brak odpowiedzi HTTP (DNS, timeout, TLS). */
	case Network = 'network';
	/** Odpowiedź niezgodna z oczekiwanym formatem. */
	case MalformedResponse = 'malformed_response';
	/** Błąd zadania zgłoszony przez dostawcę (status zadania), np. zadanie nie istnieje. */
	case TaskError = 'task_error';

	/** Ponowienie później ma sens (kolejny przebieg w tle) — przy błędach trwałych nie ponawiamy w pętli. */
	public function isRetryable(): bool
	{
		return in_array($this, [self::RateLimited, self::Transient, self::Network, self::MalformedResponse], true);
	}

	/** Błąd dotyczy całego konta (wszystkich projektów) — automatyczne odświeżanie wstrzymane. */
	public function isAccountLevel(): bool
	{
		return $this === self::Authentication || $this === self::Billing || $this === self::NotConfigured;
	}

	public function label(): string
	{
		return match ($this) {
			self::NotConfigured => 'brak konfiguracji',
			self::Authentication => 'błąd logowania do API',
			self::Billing => 'brak środków lub limit kosztów u dostawcy',
			self::RateLimited => 'limit żądań dostawcy',
			self::InvalidRequest => 'nieprawidłowe żądanie',
			self::Transient => 'chwilowy błąd dostawcy',
			self::Network => 'błąd sieci',
			self::MalformedResponse => 'nieoczekiwana odpowiedź',
			self::TaskError => 'błąd zadania',
		};
	}
}
