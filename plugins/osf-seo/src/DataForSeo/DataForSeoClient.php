<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

use InvalidArgumentException;
use OsfSeo\Http\HttpResponse;
use OsfSeo\Http\HttpTransport;
use OsfSeo\Http\TransportException;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Sleeper;

/**
 * Klient HTTP DataForSEO API v3: Basic Auth (budowany w chwili żądania), JSON, wyłącznie
 * `https://api.dataforseo.com/v3/…`, ograniczone ponowienia i klasyfikacja błędów.
 *
 * Ponowienia w żądaniu (maks. 2: 1 s i 3 s; Retry-After honorowany do 10 s):
 * - GET (odbiór wyników zadań, listy lokalizacji — bezpłatne): limit żądań, błąd serwera, sieć, uszkodzona odpowiedź,
 * - POST (płatne zlecenia): wyłącznie po jawnym odrzuceniu z powodu limitu żądań (dostawca niczego nie wykonał).
 *   Timeout lub błąd 5xx przy POST nie jest ponawiany — zadanie mogło zostać utworzone i opłacone;
 *   frazy wracają do kolejki w kolejnym przebiegu (po kontroli limitów).
 * Błędy trwałe (logowanie, środki, nieprawidłowe żądanie) nigdy nie są ponawiane w pętli.
 *
 * Logi i wyjątki: wyłącznie kod statusu, kategoria i ścieżka endpointu — bez nagłówków, danych logowania i treści.
 */
final class DataForSeoClient
{
	public const BASE_URL = 'https://api.dataforseo.com/v3/';

	public const HOST = 'api.dataforseo.com';

	/** Live (trudność SEO dla 1000 fraz) potrafi trwać kilkanaście sekund. */
	public const HTTP_TIMEOUT = 60;

	private const RETRY_DELAYS = [1.0, 3.0];

	private const MAX_IN_REQUEST_DELAY = 10;

	public function __construct(
		private readonly DataForSeoConfig $config,
		private readonly HttpTransport $http,
		private readonly Sleeper $sleeper,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Płatne zlecenie (task_post, live). Zwraca całą odpowiedź (status 20000; statusy zadań sprawdza wywołujący).
	 *
	 * @param list<array<string, mixed>> $tasks
	 * @return array<string, mixed>
	 *
	 * @throws ProviderException
	 */
	public function post(string $path, array $tasks): array
	{
		return $this->send('POST', $path, (string) json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
	}

	/**
	 * Bezpłatny odczyt (task_get, listy lokalizacji).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ProviderException
	 */
	public function get(string $path): array
	{
		return $this->send('GET', $path, null, true);
	}

	public static function url(string $path): string
	{
		if (preg_match('#^[a-z0-9_]+(?:/[A-Za-z0-9_\-]+)*$#', $path) !== 1) {
			throw new InvalidArgumentException('Invalid DataForSEO API path.');
		}

		return self::BASE_URL . $path;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function send(string $method, string $path, ?string $body, bool $retryTransient): array
	{
		$url = self::url($path);
		$attempt = 0;

		while (true) {
			try {
				return $this->attempt($method, $url, $body);
			} catch (ProviderException $exception) {
				$category = $exception->category();
				$retryable = $category === ProviderErrorCategory::RateLimited || ($retryTransient && $category->isRetryable());
				$delay = $retryable ? $this->retryDelay($exception, $attempt) : null;

				$this->logger->warning('DataForSEO {method} {path} failed: {category} (status {status}, attempt {attempt}).', [
					'method' => $method,
					'path' => $path,
					'category' => $category->value,
					'status' => $exception->statusCode() ?? 0,
					'attempt' => $attempt + 1,
				]);

				if ($delay === null) {
					throw $exception;
				}

				$this->sleeper->sleep($delay);
				$attempt++;
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function attempt(string $method, string $url, ?string $body): array
	{
		$headers = [
			'Authorization' => $this->config->authorizationHeader(),
			'Accept' => 'application/json',
		];

		if ($body !== null) {
			$headers['Content-Type'] = 'application/json';
		}

		try {
			$response = $this->http->request($method, $url, $headers, $body);
		} catch (TransportException) {
			throw new ProviderException(ProviderErrorCategory::Network, 'DataForSEO request failed (no HTTP response).');
		}

		return self::envelope($response);
	}

	/**
	 * Walidacja koperty odpowiedzi: JSON z `status_code`; dla 20000 — tablica `tasks`.
	 *
	 * @return array<string, mixed>
	 */
	public static function envelope(HttpResponse $response): array
	{
		$json = $response->json();
		$retryAfter = self::retryAfterHeader($response);
		$code = is_array($json) && is_int($json['status_code'] ?? null) ? $json['status_code'] : null;

		if ($code === null) {
			$category = DataForSeoStatus::httpCategory($response->status) ?? ProviderErrorCategory::MalformedResponse;

			throw new ProviderException($category, sprintf('DataForSEO responded with HTTP %d without a valid status.', $response->status), $response->status, $retryAfter);
		}

		if ($code !== DataForSeoStatus::OK) {
			// HTTP 401/402/429 mają pierwszeństwo nad nieznanym kodem w treści.
			$category = DataForSeoStatus::category($code);

			if ($category === ProviderErrorCategory::MalformedResponse || $category === ProviderErrorCategory::InvalidRequest) {
				$category = DataForSeoStatus::httpCategory($response->status) ?? $category;
			}

			throw new ProviderException($category, self::message($code, $json['status_message'] ?? null), $code, $retryAfter);
		}

		if (! is_array($json['tasks'] ?? null)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO response has no tasks array.', $code);
		}

		return $json;
	}

	/** Krótki komunikat dostawcy (bez danych żądania). */
	public static function message(int $code, mixed $statusMessage): string
	{
		$text = is_string($statusMessage) ? trim(preg_replace('/\s+/', ' ', $statusMessage) ?? '') : '';

		return sprintf('DataForSEO status %d%s', $code, $text === '' ? '.' : ': ' . mb_substr($text, 0, 160));
	}

	private static function retryAfterHeader(HttpResponse $response): ?int
	{
		$value = $response->headers['retry-after'] ?? null;

		return $value !== null && ctype_digit(trim($value)) ? (int) trim($value) : null;
	}

	private function retryDelay(ProviderException $error, int $attempt): ?float
	{
		if ($attempt >= count(self::RETRY_DELAYS)) {
			return null;
		}

		$retryAfter = $error->retryAfter();

		if ($retryAfter !== null) {
			return $retryAfter <= self::MAX_IN_REQUEST_DELAY ? (float) max($retryAfter, 1) : null;
		}

		return self::RETRY_DELAYS[$attempt];
	}
}
