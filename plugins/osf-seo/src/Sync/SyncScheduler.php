<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Setup\Installer;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Harmonogram WP-Cron: co minutę `osf_seo_sync_tick` → planowanie projektów (co 5 min) + wykonanie
 * kolejki w budżecie czasu. Lokalnie WP-Cron uruchamia ruch na stronie (także panel); na produkcji
 * `DISABLE_WP_CRON` + cron systemowy (`wp cron event run --due-now` albo `wp osf-seo sync:run`).
 */
final class SyncScheduler
{
	public const HOOK = 'osf_seo_sync_tick';

	public const SCHEDULE = 'osf_seo_minute';

	private const PLANNED_TRANSIENT = 'osf_seo_sync_planned';

	/** @var list<\Closure(): mixed> */
	private array $followUps = [];

	/** Początek bieżącego przebiegu (tick albo kroki po `sync:run`) — wspólny limit czasu kroków po kolejce (D63). */
	private ?float $runStartedAt = null;

	public function __construct(
		private readonly SyncPlanner $planner,
		private readonly SyncRunner $runner,
		private readonly ProjectGuard $guard,
		private readonly Installer $installer,
		private readonly SyncConfig $config,
		private readonly Logger $logger,
	) {
	}

	public function register(): void
	{
		add_filter('cron_schedules', static function (array $schedules): array {
			$schedules[self::SCHEDULE] ??= ['interval' => 60, 'display' => 'OSF SEO — co minutę'];

			return $schedules;
		});

		add_action(self::HOOK, function (): void {
			$this->tick();
		});

		if (! wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + 60, self::SCHEDULE, self::HOOK);
		}
	}

	public static function unschedule(): void
	{
		wp_clear_scheduled_hook(self::HOOK);
	}

	public function tick(): RunnerReport
	{
		// Kolejka czeka na aktualny schemat (np. tuż po wdrożeniu nowej wersji).
		if ($this->installer->needsUpgrade()) {
			return new RunnerReport(true);
		}

		$this->runStartedAt = microtime(true);

		if (get_transient(self::PLANNED_TRANSIENT) === false) {
			set_transient(self::PLANNED_TRANSIENT, '1', SyncConfig::PLAN_INTERVAL);
			$this->planAll();
		}

		$report = $this->runner->run($this->config->timeBudget(), SyncConfig::MAX_JOBS_PER_RUN);
		$this->runFollowUps();

		return $report;
	}

	/**
	 * Kroki wykonywane po przebiegu kolejki (np. przeliczenie szans SEO po imporcie) — niezależne od importu:
	 * błąd kroku jest logowany i nie wpływa na kolejkę ani na pozostałe kroki.
	 *
	 * @param \Closure(): mixed $step
	 */
	public function onAfterRun(\Closure $step): void
	{
		$this->followUps[] = $step;
	}

	public function runFollowUps(): void
	{
		$this->runStartedAt ??= microtime(true);

		try {
			foreach ($this->followUps as $step) {
				try {
					$step();
				} catch (Throwable $exception) {
					$this->logger->error('Post-sync step failed: {message}', ['message' => $exception->getMessage()]);
				}
			}
		} finally {
			$this->runStartedAt = null;
		}
	}

	/**
	 * Czas pozostały ze wspólnego limitu ticka (od początku ticka albo kroków po `sync:run`) — dla kroku, który nie może wydłużyć
	 * ticka ponad jeden limit (krok Strategii, D63).
	 */
	public function remainingBudget(): float
	{
		$elapsed = $this->runStartedAt === null ? 0.0 : microtime(true) - $this->runStartedAt;

		return max(0.0, (float) $this->config->timeBudget() - $elapsed);
	}

	/** Planowanie wszystkich kwalifikujących się projektów (codzienne odświeżanie, wznowienie łańcuchów). */
	public function planAll(): int
	{
		$planned = 0;

		foreach ($this->planner->eligibleProjectIds() as $publicId) {
			try {
				$planned += count($this->planner->plan($this->guard->authorizeSystem($publicId)));
			} catch (ProjectNotFound) {
				continue;
			} catch (Throwable $exception) {
				$this->logger->error('Planning GSC sync of project {project} failed: {message}', ['project' => $publicId, 'message' => $exception->getMessage()]);
			}
		}

		return $planned;
	}
}
