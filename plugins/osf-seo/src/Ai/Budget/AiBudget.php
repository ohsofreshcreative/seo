<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Budget;

use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\Run\AiRunRepository;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Budżet AI (D89) — całkowicie oddzielny od limitów DataForSEO (`market_tasks`, 1 USD / 10 USD bez zmian): limit dzienny, miesięczny,
 * miesięczny na projekt i maksymalny koszt jednej analizy (UTC), wszystkie z konfiguracji, domyślnie 0 (każde płatne wywołanie zablokowane).
 *
 * Do limitów liczy się `COALESCE(actual_cost, reserved_cost)` płatnych uruchomień — rezerwacja od chwili zapisu, rozliczenie po odpowiedzi,
 * a przy wyniku niepewnym (timeout, 5xx, przerwany proces) — cała rezerwacja. Rezerwacja to kontrola limitów z aktualnych sum i zapis
 * uruchomienia w jednej sekcji krytycznej pod blokadą `ai_budget` (GET_LOCK): równoległe uruchomienia nie przekroczą limitu;
 * blokada zajęta dłużej niż LOCK_TIMEOUT → odmowa `budget_busy` (bez wywołania).
 */
final class AiBudget
{
	public const LOCK = 'ai_budget';

	public const LOCK_TIMEOUT = 5;

	public const RUN_LIMIT = 'run_cost_limit';

	public const DAILY = 'budget_daily';

	public const MONTHLY = 'budget_monthly';

	public const PROJECT = 'budget_project';

	public const BUSY = 'budget_busy';

	private const EPSILON = 1e-9;

	public function __construct(
		private readonly AiConfig $config,
		private readonly AiRunRepository $runs,
		private readonly Connection $db,
		private readonly Clock $clock,
		private readonly int $lockTimeout = self::LOCK_TIMEOUT,
	) {
	}

	/**
	 * Powody blokady płatnego wywołania o danym koszcie maksymalnym (pusta lista = w limitach).
	 *
	 * @return list<string>
	 */
	public function blockers(int $projectId, float $cost): array
	{
		$snapshot = $this->snapshot($projectId);
		$blockers = [];

		if ($cost > $snapshot['limits']['max_run_cost'] + self::EPSILON) {
			$blockers[] = self::RUN_LIMIT;
		}

		if ($snapshot['spent']['today'] + $cost > $snapshot['limits']['daily'] + self::EPSILON) {
			$blockers[] = self::DAILY;
		}

		if ($snapshot['spent']['month'] + $cost > $snapshot['limits']['monthly'] + self::EPSILON) {
			$blockers[] = self::MONTHLY;
		}

		if ($snapshot['spent']['project_month'] + $cost > $snapshot['limits']['project_monthly'] + self::EPSILON) {
			$blockers[] = self::PROJECT;
		}

		return $blockers;
	}

	/**
	 * Rezerwacja: kontrola limitów i zapis uruchomienia pod blokadą budżetu.
	 *
	 * @template T
	 * @param callable(): T $create zapis uruchomienia z kosztem zarezerwowanym (autocommit — widoczny dla kolejnych rezerwacji)
	 * @return T
	 *
	 * @throws AiRefused
	 */
	public function reserve(int $projectId, float $cost, callable $create): mixed
	{
		if (! $this->db->acquireLock(self::LOCK, $this->lockTimeout)) {
			throw new AiRefused(self::BUSY);
		}

		try {
			$blockers = $this->blockers($projectId, $cost);

			if ($blockers !== []) {
				throw new AiRefused($blockers[0], $blockers);
			}

			return $create();
		} finally {
			$this->db->releaseLock(self::LOCK);
		}
	}

	/**
	 * Limity i wydatki (UTC): dziś, w miesiącu, w miesiącu projektu.
	 *
	 * @return array{limits: array{daily: float, monthly: float, project_monthly: float, max_run_cost: float}, spent: array{today: float, month: float, project_month: ?float}, remaining: array{today: float, month: float, project_month: ?float}, day_start: string, month_start: string}
	 */
	public function snapshot(?int $projectId = null): array
	{
		$now = $this->clock->now();
		$dayStart = $now->format('Y-m-d 00:00:00');
		$monthStart = $now->format('Y-m-01 00:00:00');
		$limits = [
			'daily' => $this->config->dailyLimit(),
			'monthly' => $this->config->monthlyLimit(),
			'project_monthly' => $this->config->projectMonthlyLimit(),
			'max_run_cost' => $this->config->maxRunCost(),
		];
		$spent = [
			'today' => $this->runs->spentSince($dayStart),
			'month' => $this->runs->spentSince($monthStart),
			'project_month' => $projectId === null ? null : $this->runs->spentSince($monthStart, $projectId),
		];

		return [
			'limits' => $limits,
			'spent' => $spent,
			'remaining' => [
				'today' => round(max(0.0, $limits['daily'] - $spent['today']), 6),
				'month' => round(max(0.0, $limits['monthly'] - $spent['month']), 6),
				'project_month' => $spent['project_month'] === null ? null : round(max(0.0, $limits['project_monthly'] - $spent['project_month']), 6),
			],
			'day_start' => $dayStart,
			'month_start' => $monthStart,
		];
	}
}
