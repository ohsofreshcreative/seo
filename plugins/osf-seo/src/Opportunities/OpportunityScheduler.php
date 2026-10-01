<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Support\Logger;
use OsfSeo\Sync\SyncRunRepository;
use Throwable;

/**
 * Automatyczne przeliczanie szans po imporcie GSC — osobny krok po przebiegu kolejki synchronizacji
 * (WP-Cron `osf_seo_sync_tick` i `wp osf-seo sync:run`), a nie część importera: błąd albo czas analizy
 * nie wpływają na import danych.
 *
 * Co CHECK_INTERVAL sekund sprawdza projekty z property i danymi; analizuje tylko te, których klucz danych
 * się zmienił (nowa data GSC, postęp backfillu w zakresie analizy, zmiana property/progów), i tylko gdy
 * nie czeka odświeżanie najnowszych danych (inaczej analiza zaraz byłaby nieaktualna). Limit czasu i projektów.
 */
final class OpportunityScheduler
{
	public const CHECK_INTERVAL = 300;

	public const MAX_PROJECTS_PER_RUN = 3;

	private const CHECK_TRANSIENT = 'osf_seo_opportunities_checked';

	public function __construct(
		private readonly OpportunityAnalyzer $analyzer,
		private readonly OpportunityRepository $repository,
		private readonly SyncRunRepository $runs,
		private readonly ProjectGuard $guard,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @return array{checked: int, analyzed: int, skipped_pending_sync: int}
	 */
	public function run(float $budgetSeconds = 20.0, bool $ignoreInterval = false): array
	{
		$report = ['checked' => 0, 'analyzed' => 0, 'skipped_pending_sync' => 0];

		if (! ProjectGuard::isSystemProcess()) {
			return $report;
		}

		if (! $ignoreInterval && get_transient(self::CHECK_TRANSIENT) !== false) {
			return $report;
		}

		set_transient(self::CHECK_TRANSIENT, '1', self::CHECK_INTERVAL);
		$started = microtime(true);

		foreach ($this->repository->eligibleProjectIds() as $publicId) {
			if (microtime(true) - $started >= $budgetSeconds || $report['analyzed'] >= self::MAX_PROJECTS_PER_RUN) {
				break;
			}

			try {
				$context = $this->guard->authorizeSystem($publicId);
			} catch (ProjectNotFound) {
				continue;
			}

			$report['checked']++;

			try {
				if ($this->refreshPending($context->projectId())) {
					$report['skipped_pending_sync']++;

					continue;
				}

				$results = $this->analyzer->analyzeAll($context, OpportunityAnalyzer::TRIGGER_AUTO);

				if (array_filter($results, static fn (AnalysisResult $result): bool => $result->status === AnalysisResult::SUCCESS) !== []) {
					$report['analyzed']++;
				}
			} catch (Throwable $exception) {
				$this->logger->error('Automatic SEO opportunity analysis of project {project} failed: {message}', ['project' => $publicId, 'message' => $exception->getMessage()]);
			}
		}

		return $report;
	}

	/** Czeka odświeżanie najnowszych danych (sumy, frazy, frazy × podstrony) — analiza po jego zakończeniu. */
	private function refreshPending(int $projectId): bool
	{
		foreach (array_keys($this->runs->pendingKinds($projectId)) as $kind) {
			if (str_ends_with($kind, ':refresh')) {
				return true;
			}
		}

		return false;
	}
}
