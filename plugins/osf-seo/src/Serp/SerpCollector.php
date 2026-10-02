<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\Connection;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Odbiór wyników pomiarów (bezpłatny): lista gotowych zadań (`tasks_ready`) przyspiesza odbiór i odzyskuje zlecenia
 * niepewne po `tag`; zadania niewidoczne na liście są sprawdzane bezpośrednio z narastającym odstępem, a po
 * `OSF_SEO_SERP_EXPIRE_HOURS` oznaczane jako wygasłe. Pod osobną blokadą `serp_collect` (dwa procesy nie odbierają
 * tego samego), niezależnie od blokady płatnych żądań.
 */
final class SerpCollector
{
	public const LOCK = 'serp_collect';

	/** Kolejne próby bezpośredniego odbioru (s). */
	private const POLL_DELAYS = [600, 1200, 1800, 3600, 7200];

	/** Okno odzyskiwania zleceń niepewnych (lista gotowych zadań obejmuje 3 dni). */
	private const UNCERTAIN_HOURS = 72;

	public function __construct(
		private readonly Connection $db,
		private readonly SerpProvider $provider,
		private readonly SerpSnapshotRepository $snapshots,
		private readonly SerpRunRepository $runs,
		private readonly SerpStore $store,
		private readonly SerpConfig $config,
		private readonly MarketSyncService $market,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @return array{ready: int, recovered: int, checked: int, completed: int, pending: int, failed: int, expired: int, interrupted: int, results: int}
	 */
	public function collect(float $budgetSeconds, ?int $limit = null): array
	{
		$report = ['ready' => 0, 'recovered' => 0, 'checked' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0, 'expired' => 0, 'interrupted' => 0, 'results' => 0];

		if (! $this->provider->isConfigured() || ! $this->db->acquireLock(self::LOCK, 0)) {
			return $report;
		}

		try {
			$started = microtime(true);
			$touchedRuns = [];

			foreach ($this->snapshots->closeUncertain($this->offset(-self::UNCERTAIN_HOURS * 3600)) as $row) {
				$report['interrupted']++;
				$touchedRuns[$row['run_id']] = true;
			}

			if ($this->snapshots->hasPending()) {
				try {
					$marked = $this->snapshots->markReady($this->provider->readyTasks());
					$report['ready'] = $marked['ready'];
					$report['recovered'] = $marked['recovered'];
				} catch (ProviderException $exception) {
					// Lista jest tylko przyspieszeniem — odbiór bezpośredni działa dalej.
					$this->logger->warning('SERP tasks_ready failed: {category}.', ['category' => $exception->category()->value]);

					if ($exception->category()->isAccountLevel()) {
						$this->market->pause($exception->category());

						return $report;
					}
				}
			}

			$domains = [];

			foreach ($this->snapshots->due($limit ?? $this->config->collectPerRun()) as $row) {
				if (microtime(true) - $started >= $budgetSeconds) {
					break;
				}

				$report['checked']++;
				$touchedRuns[(int) $row['run_id']] = true;
				$outcome = $this->collectOne($row, $domains);
				$report[$outcome['status']]++;
				$report['results'] += $outcome['results'];

				if ($outcome['status'] === 'failed' && $outcome['stop']) {
					break;
				}
			}

			foreach (array_keys($touchedRuns) as $runId) {
				$this->runs->refresh($runId);
			}
		} finally {
			$this->db->releaseLock(self::LOCK);
		}

		return $report;
	}

	/**
	 * @param array<string, string|null> $row
	 * @param array<int, ?string> $domains cache domen projektów
	 * @return array{status: string, results: int, stop: bool}
	 */
	private function collectOne(array $row, array &$domains): array
	{
		$id = (int) $row['id'];
		$expired = (string) $row['submitted_at'] <= $this->offset(-$this->config->expireHours() * 3600);

		try {
			$page = $this->provider->fetch((string) $row['provider_task_id']);
		} catch (ProviderException $exception) {
			$category = $exception->category();

			if ($category->isAccountLevel()) {
				$this->market->pause($category);
				$this->snapshots->reschedule($id, $this->offset(1800), $category->value);

				return ['status' => 'pending', 'results' => 0, 'stop' => true];
			}

			if (! $expired && ($exception->isRetryable() || $category === ProviderErrorCategory::RateLimited)) {
				$this->snapshots->reschedule($id, $this->offset(1800), $category->value);

				return ['status' => 'pending', 'results' => 0, 'stop' => false];
			}

			// Błąd zadania u dostawcy — zadanie było opłacone przy zleceniu, koszt zostaje.
			$this->snapshots->markFailed([$id], $category->value, true);

			return ['status' => 'failed', 'results' => 0, 'stop' => false];
		} catch (Throwable $exception) {
			$this->logger->error('SERP task {task} could not be read: {message}', ['task' => $row['public_id'], 'message' => $exception->getMessage()]);
			$this->snapshots->reschedule($id, $this->offset(3600), 'internal_error');

			return ['status' => 'pending', 'results' => 0, 'stop' => false];
		}

		if ($page === null) {
			if ($expired) {
				$this->snapshots->markExpired($id);

				return ['status' => 'expired', 'results' => 0, 'stop' => false];
			}

			$attempt = (int) $row['attempts'];
			$this->snapshots->reschedule($id, $this->offset(self::POLL_DELAYS[min($attempt, count(self::POLL_DELAYS) - 1)]));

			return ['status' => 'pending', 'results' => 0, 'stop' => false];
		}

		$projectId = (int) $row['project_id'];
		$domains[$projectId] ??= $this->db->fetchValue("SELECT domain FROM `{$this->db->table('projects')}` WHERE id = %d", [$projectId]);
		$domain = DomainFamily::normalize((string) $domains[$projectId]) ?? (string) $domains[$projectId];

		try {
			$stored = $this->store->ingest($this->snapshots->find($id) ?? $row, $page, $domain);
		} catch (Throwable $exception) {
			$this->logger->error('Storing SERP snapshot {snapshot} failed: {message}', ['snapshot' => $row['public_id'], 'message' => $exception->getMessage()]);
			$this->snapshots->reschedule($id, $this->offset(3600), 'store_error');

			return ['status' => 'pending', 'results' => 0, 'stop' => false];
		}

		return ['status' => $stored === null ? 'pending' : 'completed', 'results' => (int) $stored, 'stop' => false];
	}

	private function offset(int $seconds): string
	{
		return $this->clock->now()->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('Y-m-d H:i:s');
	}
}
