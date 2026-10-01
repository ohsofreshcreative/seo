<?php

declare(strict_types=1);

namespace OsfSeo\Market;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Rejestr zadań płatnego API (`market_tasks`): każde zlecenie wolumenu (Standard, czeka na wynik) i każde wywołanie
 * Live — z endpointem, projektem, liczbą fraz, kosztem (zgłoszonym przez API albo szacowanym) i wynikiem.
 *
 * To lokalny bezpiecznik, nie księgowość: limity dzienne i miesięczne liczą `COALESCE(cost, estimated_cost)`.
 * Błędy, przy których dostawca na pewno niczego nie wykonał (logowanie, środki, limit żądań, nieprawidłowe żądanie),
 * są zapisywane z kosztem szacowanym 0; przy niepewnych (sieć, 5xx) — z szacunkiem (ostrożnie).
 */
final class MarketTaskRepository
{
	public const STATUS_PENDING = 'pending';

	public const STATUS_COMPLETED = 'completed';

	public const STATUS_FAILED = 'failed';

	public const STATUS_EXPIRED = 'expired';

	/** Po ilu dniach usuwamy listę fraz zakończonego zadania (wiersz z kosztem zostaje). */
	private const KEYWORDS_RETENTION_DAYS = 30;

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}

	/**
	 * @param list<string> $keywords postacie znormalizowane
	 */
	public function create(
		string $provider,
		ProviderEndpoint $endpoint,
		string $trigger,
		?int $projectId,
		Market $market,
		array $keywords,
		float $estimatedCost,
	): int {
		$now = $this->now();

		return $this->db->insert($this->table(), [
			'provider' => $provider,
			'endpoint' => $endpoint->name,
			'mode' => $endpoint->mode,
			'trigger_type' => $trigger,
			'project_id' => $projectId,
			'location_code' => $market->locationCode,
			'language_code' => $market->languageCode,
			'status' => self::STATUS_PENDING,
			'keywords_count' => count($keywords),
			'keywords' => (string) json_encode(array_values($keywords), JSON_UNESCAPED_UNICODE),
			'estimated_cost' => round($estimatedCost, 6),
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	/** Zlecenie przyjęte przez dostawcę (Standard): identyfikator zadania, koszt i termin pierwszego odbioru. */
	public function markSubmitted(int $id, string $providerTaskId, ?float $cost, string $nextCheckAt): void
	{
		$this->db->update($this->table(), [
			'provider_task_id' => $providerTaskId,
			'cost' => $cost === null ? null : round($cost, 6),
			'next_check_at' => $nextCheckAt,
			'updated_at' => $this->now(),
		], ['id' => $id]);
	}

	public function markCompleted(int $id, int $results, ?float $cost = null): void
	{
		$data = ['status' => self::STATUS_COMPLETED, 'results_count' => $results, 'completed_at' => $this->now(), 'next_check_at' => null, 'updated_at' => $this->now()];

		if ($cost !== null) {
			$data['cost'] = round($cost, 6);
		}

		$this->db->update($this->table(), $data, ['id' => $id]);
	}

	/**
	 * @param bool $charged czy dostawca mógł obciążyć konto (nieznany wynik) — wtedy zostaje koszt szacowany
	 */
	public function markFailed(int $id, ProviderErrorCategory $category, string $message, bool $charged): void
	{
		$data = [
			'status' => self::STATUS_FAILED,
			'error_code' => $category->value,
			'error_message' => mb_substr($message, 0, 250),
			'completed_at' => $this->now(),
			'next_check_at' => null,
			'updated_at' => $this->now(),
		];

		if (! $charged) {
			$data['estimated_cost'] = 0;
		}

		$this->db->update($this->table(), $data, ['id' => $id]);
	}

	public function markExpired(int $id): void
	{
		$this->db->update($this->table(), [
			'status' => self::STATUS_EXPIRED,
			'error_code' => 'expired',
			'completed_at' => $this->now(),
			'next_check_at' => null,
			'updated_at' => $this->now(),
		], ['id' => $id]);
	}

	/** Wynik jeszcze niegotowy — kolejna próba odbioru później. */
	public function reschedule(int $id, string $nextCheckAt, ?string $errorCode = null): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET attempts = attempts + 1, next_check_at = %s, error_code = NULLIF(%s, ''), updated_at = %s WHERE id = %d",
			[$nextCheckAt, $errorCode ?? '', $this->now(), $id],
		);
	}

	/**
	 * Zadania Standard do odbioru (bezpłatny `task_get`).
	 *
	 * @return list<array<string, string|null>>
	 */
	public function duePending(int $limit): array
	{
		return $this->db->fetchAll(
			"SELECT * FROM `{$this->table()}` WHERE status = 'pending' AND provider_task_id IS NOT NULL AND next_check_at <= %s
			ORDER BY next_check_at, id LIMIT %d",
			[$this->now(), max(1, $limit)],
		);
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public function find(int $id): ?array
	{
		return $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE id = %d", [$id]);
	}

	/**
	 * Frazy zadania (postacie znormalizowane).
	 *
	 * @param array<string, string|null> $task
	 * @return list<string>
	 */
	public static function keywords(array $task): array
	{
		$keywords = json_decode((string) ($task['keywords'] ?? ''), true);

		return is_array($keywords) ? array_values(array_filter($keywords, 'is_string')) : [];
	}

	public function pendingCount(?int $projectId = null): int
	{
		return (int) $this->db->fetchValue(
			"SELECT COUNT(*) FROM `{$this->table()}` WHERE status = 'pending'" . ($projectId === null ? '' : ' AND project_id = %d'),
			$projectId === null ? [] : [$projectId],
		);
	}

	/** Koszt od podanej chwili (UTC) — zgłoszony przez API, a gdy brak — szacowany. */
	public function spentSince(string $since): float
	{
		return (float) $this->db->fetchValue(
			"SELECT COALESCE(SUM(COALESCE(cost, estimated_cost)), 0) FROM `{$this->table()}` WHERE created_at >= %s",
			[$since],
		);
	}

	/**
	 * Zużycie od podanej chwili (status, panel): liczba zadań, koszt, frazy, błędy.
	 *
	 * @return array{tasks: int, failed: int, keywords: int, cost: float, reported_cost: float}
	 */
	public function usage(string $since, ?int $projectId = null): array
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS tasks, SUM(status = 'failed') AS failed, COALESCE(SUM(keywords_count), 0) AS keywords,
				COALESCE(SUM(COALESCE(cost, estimated_cost)), 0) AS cost, COALESCE(SUM(cost), 0) AS reported_cost
			FROM `{$this->table()}` WHERE created_at >= %s" . ($projectId === null ? '' : ' AND project_id = %d'),
			$projectId === null ? [$since] : [$since, $projectId],
		) ?? [];

		return [
			'tasks' => (int) ($row['tasks'] ?? 0),
			'failed' => (int) ($row['failed'] ?? 0),
			'keywords' => (int) ($row['keywords'] ?? 0),
			'cost' => round((float) ($row['cost'] ?? 0), 6),
			'reported_cost' => round((float) ($row['reported_cost'] ?? 0), 6),
		];
	}

	/**
	 * Ostatnie zadania (bez listy fraz).
	 *
	 * @return list<array<string, string|null>>
	 */
	public function recent(int $limit = 10, ?int $projectId = null): array
	{
		$columns = 'id, endpoint, mode, trigger_type, project_id, location_code, language_code, status, keywords_count, results_count,
			estimated_cost, cost, attempts, error_code, error_message, created_at, completed_at';

		return $projectId === null
			? $this->db->fetchAll("SELECT {$columns} FROM `{$this->table()}` ORDER BY id DESC LIMIT %d", [max(1, $limit)])
			: $this->db->fetchAll("SELECT {$columns} FROM `{$this->table()}` WHERE project_id = %d ORDER BY id DESC LIMIT %d", [$projectId, max(1, $limit)]);
	}

	/** Utrzymanie: lista fraz zakończonych zadań jest potrzebna tylko do odbioru wyniku — po 30 dniach ją usuwamy. */
	public function maintenance(): int
	{
		return $this->db->execute(
			"UPDATE `{$this->table()}` SET keywords = NULL WHERE status <> 'pending' AND keywords IS NOT NULL AND created_at < %s LIMIT 1000",
			[$this->clock->now()->modify('-' . self::KEYWORDS_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s')],
		);
	}

	private function table(): string
	{
		return $this->db->table('market_tasks');
	}
}
