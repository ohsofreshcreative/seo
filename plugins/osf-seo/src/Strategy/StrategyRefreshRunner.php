<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Logger;
use ReflectionClass;
use Throwable;

/**
 * Wykonanie zadania przeliczenia Strategii projektu (faza E — docs/ARCHITECTURE.md, sekcja 15.15): blokada projektu → przejęcie zadania
 * → przeliczenie (`StrategyRefresher::refreshLocked`) → zakończenie albo ponowienie — wszystko pod jedną blokadą `StrategyRefresher::lock()`,
 * więc status `running` bez trzymanej blokady zawsze oznacza przerwany proces. Wyłącznie lokalne przeliczenie zapisanych danych — bez API.
 *
 * Po udanym przeliczeniu sprawdza, czy w trakcie nie zmieniły się dane (klucz danych inny niż na starcie) — wtedy oznacza zmianę do
 * wykrycia (debounce kroku w tle), co zleci kolejne przeliczenie bez pętli przeliczenie → unieważnienie → przeliczenie (samo przeliczenie
 * klucza nie zmienia).
 */
final class StrategyRefreshRunner
{
	public function __construct(
		private readonly Connection $db,
		private readonly StrategyRefresher $refresher,
		private readonly StrategyRefreshQueue $queue,
		private readonly StrategySettingsRepository $settings,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Oczekujące zadanie projektu (krok w tle). Projekt przelicza inny proces → zadanie przesunięte bez liczenia próby (`busy`);
	 * zadanie przejęte już przez inny proces albo jeszcze nieaktualne → `not_due`. Zlecenie ręczne wymusza przeliczenie.
	 *
	 * @return array<string, mixed> raport przeliczenia z kluczami `outcome` (`busy`, `not_due`, `refreshed`, `unchanged`, `failed`,
	 *                              `abandoned`) i `queue_status`
	 */
	public function runQueued(int $projectId): array
	{
		$lock = StrategyRefresher::lock($projectId);

		if (! $this->db->acquireLock($lock, 0)) {
			$this->queue->defer($projectId);

			return ['outcome' => 'busy', 'queue_status' => StrategyRefreshQueue::STATUS_QUEUED];
		}

		try {
			$token = $this->queue->claim($projectId);

			if ($token === null) {
				return ['outcome' => 'not_due', 'queue_status' => $this->queue->status($projectId)];
			}

			$source = $this->settings->get($projectId)->refreshSource;

			return $this->execute($projectId, $token, $source === StrategyRefreshQueue::SOURCE_MANUAL, false);
		} finally {
			$this->db->releaseLock($lock);
		}
	}

	/**
	 * Przeliczenie poza kolejką (CLI `strategy:refresh`) — ta sama blokada i ten sam zapis stanu zadania (źródło `cli`); oczekujące
	 * zlecenie sprzed startu zostaje wykonane. Błąd jest zapisany jak w tle (ponowienie z odstępem) i przekazany wywołującemu.
	 *
	 * @return array<string, mixed>
	 */
	public function runNow(int $projectId, bool $force, string $source = StrategyRefreshQueue::SOURCE_CLI): array
	{
		$lock = StrategyRefresher::lock($projectId);

		if (! $this->db->acquireLock($lock, 0)) {
			return ['skipped' => StrategyRefresher::SKIPPED_LOCKED, 'outcome' => 'busy'];
		}

		try {
			return $this->execute($projectId, $this->queue->start($projectId, $source), $force, true);
		} finally {
			$this->db->releaseLock($lock);
		}
	}

	/**
	 * Odzyskanie porzuconego zadania: status `running`, a blokady projektu nie trzyma żaden proces (proces PHP przerwany — limit czasu,
	 * pamięci, restart). Liczy się jako nieudana próba (`interrupted`) z ponowieniem według limitu prób. Zwraca nowy status albo null
	 * (proces żyje albo zadanie już nie trwa).
	 */
	public function recover(int $projectId): ?string
	{
		$lock = StrategyRefresher::lock($projectId);

		if (! $this->db->acquireLock($lock, 0)) {
			return null;
		}

		try {
			$settings = $this->settings->get($projectId);

			if ($settings->refreshStatus !== StrategyRefreshQueue::STATUS_RUNNING || $settings->refreshStartedAt === null) {
				return null;
			}

			$status = $this->queue->fail($projectId, $settings->refreshStartedAt, StrategyRefreshQueue::ERROR_INTERRUPTED, $this->safeKey($projectId));
			$this->logger->warning('Strategy refresh of project {project} started at {started} was interrupted; job is now {status} (attempt {attempt}).', [
				'project' => $projectId,
				'started' => $settings->refreshStartedAt,
				'status' => $status ?? '-',
				'attempt' => $settings->refreshAttempts,
			]);

			return $status;
		} finally {
			$this->db->releaseLock($lock);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function execute(int $projectId, string $token, bool $force, bool $rethrow): array
	{
		try {
			$report = $this->refresher->refreshLocked($projectId, $force);
		} catch (Throwable $exception) {
			$code = 'error:' . (new ReflectionClass($exception))->getShortName();
			// Szczegóły wyłącznie w logu pluginu (redakcja sekretów); w stanie zadania tylko kod — panel nie pokazuje treści wyjątku.
			$this->logger->error('Strategy refresh of project {project} failed ({code}): {message} at {file}:{line}', [
				'project' => $projectId,
				'code' => $code,
				'message' => $exception->getMessage(),
				'file' => basename($exception->getFile()),
				'line' => $exception->getLine(),
			]);
			$status = $this->queue->fail($projectId, $token, $code, $this->safeKey($projectId));

			if ($rethrow) {
				throw $exception;
			}

			return ['skipped' => null, 'outcome' => 'failed', 'error' => $code, 'queue_status' => $status];
		}

		if (in_array($report['skipped'], [StrategyRefresher::SKIPPED_NO_PROJECT, StrategyRefresher::SKIPPED_UNSUPPORTED_MARKET], true)) {
			$this->queue->abandon($projectId, $token, (string) $report['skipped']);

			return $report + ['outcome' => 'abandoned', 'queue_status' => StrategyRefreshQueue::STATUS_FAILED];
		}

		$status = $this->queue->complete($projectId, $token);

		if ($report['skipped'] === null) {
			$current = $this->safeKey($projectId);

			if ($current !== null && $current !== $report['data_key']) {
				// Dane zmieniły się w trakcie przeliczenia — kolejne przeliczenie zleci wykrywanie zmian (po oknie ciszy importu).
				$this->queue->markSeen($projectId, $current);
				$report['changed_during_refresh'] = true;
			}
		}

		return $report + ['outcome' => $report['skipped'] === null ? 'refreshed' : 'unchanged', 'queue_status' => $status];
	}

	private function safeKey(int $projectId): ?string
	{
		try {
			return $this->refresher->currentKey($projectId);
		} catch (Throwable) {
			return null;
		}
	}
}
