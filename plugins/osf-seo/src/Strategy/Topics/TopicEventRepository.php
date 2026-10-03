<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;

/**
 * Zdarzenia tematów (`strategy_topic_events`) — tylko istotne zmiany, bez migawek; odczyty zawężone do projektu.
 */
final class TopicEventRepository
{
	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * @param list<array{topic_id: int, type: string, from?: ?string, to?: ?string, data?: array<string, mixed>|null, user?: ?int}> $events
	 */
	public function add(int $projectId, array $events, string $now): int
	{
		if ($events === []) {
			return 0;
		}

		$insert = new BulkInsert(
			$this->db,
			$this->db->table('strategy_topic_events'),
			['topic_id', 'project_id', 'type', 'from_value', 'to_value', 'data', 'created_by', 'created_at'],
			['%d', '%d', '%s', "NULLIF(%s, '')", "NULLIF(%s, '')", "NULLIF(%s, '')", 'NULLIF(%d, 0)', '%s'],
			'',
			200,
		);

		foreach ($events as $event) {
			$insert->add([
				$event['topic_id'],
				$projectId,
				$event['type'],
				mb_substr((string) ($event['from'] ?? ''), 0, 64),
				mb_substr((string) ($event['to'] ?? ''), 0, 64),
				empty($event['data']) ? '' : (string) json_encode($event['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				max(0, (int) ($event['user'] ?? 0)),
				$now,
			]);
		}

		$insert->flush();

		return count($events);
	}

	/**
	 * Najnowsze zdarzenia tematu.
	 *
	 * @return list<array{type: string, label: string, from: ?string, to: ?string, data: ?array<string, mixed>, user: ?int, at: string}>
	 */
	public function forTopic(int $projectId, int $topicId, int $limit = 50): array
	{
		return array_map(static function (array $row): array {
			$data = is_string($row['data']) ? json_decode($row['data'], true) : null;

			return [
				'type' => (string) $row['type'],
				'label' => TopicEvent::label((string) $row['type']),
				'from' => $row['from_value'],
				'to' => $row['to_value'],
				'data' => is_array($data) ? $data : null,
				'user' => $row['created_by'] === null ? null : (int) $row['created_by'],
				'at' => (string) $row['created_at'],
			];
		}, $this->db->fetchAll(
			"SELECT type, from_value, to_value, data, created_by, created_at FROM `{$this->db->table('strategy_topic_events')}`
			WHERE project_id = %d AND topic_id = %d ORDER BY id DESC LIMIT %d",
			[$projectId, $topicId, max(1, $limit)],
		));
	}
}
