<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Database\DatabaseException;
use OsfSeo\Google\GoogleConnection;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Support\DateRange;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Import jednego okna dat jednego datasetu GSC — idempotentny (zamiana zakresu), atomowy.
 *
 * 1. Pobranie wszystkich stron (rowLimit 25 000, startRow) — każda strona po walidacji jest normalizowana
 *    (fraza/URL → ID) i zapisywana wsadowo do `osf_gsc_import_staging` (pamięć: jedna strona naraz).
 *    Zduplikowany klucz w odpowiedziach = niestabilna paginacja → błąd (ponawiany), nic nie jest zapisywane.
 * 2. Dopiero po pobraniu całego okna — jedna transakcja z blokadą wiersza projektu:
 *    kontrola property (zadanie musi dotyczyć bieżącej property i źródła danych projektu),
 *    DELETE zakresu dat projektu w tabeli faktów, INSERT … SELECT ze stagingu, aktualizacja
 *    first_seen/last_seen słowników.
 * 3. Staging jest czyszczony zawsze (sukces i błąd).
 *
 * Błąd Google, sieci, walidacji albo bazy przed zatwierdzeniem zostawia poprzednie dane bez zmian.
 * position_sum = position × impressions (wiersz z 0 wyświetleń → 0, nie wpływa na średnie).
 */
final class GscImporter
{
	private const STAGING_BATCH = 1000;

	private const CLEANUP_BATCH = 20000;

	public function __construct(
		private readonly GscClient $client,
		private readonly Connection $db,
		private readonly ProjectRepository $projects,
		private readonly Dictionary $dictionary,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @param int $stagingId identyfikator zadania (sync_runs.id) — klucz wierszy w stagingu
	 * @param string $property property, dla której zaplanowano import (kontrolowana przy zatwierdzaniu)
	 *
	 * @throws GscApiException
	 * @throws ImportAborted
	 * @throws \OsfSeo\Google\ReauthorizationRequired
	 * @throws DatabaseException
	 */
	public function import(ProjectContext $context, GoogleConnection $connection, Dataset $dataset, DateRange $range, int $stagingId, string $property): ImportResult
	{
		$projectId = $context->projectId();
		$requestsBefore = $this->client->requestCount();
		$this->dictionary->reset();
		$this->clearStaging($stagingId);

		try {
			$fetched = $this->fetchToStaging($connection, $dataset, $range, $stagingId, $property, $projectId);
			[$written, $replaced] = $this->commit($projectId, $dataset, $range, $stagingId, $property);
		} catch (Throwable $exception) {
			$this->clearStagingQuietly($stagingId);

			throw $exception;
		}

		$this->clearStaging($stagingId);

		$result = new ImportResult(
			dataset: $dataset,
			rowsFetched: $fetched['rows'],
			rowsWritten: $written,
			rowsReplaced: $replaced,
			apiRequests: $this->client->requestCount() - $requestsBefore,
			pages: $fetched['pages'],
			minDate: $fetched['min'],
			maxDate: $fetched['max'],
			keywordsCreated: $fetched['keywords_created'],
			pagesCreated: $fetched['pages_created'],
		);

		$this->logger->info('Imported GSC {dataset} {range} for project {project}: {fetched} rows fetched, {written} written.', [
			'dataset' => $dataset->value,
			'range' => (string) $range,
			'project' => $context->publicId(),
			'fetched' => $result->rowsFetched,
			'written' => $result->rowsWritten,
		]);

		return $result;
	}

	/**
	 * @return array{rows: int, pages: int, min: ?string, max: ?string, keywords_created: int, pages_created: int}
	 */
	private function fetchToStaging(GoogleConnection $connection, Dataset $dataset, DateRange $range, int $stagingId, string $property, int $projectId): array
	{
		$request = new SearchAnalyticsRequest($range, $dataset->dimensions(), SearchAnalyticsRequest::MAX_ROW_LIMIT);
		$staging = new BulkInsert(
			$this->db,
			$this->db->table('gsc_import_staging'),
			['run_id', 'date', 'keyword_id', 'page_id', 'clicks', 'impressions', 'position_sum'],
			['%d', '%s', '%d', '%d', '%d', '%d', '%f'],
			'',
			self::STAGING_BATCH,
		);

		$rows = 0;
		$pages = 0;
		$min = null;
		$max = null;

		try {
			foreach ($this->client->pages($connection, $property, $request) as $page) {
				$pages++;
				$keywordIds = [];
				$pageIds = [];

				if ($dataset->hasKeywords() && $page->rows !== []) {
					$keywordIds = $this->dictionary->keywordIds($projectId, array_values(array_unique(array_map(static fn (array $row): string => $row['keys'][1], $page->rows))));
				}

				if ($dataset->hasPages() && $page->rows !== []) {
					$pageIds = $this->dictionary->pageIds($projectId, array_values(array_unique(array_map(static fn (array $row): string => $row['keys'][2], $page->rows))));
				}

				foreach ($page->rows as $row) {
					$date = $row['keys'][0];
					$min = $min === null || $date < $min ? $date : $min;
					$max = $max === null || $date > $max ? $date : $max;

					$staging->add([
						$stagingId,
						$date,
						$dataset->hasKeywords() ? $keywordIds[$row['keys'][1]] : 0,
						$dataset->hasPages() ? $pageIds[$row['keys'][2]] : 0,
						$row['clicks'],
						$row['impressions'],
						$row['impressions'] > 0 ? $row['position'] * $row['impressions'] : 0.0,
					]);
					$rows++;
				}

				// Strona trafia do bazy przed pobraniem następnej — w pamięci jest najwyżej jedna strona.
				$staging->flush();
			}
		} catch (DatabaseException $exception) {
			if (stripos($exception->getMessage(), 'Duplicate entry') !== false) {
				throw GscApiException::pagination('Google returned the same dimension keys more than once.');
			}

			throw $exception;
		}

		return [
			'rows' => $rows,
			'pages' => $pages,
			'min' => $min,
			'max' => $max,
			'keywords_created' => $this->dictionary->keywordsCreated(),
			'pages_created' => $this->dictionary->pagesCreated(),
		];
	}

	/**
	 * @return array{0: int, 1: int} [zapisane, usunięte]
	 */
	private function commit(int $projectId, Dataset $dataset, DateRange $range, int $stagingId, string $property): array
	{
		return $this->db->transaction(function () use ($projectId, $dataset, $range, $stagingId, $property): array {
			$project = $this->projects->lockForUpdate($projectId);

			if ($project === null) {
				throw new ImportAborted(ImportAborted::PROJECT_MISSING);
			}

			// Dane tylko z bieżącej property i zgodne ze źródłem danych projektu (reset/zmiana property w trakcie → przerwij).
			if ($project->gscProperty !== $property || $project->gscDataProperty !== $property) {
				throw new ImportAborted(ImportAborted::PROPERTY_CHANGED);
			}

			$table = $this->db->table($dataset->factTable());
			$staging = $this->db->table('gsc_import_staging');

			if ($dataset === Dataset::Site) {
				$replaced = $this->db->execute(
					"DELETE FROM `{$table}` WHERE project_id = %d AND date BETWEEN %s AND %s AND device = %d",
					[$projectId, $range->start, $range->end, Dataset::DEVICE_ALL],
				);
				$written = $this->db->execute(
					"INSERT INTO `{$table}` (project_id, date, device, clicks, impressions, position_sum)
					SELECT %d, date, %d, clicks, impressions, position_sum FROM `{$staging}` WHERE run_id = %d",
					[$projectId, Dataset::DEVICE_ALL, $stagingId],
				);

				return [$written, $replaced];
			}

			$replaced = $this->db->execute(
				"DELETE FROM `{$table}` WHERE project_id = %d AND date BETWEEN %s AND %s",
				[$projectId, $range->start, $range->end],
			);

			$written = $dataset === Dataset::Query
				? $this->db->execute(
					"INSERT INTO `{$table}` (project_id, date, keyword_id, clicks, impressions, position_sum)
					SELECT %d, date, keyword_id, clicks, impressions, position_sum FROM `{$staging}` WHERE run_id = %d",
					[$projectId, $stagingId],
				)
				: $this->db->execute(
					"INSERT INTO `{$table}` (project_id, date, keyword_id, page_id, clicks, impressions, position_sum)
					SELECT %d, date, keyword_id, page_id, clicks, impressions, position_sum FROM `{$staging}` WHERE run_id = %d",
					[$projectId, $stagingId],
				);

			$this->touchDictionary('keywords', 'keyword_id', $projectId, $stagingId);

			if ($dataset->hasPages()) {
				$this->touchDictionary('pages', 'page_id', $projectId, $stagingId);
			}

			return [$written, $replaced];
		});
	}

	/** first_seen / last_seen tylko z zatwierdzonych danych. */
	private function touchDictionary(string $table, string $column, int $projectId, int $stagingId): void
	{
		$this->db->execute(
			"UPDATE `{$this->db->table($table)}` d
			JOIN (SELECT `{$column}` AS ref_id, MIN(date) AS first_date, MAX(date) AS last_date
				FROM `{$this->db->table('gsc_import_staging')}` WHERE run_id = %d GROUP BY `{$column}`) s ON s.ref_id = d.id
			SET d.first_seen = IF(d.first_seen IS NULL OR s.first_date < d.first_seen, s.first_date, d.first_seen),
				d.last_seen = IF(d.last_seen IS NULL OR s.last_date > d.last_seen, s.last_date, d.last_seen)
			WHERE d.project_id = %d",
			[$stagingId, $projectId],
		);
	}

	private function clearStaging(int $stagingId): void
	{
		do {
			$affected = $this->db->execute(
				"DELETE FROM `{$this->db->table('gsc_import_staging')}` WHERE run_id = %d LIMIT " . self::CLEANUP_BATCH,
				[$stagingId],
			);
		} while ($affected >= self::CLEANUP_BATCH);
	}

	private function clearStagingQuietly(int $stagingId): void
	{
		try {
			$this->clearStaging($stagingId);
		} catch (Throwable $exception) {
			$this->logger->warning('Could not clean up GSC import staging {staging}: {message}', [
				'staging' => $stagingId,
				'message' => $exception->getMessage(),
			]);
		}
	}
}
