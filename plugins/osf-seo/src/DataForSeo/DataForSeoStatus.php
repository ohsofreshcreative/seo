<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

use OsfSeo\Market\ProviderErrorCategory;

/**
 * Kody statusu DataForSEO (pole `status_code` odpowiedzi i każdego zadania; HTTP zwykle 200).
 * Według dokumentacji „appendix/errors”: 20000 OK, 20100 zadanie utworzone, 40601/40602 zadanie przekazane/w kolejce,
 * 40100 błędny login lub hasło API, 40104 konto niezweryfikowane, 40200/40210 brak środków, 40203 limit kosztów
 * ustawiony w panelu, 40202 limit żądań, 40209 zbyt wiele równoczesnych żądań, 40102 brak wyników,
 * 404xx nie znaleziono, 40501 nieprawidłowe pole, 50000+ błąd wewnętrzny, 40101 błąd wyszukiwarki (przejściowy).
 */
final class DataForSeoStatus
{
	public const OK = 20000;

	public const TASK_CREATED = 20100;

	public const NO_RESULTS = 40102;

	public static function isSuccess(int $code): bool
	{
		return $code === self::OK || $code === self::TASK_CREATED;
	}

	/** Zadanie Standard jeszcze się wykonuje — wynik odbieramy później (bez kosztów). */
	public static function isPending(int $code): bool
	{
		return $code === 40601 || $code === 40602;
	}

	public static function category(int $code, bool $taskLevel = false): ProviderErrorCategory
	{
		return match (true) {
			in_array($code, [40100, 40104, 40201, 40204], true) => ProviderErrorCategory::Authentication,
			in_array($code, [40200, 40203, 40210], true) => ProviderErrorCategory::Billing,
			in_array($code, [40202, 40209], true) => ProviderErrorCategory::RateLimited,
			$code === 40101 || $code >= 50000 => ProviderErrorCategory::Transient,
			$taskLevel && $code >= 40400 && $code < 40500 => ProviderErrorCategory::TaskError,
			$code >= 40000 && $code < 50000 => ProviderErrorCategory::InvalidRequest,
			default => ProviderErrorCategory::MalformedResponse,
		};
	}

	/** Kategoria z samego statusu HTTP (gdy odpowiedź nie ma czytelnej treści). */
	public static function httpCategory(int $status): ?ProviderErrorCategory
	{
		return match (true) {
			$status >= 200 && $status < 300 => null,
			$status === 401 || $status === 403 => ProviderErrorCategory::Authentication,
			$status === 402 => ProviderErrorCategory::Billing,
			$status === 429 => ProviderErrorCategory::RateLimited,
			$status >= 500 => ProviderErrorCategory::Transient,
			default => ProviderErrorCategory::InvalidRequest,
		};
	}
}
