<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Analytics\Period;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Analiza szans projektu: agregaty GSC (OpportunityDataSource) → wykrywanie (OpportunityDetector) → zapis
 * (OpportunityRepository). Niezależna od importu GSC — import nie czeka na analizę ani od niej nie zależy.
 *
 * - idempotentna: ten sam stan danych (klucz danych) = brak ponownego liczenia; wynik zastępuje wykrycia okresu,
 * - bezpieczna przy ponowieniu i równoległych wywołaniach: blokada GET_LOCK per projekt + blokada wiersza
 *   projektu i kontrola property przy zapisie,
 * - okres analizowany tylko, gdy bieżący okres jest w pełni zaimportowany (frazy i frazy × podstrony);
 *   poprzedni okres bez pełnych danych → bez spadków i z niższą pewnością.
 */
final class OpportunityAnalyzer
{
	public const TRIGGER_AUTO = 'auto';

	public const TRIGGER_MANUAL = 'manual';

	public const TRIGGER_CLI = 'cli';

	public function __construct(
		private readonly Connection $db,
		private readonly OpportunityDataSource $data,
		private readonly OpportunityRepository $repository,
		private readonly ProjectRepository $projects,
		private readonly OpportunityConfig $config,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Wszystkie okresy (najpierw kanoniczny 28 dni). Inny proces analizujący ten projekt → wynik `locked`.
	 *
	 * @param list<int>|null $periods
	 * @return list<AnalysisResult>
	 */
	public function analyzeAll(ProjectContext $context, string $trigger, bool $force = false, ?array $periods = null, int $lockWait = 0): array
	{
		$periods ??= OpportunityConfig::PERIODS;
		$lock = 'opportunities_' . $context->projectId();

		if (! $this->db->acquireLock($lock, $lockWait)) {
			return array_map(static fn (int $days): AnalysisResult => new AnalysisResult($days, AnalysisResult::LOCKED), $periods);
		}

		// Agregaty dużych projektów (setki tysięcy par fraza × podstrona) — jak przy imporcie, wyższy limit pamięci.
		if (function_exists('wp_raise_memory_limit')) {
			wp_raise_memory_limit('osf_seo_opportunities');
		}

		try {
			$results = [];

			foreach ($periods as $days) {
				$results[] = $this->analyze($context, $days, $trigger, $force);
			}

			return $results;
		} finally {
			$this->db->releaseLock($lock);
		}
	}

	/**
	 * Klucz stanu danych okresu: zmiana ostatniej daty, pokrycia zakresu analizy (backfill), property,
	 * wersji analizy albo progów wymusza ponowną analizę. Null — okres nie jest gotowy do analizy.
	 *
	 * @return array{key: string, period: Period, previous_covered: bool, property: string}|array{skip: string, period?: Period}
	 */
	public function prepare(ProjectContext $context, int $days): array
	{
		$project = $this->projects->reload($context->project());
		$property = $project->gscProperty;

		if ($property === null || $property !== $project->gscDataProperty) {
			return ['skip' => 'property_not_ready'];
		}

		$latest = $this->data->latestDate($context);

		if ($latest === null) {
			return ['skip' => 'no_data'];
		}

		$period = new Period($latest, $days);
		$coverage = $this->data->coverageStart($context);

		if ($coverage['query'] === null || $coverage['query_page'] === null
			|| $coverage['query'] > $period->current->start || $coverage['query_page'] > $period->current->start) {
			return ['skip' => 'insufficient_history', 'period' => $period];
		}

		$spanStart = $period->previous->start;
		$previousCovered = $coverage['query'] <= $spanStart && $coverage['query_page'] <= $spanStart;

		return [
			'key' => md5((string) json_encode([
				OpportunityConfig::ANALYSIS_VERSION,
				$this->config->hash(),
				$property,
				$latest,
				$days,
				max($coverage['query'], $spanStart),
				max($coverage['query_page'], $spanStart),
			])),
			'period' => $period,
			'previous_covered' => $previousCovered,
			'property' => $property,
		];
	}

	public function analyze(ProjectContext $context, int $days, string $trigger, bool $force = false): AnalysisResult
	{
		$started = microtime(true);
		$projectId = $context->projectId();

		try {
			$prepared = $this->prepare($context, $days);

			if (isset($prepared['skip'])) {
				$latest = isset($prepared['period']) ? $prepared['period']->latestDate : null;
				$this->repository->recordAnalysis($projectId, $days, 'skipped', $trigger, $context->project()->gscDataProperty, null, $latest, 0, self::elapsed($started), $prepared['skip']);

				return new AnalysisResult($days, AnalysisResult::SKIPPED, 0, $prepared['skip']);
			}

			$previous = $this->repository->analyses($projectId)[$days] ?? null;

			if (! $force && $previous !== null && $previous['status'] === 'success' && $previous['data_key'] === $prepared['key']) {
				return new AnalysisResult($days, AnalysisResult::UNCHANGED, (int) $previous['opportunities']);
			}

			$period = $prepared['period'];
			$candidates = $this->detect($context, $period, $prepared['previous_covered'], $prepared['property']);
			$stats = $this->repository->replaceDetections($projectId, $days, $prepared['property'], $period->latestDate, $candidates);
			$duration = self::elapsed($started);
			$this->repository->recordAnalysis($projectId, $days, 'success', $trigger, $prepared['property'], $prepared['key'], $period->latestDate, $stats['detected'], $duration, null);

			$this->logger->info('Analyzed SEO opportunities of project {project} ({days} days): {detected} detected, {inserted} new, {deactivated} no longer qualifying, {ms} ms.', [
				'project' => $context->publicId(),
				'days' => $days,
				'detected' => $stats['detected'],
				'inserted' => $stats['inserted'],
				'deactivated' => $stats['deactivated'],
				'ms' => $duration,
			]);

			return new AnalysisResult($days, AnalysisResult::SUCCESS, $stats['detected'], null, $stats['inserted'], $stats['deactivated'], $duration);
		} catch (AnalysisAborted $exception) {
			return new AnalysisResult($days, AnalysisResult::SKIPPED, 0, $exception->reason);
		} catch (Throwable $exception) {
			$this->logger->error('SEO opportunity analysis of project {project} ({days} days) failed: {message}', [
				'project' => $context->publicId(),
				'days' => $days,
				'message' => $exception->getMessage(),
			]);

			try {
				$this->repository->recordAnalysis($projectId, $days, 'failed', $trigger, $context->project()->gscDataProperty, null, null, 0, self::elapsed($started), 'internal_error');
			} catch (Throwable) {
				// Zapis stanu analizy jest pomocniczy — błąd bazy zgłosił już wyjątek wyżej.
			}

			return new AnalysisResult($days, AnalysisResult::FAILED, 0, 'internal_error');
		}
	}

	/**
	 * @return list<Candidate>
	 */
	private function detect(ProjectContext $context, Period $period, bool $previousCovered, string $property): array
	{
		$days = $period->days;
		$detector = OpportunityDetector::create($this->config);
		$input = new DetectionInput(
			property: $property,
			period: $period,
			previousCovered: $previousCovered,
			keywords: $this->data->keywords($context, $period, $this->config->volume('keyword_min_impressions', $days)),
			pairs: $this->data->pairs($context, $period, $this->config->volume('pair_min_impressions', $days)),
			pageTotals: $this->data->pageTotals($context, $period, $this->config->volume('keyword_min_impressions', $days)),
		);
		$candidateIds = $detector->cannibalizationKeywordIds($input);

		if ($candidateIds !== []) {
			$known = array_fill_keys(array_map(static fn ($row): int => $row->keywordId, $input->keywords), true);
			$missing = array_values(array_filter($candidateIds, static fn (int $id): bool => ! isset($known[$id])));
			$input = $input->withSegments(
				$this->data->segments($context, $period, $candidateIds, $input->segmentDays()),
				$missing === [] ? [] : $this->data->keywordTexts($context->projectId(), $missing),
			);
		}

		return $detector->detect($input);
	}

	private static function elapsed(float $started): int
	{
		return (int) round((microtime(true) - $started) * 1000);
	}
}
