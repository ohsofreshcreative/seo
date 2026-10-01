<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use InvalidArgumentException;
use OsfSeo\Google\ApiRequester;
use OsfSeo\Google\GoogleConnection;
use OsfSeo\Google\OAuthEndpointError;
use OsfSeo\Http\HttpResponse;
use OsfSeo\Http\TransportException;
use OsfSeo\Support\DateRange;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Sleeper;

/**
 * Klient Google Search Console API (webmasters v3) na autoryzowanym kliencie Google (ApiRequester:
 * Bearer tylko do *.googleapis.com, po 401 jedno odświeżenie tokenu).
 *
 * Ponowienia w żądaniu: 429, 403 z powodem limitu, 5xx i błędy sieci — krótki, ograniczony backoff
 * (Retry-After honorowany do MAX_IN_REQUEST_DELAY). Dłuższe przerwy to zadanie kolejki synchronizacji
 * (ponowienie zadania z backoffem minutowym/godzinowym), nie usypianie procesu PHP.
 *
 * Logi zawierają wyłącznie status, kategorię i powód błędu — bez nagłówków (Authorization) i treści.
 */
final class GscClient
{
	public const API_BASE = 'https://www.googleapis.com/webmasters/v3';

	/** Timeout HTTP (s) — strona 25 000 wierszy to kilka MB odpowiedzi. */
	public const HTTP_TIMEOUT = 60;

	/** Opóźnienia (s) przed kolejnymi próbami w tym samym żądaniu: 2 ponowienia. */
	private const IN_REQUEST_DELAYS = [1.0, 3.0];

	/** Dłuższy Retry-After → bez czekania, decyzję podejmuje kolejka (ponowienie zadania). */
	private const MAX_IN_REQUEST_DELAY = 10;

	private int $requestCount = 0;

	public function __construct(
		private readonly ApiRequester $api,
		private readonly Sleeper $sleeper,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Properties dostępne dla konta Google (w kolejności z API).
	 *
	 * @return list<GscProperty>
	 * @throws GscApiException
	 * @throws \OsfSeo\Google\ReauthorizationRequired
	 */
	public function listSites(GoogleConnection $connection): array
	{
		$json = $this->send($connection, 'GET', self::API_BASE . '/sites');
		$entries = $json['siteEntry'] ?? [];

		if (! is_array($entries) || ! array_is_list($entries)) {
			throw GscApiException::malformed('siteEntry is not a list.');
		}

		$properties = [];

		foreach ($entries as $entry) {
			if (! is_array($entry)) {
				throw GscApiException::malformed('siteEntry item is not an object.');
			}

			try {
				$properties[] = GscProperty::fromApi($entry);
			} catch (InvalidArgumentException) {
				// Pojedynczy nietypowy wpis (np. identyfikator dłuższy niż kolumna) nie blokuje wyboru pozostałych.
				$this->logger->warning('Skipped an invalid Search Console siteEntry.');
			}
		}

		return $properties;
	}

	/**
	 * Jedna strona searchAnalytics.query (zwalidowana).
	 *
	 * @throws GscApiException
	 * @throws \OsfSeo\Google\ReauthorizationRequired
	 */
	public function query(GoogleConnection $connection, string $siteUrl, SearchAnalyticsRequest $request): SearchAnalyticsPage
	{
		$json = $this->send($connection, 'POST', self::siteUrlPath($siteUrl) . '/searchAnalytics/query', $request->toApi());

		return self::parsePage($json, $request);
	}

	/**
	 * Wszystkie strony wyników (rowLimit/startRow) jako generator — wywołujący przetwarza stronę i zwalnia pamięć.
	 *
	 * Koniec: strona krótsza niż rowLimit (także pusta). Ochrona: limit stron wynikający z zakresu dat
	 * (Google udostępnia maks. ok. 50 000 wierszy dziennie) i wykrywanie powtórzonej strony.
	 * 25 000 wierszy nie oznacza kompletu danych — GSC pomija zapytania zanonimizowane.
	 *
	 * @return \Generator<int, SearchAnalyticsPage>
	 * @throws GscApiException
	 */
	public function pages(GoogleConnection $connection, string $siteUrl, SearchAnalyticsRequest $request): \Generator
	{
		$maxPages = $request->maxPages();
		$previous = null;

		for ($page = 0; ; $page++) {
			if ($page >= $maxPages) {
				throw GscApiException::pagination(sprintf('Pagination exceeded %d pages for %s.', $maxPages, $request->range));
			}

			$result = $this->query($connection, $siteUrl, $request->withStartRow($page * $request->rowLimit));

			if ($result->count() > 0 && $previous !== null && $result->fingerprint() === $previous) {
				throw GscApiException::pagination(sprintf('Page at startRow %d repeats the previous page.', $result->startRow));
			}

			$previous = $result->fingerprint();

			yield $result;

			if ($result->count() < $request->rowLimit) {
				return;
			}
		}
	}

	/**
	 * @param array<string, mixed> $json
	 * @throws GscApiException
	 */
	public static function parsePage(array $json, SearchAnalyticsRequest $request): SearchAnalyticsPage
	{
		$rows = $json['rows'] ?? [];

		if (! is_array($rows) || ! array_is_list($rows)) {
			throw GscApiException::malformed('rows is not a list.');
		}

		if (count($rows) > $request->rowLimit) {
			throw GscApiException::malformed('More rows than rowLimit.');
		}

		$dimensions = count($request->dimensions);
		$dateIndex = $request->dimensionIndex('date');
		$parsed = [];

		foreach ($rows as $i => $row) {
			$keys = is_array($row) ? ($row['keys'] ?? ($dimensions === 0 ? [] : null)) : null;

			if (! is_array($keys) || ! array_is_list($keys) || count($keys) !== $dimensions) {
				throw GscApiException::malformed(sprintf('Row %d: keys do not match dimensions.', $i));
			}

			foreach ($keys as $key) {
				if (! is_string($key)) {
					throw GscApiException::malformed(sprintf('Row %d: non-string key.', $i));
				}
			}

			if ($dateIndex !== null && (! DateRange::isDate($keys[$dateIndex]) || ! $request->range->contains($keys[$dateIndex]))) {
				throw GscApiException::malformed(sprintf('Row %d: date outside of the requested range.', $i));
			}

			$clicks = self::count($row['clicks'] ?? null);
			$impressions = self::count($row['impressions'] ?? null);
			$position = $row['position'] ?? null;

			if ($clicks === null || $impressions === null || ! (is_int($position) || is_float($position)) || ! is_finite((float) $position) || $position < 0) {
				throw GscApiException::malformed(sprintf('Row %d: invalid metrics.', $i));
			}

			$parsed[] = ['keys' => $keys, 'clicks' => $clicks, 'impressions' => $impressions, 'position' => (float) $position];
		}

		$aggregation = $json['responseAggregationType'] ?? '';

		return new SearchAnalyticsPage($parsed, $request->startRow, is_string($aggregation) ? $aggregation : '');
	}

	/** Liczba żądań HTTP do API wykonanych przez ten obiekt (z ponowieniami) — do statystyk synchronizacji. */
	public function requestCount(): int
	{
		return $this->requestCount;
	}

	/** Licznik (kliknięcia, wyświetlenia): liczba całkowita ≥ 0 mieszcząca się w INT UNSIGNED; JSON może podać 5.0. */
	private static function count(mixed $value): ?int
	{
		if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 4294967295.0) {
			$value = (int) $value;
		}

		return is_int($value) && $value >= 0 && $value <= 4294967295 ? $value : null;
	}

	public static function siteUrlPath(string $siteUrl): string
	{
		return self::API_BASE . '/sites/' . rawurlencode($siteUrl);
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 * @throws GscApiException
	 */
	private function send(GoogleConnection $connection, string $method, string $url, ?array $body = null): array
	{
		$attempt = 0;

		while (true) {
			$attempt++;

			try {
				$this->requestCount++;
				$response = $this->api->request($connection, $method, $url, $body);
				$error = $response->isSuccess() ? null : GscApiException::fromResponse($response);
			} catch (TransportException $exception) {
				$response = null;
				$error = new GscApiException(ErrorCategory::Network, null, '', $exception->getMessage(), null, $exception);
			} catch (OAuthEndpointError $exception) {
				// Odświeżenie access tokenu nie powiodło się z innego powodu niż invalid_grant
				// (ten kończy się ReauthorizationRequired i nie jest tu przechwytywany).
				$response = null;
				$error = new GscApiException(match (true) {
					$exception->status() === 429 => ErrorCategory::RateLimited,
					$exception->status() >= 500 => ErrorCategory::Transient,
					default => ErrorCategory::Unauthorized,
				}, $exception->status(), $exception->error(), 'OAuth token refresh failed.', null, $exception);
			}

			if ($error === null && $response instanceof HttpResponse) {
				$json = $response->json();

				if ($json === null) {
					throw GscApiException::malformed('Response body is not a JSON object.');
				}

				return $json;
			}

			$delay = $this->retryDelay($error, $attempt);

			if ($delay === null) {
				$this->logger->warning('Search Console API request failed: {category} (status {status}, reason {reason}).', [
					'category' => $error->category()->value,
					'status' => $error->status() ?? 'none',
					'reason' => $error->reason() !== '' ? $error->reason() : 'none',
					'attempts' => $attempt,
				]);

				throw $error;
			}

			$this->logger->info('Search Console API {category} (status {status}); retry {attempt} in {delay}s.', [
				'category' => $error->category()->value,
				'status' => $error->status() ?? 'none',
				'attempt' => $attempt,
				'delay' => $delay,
			]);
			$this->sleeper->sleep($delay);
		}
	}

	private function retryDelay(GscApiException $error, int $attempt): ?float
	{
		if (! $error->category()->isRetryableInRequest() || $attempt > count(self::IN_REQUEST_DELAYS)) {
			return null;
		}

		$retryAfter = $error->retryAfter();

		if ($retryAfter !== null) {
			return $retryAfter <= self::MAX_IN_REQUEST_DELAY ? (float) max($retryAfter, 1) : null;
		}

		return self::IN_REQUEST_DELAYS[$attempt - 1];
	}
}
