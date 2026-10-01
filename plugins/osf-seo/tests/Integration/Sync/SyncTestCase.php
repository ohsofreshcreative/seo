<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Sync;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Gsc\Dictionary;
use OsfSeo\Gsc\GscCalendar;
use OsfSeo\Gsc\GscImporter;
use OsfSeo\Setup\Installer;
use OsfSeo\Sync\SyncConfig;
use OsfSeo\Sync\SyncPlanner;
use OsfSeo\Sync\SyncRun;
use OsfSeo\Sync\SyncRunner;
use OsfSeo\Sync\SyncRunRepository;
use OsfSeo\Sync\SyncScheduler;
use OsfSeo\Sync\SyncService;
use OsfSeo\Sync\SyncStateRepository;
use OsfSeo\Sync\TriggerType;
use OsfSeo\Tests\Integration\Gsc\GscTestCase;

/**
 * Silnik synchronizacji na prawdziwej bazie: zegar testowy (2026-01-15 12:00 UTC → dziś w PT 2026-01-15),
 * atrapa Google z syntetycznymi danymi (ostatnia data `final`: 2026-01-12, historia od 2024-09-20).
 */
abstract class SyncTestCase extends GscTestCase
{
	protected const LATEST = '2026-01-12';

	protected const EARLIEST = '2024-09-20';

	protected SyncRunRepository $runs;

	protected SyncStateRepository $states;

	protected SyncPlanner $planner;

	protected SyncRunner $runner;

	protected SyncService $sync;

	protected SyncScheduler $scheduler;

	/** @var \Closure(): void */
	private \Closure $cron;

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables('gsc_import_staging');
		delete_option(SyncRunner::HEARTBEAT_OPTION);
		delete_option('osf_seo_sync_maintenance_at');
		delete_transient('osf_seo_sync_planned');
		// Tick czeka na aktualną instalację — w testach schemat jest aktualny (freshTables).
		update_option(Installer::OPTION_VERSION, \OsfSeo\Plugin::VERSION);

		// Runner działa jak w WP-Cron (kontekst systemowy projektu dostępny tylko w CLI/cron).
		$this->cron = static fn (): bool => true;
		add_filter('wp_doing_cron', $this->cron);

		$db = self::db();
		$logger = $this->captureLogger();
		$calendar = new GscCalendar($this->clock);
		$config = new SyncConfig();
		$this->runs = new SyncRunRepository($db, $this->clock);
		$this->states = new SyncStateRepository($db, $this->clock);
		$this->planner = new SyncPlanner($db, $this->projects, $this->connections, $this->states, $this->runs, $calendar, $config, $logger);
		$importer = new GscImporter($this->gsc, $db, $this->projects, new Dictionary($db, $this->clock), $logger);
		$this->runner = new SyncRunner($db, $this->runs, $this->states, $this->planner, $importer, $this->guard, $this->projects, $this->connections, $logger);
		$this->sync = new SyncService($db, $this->projects, $this->connections, $this->planner, $this->runner, $this->runs, $this->states, $calendar, $config);
		$this->scheduler = new SyncScheduler($this->planner, $this->runner, $this->guard, osf_seo()->get(Installer::class), $config, $logger);
		$this->properties->onDetach(fn (ProjectContext $context) => $this->runs->cancelPending($context->projectId(), 'property_reset'));
		$this->properties->onSelected(fn (ProjectContext $context) => $this->planner->plan($context, TriggerType::Connect));
	}

	protected function tearDown(): void
	{
		remove_filter('wp_doing_cron', $this->cron);

		parent::tearDown();
	}

	/**
	 * Syntetyczne dane GSC: sumy witryny dla każdego dnia historii; frazy „alfa” i „beta” codziennie,
	 * każda na jednej stronie. Suma witryny > suma fraz (zapytania zanonimizowane).
	 */
	protected function mockGscData(string $latest = self::LATEST): void
	{
		$this->mockSearchAnalytics(static function (array $body) use ($latest): array {
			$rows = [];
			$start = max($body['startDate'], self::EARLIEST);
			$end = min($body['endDate'], $latest);

			for ($date = $start; $date <= $end; $date = \OsfSeo\Support\DateRange::shift($date, 1)) {
				if ($body['dimensions'] === ['date']) {
					$rows[] = self::apiRow([$date], 30, 1000, 8.0);

					continue;
				}

				foreach (['alfa' => [10, 200, 3.0], 'beta' => [2, 100, 12.0]] as $query => [$clicks, $impressions, $position]) {
					$keys = $body['dimensions'] === ['date', 'query'] ? [$date, $query] : [$date, $query, 'https://example.pl/' . $query . '/'];
					$rows[] = self::apiRow($keys, $clicks, $impressions, $position);
				}
			}

			return $rows;
		});
	}

	/** Uruchamia kolejkę do opróżnienia (bez przesuwania zegara). */
	protected function drain(int $maxJobs = 500): \OsfSeo\Sync\RunnerReport
	{
		return $this->runner->run(120.0, $maxJobs);
	}

	/**
	 * @return list<SyncRun>
	 */
	protected function allRuns(ProjectContext $context): array
	{
		return array_reverse($this->runs->recent($context->projectId(), 100));
	}
}
