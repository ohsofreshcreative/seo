<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use InvalidArgumentException;
use OsfSeo\Analytics\Metrics;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleConnection;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Support\DateRange;

/**
 * Diagnostyka prawdziwych danych GSC: jedno małe zapytanie searchAnalytics.query (domyślnie 10 wierszy),
 * bez zapisu do bazy. Do uruchamiania na stagingu (`wp osf-seo gsc:probe`) — nie wypisuje tokenów.
 */
final class GscProbe
{
	public const DEFAULT_DAYS = 7;

	public const DEFAULT_LIMIT = 10;

	public const MAX_LIMIT = 1000;

	public const MAX_DAYS = 31;

	/** Dane `final` pojawiają się zwykle z opóźnieniem 2–3 dni — domyślny koniec zakresu. */
	public const DEFAULT_LAG_DAYS = 3;

	public function __construct(
		private readonly GscClient $client,
		private readonly ConnectionRepository $connections,
		private readonly ProjectRepository $projects,
		private readonly GscCalendar $calendar,
	) {
	}

	/**
	 * @param array{start?: string, end?: string, days?: int|string, dimensions?: string|list<string>, limit?: int|string, data_state?: string} $options
	 *
	 * @throws \OsfSeo\Auth\AccessDenied
	 * @throws GscNotReady
	 * @throws GscApiException
	 * @throws InvalidArgumentException nieprawidłowe opcje
	 * @throws \OsfSeo\Google\ReauthorizationRequired
	 */
	public function run(ProjectContext $context, array $options = []): ProbeResult
	{
		$context->assertCan(Capabilities::MANAGE_CONNECTIONS);

		$project = $this->projects->reload($context->project());
		$connection = self::readyConnection($project->connectionId === null ? null : $this->connections->find($project->connectionId), $project->gscProperty);
		$request = $this->request($options);

		$startedAt = microtime(true);
		$requestsBefore = $this->client->requestCount();
		$page = $this->client->query($connection, (string) $project->gscProperty, $request);

		$clicks = 0;
		$impressions = 0;
		$positionSum = 0.0;

		foreach ($page->rows as $row) {
			$clicks += $row['clicks'];
			$impressions += $row['impressions'];
			$positionSum += $row['position'] * $row['impressions'];
		}

		return new ProbeResult(
			property: (string) $project->gscProperty,
			permission: (string) $project->gscPermission,
			request: $request,
			rows: $page->rows,
			clicks: $clicks,
			impressions: $impressions,
			ctr: Metrics::ctr($clicks, $impressions),
			position: Metrics::position($positionSum, $impressions),
			aggregationType: $page->aggregationType,
			apiRequests: $this->client->requestCount() - $requestsBefore,
			durationMs: (int) round((microtime(true) - $startedAt) * 1000),
		);
	}

	public static function readyConnection(?GoogleConnection $connection, ?string $property): GoogleConnection
	{
		if ($connection === null) {
			throw new GscNotReady(GscNotReady::NO_CONNECTION);
		}

		if (! $connection->isActive()) {
			throw new GscNotReady(GscNotReady::CONNECTION_INACTIVE);
		}

		if ($property === null || $property === '') {
			throw new GscNotReady(GscNotReady::NO_PROPERTY);
		}

		return $connection;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function request(array $options): SearchAnalyticsRequest
	{
		$days = (int) ($options['days'] ?? self::DEFAULT_DAYS);
		$limit = (int) ($options['limit'] ?? self::DEFAULT_LIMIT);

		if ($days < 1 || $days > self::MAX_DAYS) {
			throw new InvalidArgumentException(sprintf('--days must be between 1 and %d.', self::MAX_DAYS));
		}

		if ($limit < 1 || $limit > self::MAX_LIMIT) {
			throw new InvalidArgumentException(sprintf('--limit must be between 1 and %d (the probe fetches a small sample only).', self::MAX_LIMIT));
		}

		$end = (string) ($options['end'] ?? DateRange::shift($this->calendar->today(), -self::DEFAULT_LAG_DAYS));
		$start = (string) ($options['start'] ?? DateRange::shift($end, -($days - 1)));

		if (! DateRange::isDate($start) || ! DateRange::isDate($end) || $start > $end) {
			throw new InvalidArgumentException('--start and --end must be Y-m-d dates and start must not be after end.');
		}

		$range = new DateRange($start, $end);

		if ($range->days() > 500) {
			throw new InvalidArgumentException('The probe range must not exceed 500 days.');
		}

		$dimensions = $options['dimensions'] ?? ['date'];
		$dimensions = is_string($dimensions) ? array_values(array_filter(array_map('trim', explode(',', $dimensions)), static fn (string $d): bool => $d !== '')) : $dimensions;

		return new SearchAnalyticsRequest($range, $dimensions, $limit, 0, (string) ($options['data_state'] ?? 'final'));
	}
}
