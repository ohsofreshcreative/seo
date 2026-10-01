<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\MarketTaskRepository;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;

/**
 * Płatne pomiary SERP — jedyne miejsce, które zleca zadania dostawcy.
 *
 * 1. Rezerwacja (`queue`, bez API): pod blokadą `serp_reserve` — zajęcie fraz (warunkowo, bez fraz zleconych niedawno),
 *    przebieg, pomiary `queued` i wiersze rejestru kosztów `pending` z kosztem szacowanym (po jednym na paczkę do 100 zadań).
 *    Rezerwacja liczy się od razu do wspólnych limitów dziennego i miesięcznego — inne moduły nie wydadzą tego budżetu.
 * 2. Wysyłka (`submit`, wywoływana pod wspólną blokadą płatnych żądań `MarketSyncService::LOCK`): paczka po paczce,
 *    pomiary oznaczone `uncertain` PRZED wysłaniem. Błąd, przy którym dostawca nic nie wykonał (limit żądań, konto),
 *    przywraca paczkę do kolejki; błąd niepewny (sieć, 5xx, uszkodzona odpowiedź) nie jest ponawiany — zadania mogły
 *    zostać utworzone i opłacone; odzyskuje je lista gotowych zadań po `tag`, a reszta przebiegu jest anulowana.
 */
final class SerpSubmitter
{
	public const RESERVE_LOCK = 'serp_reserve';

	/** Pierwszy odbiór wyniku po zleceniu (kolejka Standard: zwykle ~5 min, gwarantowane 45 min). */
	private const FIRST_CHECK = 600;

	public function __construct(
		private readonly Connection $db,
		private readonly SerpProvider $provider,
		private readonly TrackedKeywordRepository $tracked,
		private readonly SerpRunRepository $runs,
		private readonly SerpSnapshotRepository $snapshots,
		private readonly SerpContextRepository $contexts,
		private readonly MarketTaskRepository $tasks,
		private readonly MarketSyncService $market,
		private readonly SerpPlanner $planner,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Zakolejkowanie pomiaru z planu (bez wywołania API).
	 *
	 * @return array{status: string, run_id: ?int, tasks: int, cost: float, blocked_by: ?string}
	 */
	public function queue(int $projectId, SerpPlan $plan, string $trigger, ?string $slotKey, ?int $userId): array
	{
		$result = ['status' => 'queued', 'run_id' => null, 'tasks' => 0, 'cost' => 0.0, 'blocked_by' => null];

		if ($plan->context === null || $plan->market === null || $plan->keywords === []) {
			return ['status' => $plan->skipReason ?? SerpPlan::NOTHING_TO_DO] + $result;
		}

		if (! $this->db->acquireLock(self::RESERVE_LOCK, 10)) {
			return ['status' => 'locked'] + $result;
		}

		try {
			$context = $this->contexts->ensure($plan->context);
			$cutoff = $this->planner->cutoff();

			return $this->db->transaction(function () use ($projectId, $plan, $context, $trigger, $slotKey, $userId, $cutoff, $result): array {
				$claimed = array_flip($this->tracked->claim(array_map(static fn (array $keyword): int => $keyword['id'], $plan->keywords), $cutoff));
				$keywords = array_values(array_filter($plan->keywords, static fn (array $keyword): bool => isset($claimed[$keyword['id']])));

				if ($keywords === []) {
					return ['status' => SerpPlan::NOTHING_TO_DO] + $result;
				}

				$cost = round(count($keywords) * $plan->costPerTask, 6);
				$budget = $this->market->budget();

				// Cały pomiar albo wcale: limit sprawdzany dla pełnego kosztu (w tej samej transakcji co rezerwacja).
				$blocked = match (true) {
					$budget->spentToday() + $cost > $budget->dailyLimit + 1e-9 => 'daily_limit',
					$budget->spentMonth() + $cost > $budget->monthlyLimit + 1e-9 => 'monthly_limit',
					default => null,
				};

				if ($blocked !== null) {
					$this->tracked->release(array_keys($claimed));

					return ['status' => 'over_budget', 'blocked_by' => $blocked] + $result;
				}

				$runId = $this->runs->create($projectId, (int) $context->id, $trigger, $slotKey, SerpRun::QUEUED, count($keywords), $plan->recent, $cost, $userId);

				if ($runId === null) {
					$this->tracked->release(array_keys($claimed));

					return ['status' => 'slot_taken'] + $result;
				}

				$market = new Market($this->provider->name(), '', '', $context->locationCode, $context->languageCode, '', '');

				foreach (array_chunk($keywords, $this->provider->maxTasksPerPost()) as $chunk) {
					$taskId = $this->tasks->create(
						$this->provider->name(),
						$this->provider->endpoint(),
						$trigger === 'manual' ? 'serp_manual' : 'serp_schedule',
						$projectId,
						$market,
						array_map(static fn (array $keyword): string => $keyword['keyword'], $chunk),
						count($chunk) * $plan->costPerTask,
					);
					$this->snapshots->createQueued($projectId, $runId, (int) $context->id, $taskId, $trigger, $this->provider->name(), $context->depth, $plan->costPerTask, $chunk);
				}

				return ['status' => 'queued', 'run_id' => $runId, 'tasks' => count($keywords), 'cost' => $cost, 'blocked_by' => null];
			});
		} finally {
			$this->db->releaseLock(self::RESERVE_LOCK);
		}
	}

	/**
	 * Wysyłka zaplanowanych paczek (najwyżej $maxPosts zleceń, w budżecie czasu). Wywołujący trzyma `MarketSyncService::LOCK`.
	 *
	 * @return array{posts: int, tasks: int, failed: int, uncertain: int, cost: float, stopped: ?string}
	 */
	public function submit(int $maxPosts, float $budgetSeconds, ?int $runId = null): array
	{
		$report = ['posts' => 0, 'tasks' => 0, 'failed' => 0, 'uncertain' => 0, 'cost' => 0.0, 'stopped' => null];
		$started = microtime(true);

		foreach ($this->snapshots->queuedBatches($maxPosts, $runId) as $batch) {
			if ($report['posts'] >= $maxPosts || microtime(true) - $started >= $budgetSeconds) {
				break;
			}

			if ($this->market->paused() !== null) {
				$report['stopped'] = 'paused';

				break;
			}

			$outcome = $this->post($batch['market_task_id'], $batch['run_id']);
			$report['posts']++;
			$report['tasks'] += $outcome['tasks'];
			$report['failed'] += $outcome['failed'];
			$report['uncertain'] += $outcome['uncertain'];
			$report['cost'] += $outcome['cost'];
			$this->runs->refresh($batch['run_id']);

			if ($outcome['stop'] !== null) {
				$report['stopped'] = $outcome['stop'];

				break;
			}
		}

		$report['cost'] = round($report['cost'], 6);

		return $report;
	}

	/**
	 * Jedno zlecenie (paczka do 100 zadań).
	 *
	 * @return array{tasks: int, failed: int, uncertain: int, cost: float, stop: ?string}
	 */
	private function post(int $marketTaskId, int $runId): array
	{
		$outcome = ['tasks' => 0, 'failed' => 0, 'uncertain' => 0, 'cost' => 0.0, 'stop' => null];
		$rows = $this->snapshots->batch($marketTaskId);

		if ($rows === []) {
			return $outcome;
		}

		$context = $this->contexts->find($rows[0]['context_id']);

		if ($context === null) {
			$this->snapshots->markCancelled(array_column($rows, 'id'), 'missing_context');
			$this->tasks->release($marketTaskId, 'cancelled');

			return $outcome;
		}

		$requests = array_map(static fn (array $row): SerpTaskRequest => new SerpTaskRequest($row['public_id'], $row['keyword'], $context), $rows);
		$ids = array_column($rows, 'id');
		$this->runs->markSubmitting($runId);
		$this->snapshots->markUncertain($ids);

		try {
			$submissions = $this->provider->submit($requests);
		} catch (ProviderException $exception) {
			return $this->failedPost($exception, $marketTaskId, $runId, $rows) + $outcome;
		}

		$accepted = 0;
		$reported = 0.0;
		$hasReported = false;
		$nextCheck = $this->offset(self::FIRST_CHECK);

		foreach ($rows as $row) {
			$submission = $submissions[$row['public_id']] ?? null;

			if ($submission !== null && $submission->accepted()) {
				$this->snapshots->markSubmitted($row['id'], (string) $submission->taskId, $submission->cost, $nextCheck);
				$accepted++;
				$hasReported = $hasReported || $submission->cost !== null;
				$reported += $submission->cost ?? $row['estimated_cost'];

				continue;
			}

			if ($submission === null || $submission->error === ProviderErrorCategory::MalformedResponse) {
				// Brak odpowiedzi dla zadania: mogło zostać utworzone — zostaje `uncertain` (odzyskanie po `tag`).
				$outcome['uncertain']++;
				$reported += $row['estimated_cost'];

				continue;
			}

			$this->snapshots->markFailed([$row['id']], 'task_' . ($submission->statusCode ?? 0));
			$outcome['failed']++;
		}

		$outcome['tasks'] = $accepted;
		$outcome['cost'] = round($reported, 6);

		if ($accepted + $outcome['uncertain'] > 0) {
			$this->tasks->markCompleted($marketTaskId, $accepted, $hasReported || $outcome['uncertain'] > 0 ? $reported : null);
		} else {
			$this->tasks->release($marketTaskId, 'rejected');
		}

		$this->logger->info('SERP tasks submitted for run {run}: {accepted} accepted, {failed} rejected, {uncertain} uncertain.', [
			'run' => $runId,
			'accepted' => $accepted,
			'failed' => $outcome['failed'],
			'uncertain' => $outcome['uncertain'],
		]);

		return $outcome;
	}

	/**
	 * @param list<array{id: int, public_id: string, tracked_keyword_id: int, context_id: int, keyword: string, estimated_cost: float}> $rows
	 * @return array{failed: int, uncertain: int, cost: float, stop: ?string}
	 */
	private function failedPost(ProviderException $exception, int $marketTaskId, int $runId, array $rows): array
	{
		$category = $exception->category();
		$ids = array_column($rows, 'id');
		$this->logger->warning('SERP task post for run {run} failed: {category}.', ['run' => $runId, 'category' => $category->value]);
		$this->runs->recordError($runId, $category->value, $exception->getMessage());

		if ($category === ProviderErrorCategory::RateLimited || $category->isAccountLevel()) {
			// Dostawca nic nie wykonał — paczka wraca do kolejki (rezerwacja kosztu zostaje).
			$this->snapshots->requeue($ids);

			if ($category->isAccountLevel()) {
				$this->market->pause($category);
			}

			return ['failed' => 0, 'uncertain' => 0, 'cost' => 0.0, 'stop' => $category->value];
		}

		if (in_array($category, [ProviderErrorCategory::Network, ProviderErrorCategory::Transient, ProviderErrorCategory::MalformedResponse], true)) {
			// Wynik nieznany: zadania mogły zostać utworzone i opłacone — bez ponawiania; odzyskanie po `tag`.
			// Koszt szacowany zostaje w limicie; dalsze paczki tego przebiegu są anulowane (bez kosztu).
			$this->tasks->markFailed($marketTaskId, $category, $exception->getMessage(), true);
			$this->cancelRest($runId);

			return ['failed' => 0, 'uncertain' => count($ids), 'cost' => round(array_sum(array_column($rows, 'estimated_cost')), 6), 'stop' => $category->value];
		}

		// Nieprawidłowe żądanie: dostawca nic nie wykonał — pomiary kończą się błędem, bez kosztu.
		$this->snapshots->markFailed($ids, $category->value);
		$this->tasks->release($marketTaskId, $category->value);

		return ['failed' => count($ids), 'uncertain' => 0, 'cost' => 0.0, 'stop' => null];
	}

	/** Anulowanie niewysłanych paczek przebiegu: zwolnienie rezerwacji kosztów i fraz. */
	public function cancelRest(int $runId): int
	{
		$rows = $this->db->fetchAll(
			"SELECT id, tracked_keyword_id, market_task_id FROM `{$this->db->table('serp_snapshots')}` WHERE run_id = %d AND status = 'queued'",
			[$runId],
		);

		if ($rows === []) {
			return 0;
		}

		$this->snapshots->markCancelled(array_map(static fn (array $row): int => (int) $row['id'], $rows), 'cancelled');
		$this->tracked->release(array_map(static fn (array $row): int => (int) $row['tracked_keyword_id'], $rows));

		foreach (array_unique(array_map(static fn (array $row): int => (int) $row['market_task_id'], $rows)) as $taskId) {
			$this->tasks->release($taskId, 'cancelled');
		}

		$this->runs->refresh($runId);

		return count($rows);
	}

	private function offset(int $seconds): string
	{
		return $this->clock->now()->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
	}
}
