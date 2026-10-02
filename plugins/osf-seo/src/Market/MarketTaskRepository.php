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

	/** `trigger_type` żądań wyszukiwania nowych fraz (STEP 13). */
	public const TRIGGER_DISCOVERY = 'discovery';

	/** Wzbogacanie fraz wolumenem (Standard) — jedyne zadania odbierane przez `MarketSyncService::collect()`. */
	public const ENDPOINT_VOLUME = 'google_ads_search_volume';

	/** Pomiary pozycji SERP (STEP 14): jeden wiersz = jedno zlecenie (do 100 zadań); zadania śledzi `serp_snapshots`. */
	public const ENDPOINT_SERP = 'google_organic_serp';

	/** Luki SEO (STEP 15): jedno żądanie Ranked Keywords = jedna strona wyników (do 1000 fraz) jednej domeny. */
	public const TRIGGER_GAP = 'gap';

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

	/**
	 * Rezerwacja zwolniona: zaplanowane zlecenie nie zostało wysłane (anulowanie, błąd, przy którym dostawca nic nie
	 * wykonał) — koszt 0, nie liczy się do limitów.
	 */
	public function release(int $id, string $reason): void
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'failed', error_code = %s, estimated_cost = 0, cost = NULL, completed_at = %s, updated_at = %s
			WHERE id = %d AND status = 'pending'",
			[$reason, $this->now(), $this->now(), $id],
		);
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
			"SELECT * FROM `{$this->table()}` WHERE status = 'pending' AND provider_task_id IS NOT NULL AND next_check_at <= %s AND endpoint = %s
			ORDER BY next_check_at, id LIMIT %d",
			[$this->now(), self::ENDPOINT_VOLUME, max(1, $limit)],
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

	/** Zadania wolumenu w toku (zlecenia SERP mają własny stan w module pozycji). */
	public function pendingCount(?int $projectId = null): int
	{
		return (int) $this->db->fetchValue(
			"SELECT COUNT(*) FROM `{$this->table()}` WHERE status = 'pending' AND endpoint <> %s" . ($projectId === null ? '' : ' AND project_id = %d'),
			$projectId === null ? [self::ENDPOINT_SERP] : [self::ENDPOINT_SERP, $projectId],
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
	 * Zużycie od podanej chwili z podziałem: wzbogacanie fraz danymi rynkowymi (STEP 12), wyszukiwanie nowych fraz
	 * (STEP 13, `trigger_type = discovery`) i pomiary pozycji SERP (STEP 14, endpoint `google_organic_serp`; zadania =
	 * zadania SERP w zleceniach), luki SEO (STEP 15, `trigger_type = gap`; zadania = strony wyników Ranked Keywords) oraz suma.
	 * Limity kosztów są wspólne — to tylko podział do wglądu.
	 *
	 * @return array{enrichment: array{tasks: int, cost: float}, discovery: array{tasks: int, cost: float}, serp: array{tasks: int, cost: float}, gap: array{tasks: int, cost: float}, total: array{tasks: int, cost: float}}
	 */
	public function usageByPurpose(string $since, ?int $projectId = null): array
	{
		$empty = ['tasks' => 0, 'cost' => 0.0];
		$result = ['enrichment' => $empty, 'discovery' => $empty, 'serp' => $empty, 'gap' => $empty, 'total' => $empty];
		$params = [self::ENDPOINT_SERP, self::TRIGGER_GAP, self::TRIGGER_DISCOVERY, self::ENDPOINT_SERP, $since];

		if ($projectId !== null) {
			$params[] = $projectId;
		}

		foreach ($this->db->fetchAll(
			"SELECT CASE WHEN endpoint = %s THEN 'serp' WHEN trigger_type = %s THEN 'gap' WHEN trigger_type = %s THEN 'discovery' ELSE 'enrichment' END AS purpose,
				SUM(CASE WHEN endpoint = %s THEN keywords_count ELSE 1 END) AS tasks, COALESCE(SUM(COALESCE(cost, estimated_cost)), 0) AS cost
			FROM `{$this->table()}` WHERE created_at >= %s" . ($projectId === null ? '' : ' AND project_id = %d') . ' GROUP BY purpose',
			$params,
		) as $row) {
			$result[(string) $row['purpose']] = ['tasks' => (int) $row['tasks'], 'cost' => round((float) $row['cost'], 6)];
		}

		$tasks = 0;
		$cost = 0.0;

		foreach (['enrichment', 'discovery', 'serp', 'gap'] as $purpose) {
			$tasks += $result[$purpose]['tasks'];
			$cost += $result[$purpose]['cost'];
		}

		$result['total'] = ['tasks' => $tasks, 'cost' => round($cost, 6)];

		return $result;
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

	/**
	 * Utrzymanie:
	 * - zadanie bez identyfikatora dostawcy starsze niż godzina (proces przerwany między wysłaniem a zapisem odpowiedzi)
	 *   → `failed` (`interrupted`); koszt szacowany zostaje w limicie, bo nie wiadomo, czy dostawca je opłacił.
	 *   Nie dotyczy zleceń SERP — ich rezerwacje (zaplanowane paczki) i niepewne zlecenia obsługuje moduł pozycji,
	 * - lista fraz zakończonych zadań jest potrzebna tylko do odbioru wyniku — po 30 dniach ją usuwamy.
	 */
	public function maintenance(): int
	{
		$interrupted = $this->db->execute(
			"UPDATE `{$this->table()}` SET status = 'failed', error_code = 'interrupted', completed_at = %s, updated_at = %s
			WHERE status = 'pending' AND provider_task_id IS NULL AND created_at < %s AND endpoint <> %s",
			[$this->now(), $this->now(), $this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s'), self::ENDPOINT_SERP],
		);

		return $interrupted + $this->db->execute(
			"UPDATE `{$this->table()}` SET keywords = NULL WHERE status <> 'pending' AND keywords IS NOT NULL AND created_at < %s LIMIT 1000",
			[$this->clock->now()->modify('-' . self::KEYWORDS_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s')],
		);
	}

	private function table(): string
	{
		return $this->db->table('market_tasks');
	}
}
