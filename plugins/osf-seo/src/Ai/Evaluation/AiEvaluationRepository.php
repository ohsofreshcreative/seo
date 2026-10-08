<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Evaluation;

use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Oceny jakości analiz AI (`ai_evaluations`, M0019). Odczyt zawsze w obrębie projektu (brak IDOR); jedna ocena na uruchomienie i oceniającego
 * (ponowna ocena aktualizuje wpis). Metadane uruchomienia (zadanie, dostawca, model, wersje) kopiowane — ocena przetrwa retencję historii.
 */
final class AiEvaluationRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @param array<string, ?int> $scores
	 * @param list<array{code: string, item: ?string, note: ?string}> $issues
	 * @return array<string, mixed>
	 */
	public function save(AiRun $run, int $evaluatorId, array $scores, array $issues, string $verdict, string $action, ?string $notes, ?string $caseId): array
	{
		$now = $this->clock->now()->format('Y-m-d H:i:s');
		$data = [
			'task' => $run->task,
			'provider' => $run->provider,
			'model' => $run->model,
			'prompt_version' => $run->promptVersion,
			'contract_version' => $run->contractVersion,
			'rubric_version' => QualityRubric::VERSION,
			'case_id' => $caseId,
			'scores' => (string) json_encode($scores),
			'issues' => (string) json_encode($issues, JSON_UNESCAPED_UNICODE),
			'verdict' => $verdict,
			'fix_action' => $action,
			'notes' => $notes,
			'updated_at' => $now,
		];
		$existing = $this->db->fetchValue("SELECT id FROM `{$this->table()}` WHERE run_id = %d AND evaluator_id = %d", [$run->id, $evaluatorId]);

		if ($existing !== null) {
			$this->db->update($this->table(), $data, ['id' => (int) $existing]);
		} else {
			$this->db->insert($this->table(), $data + [
				'public_id' => Ulid::generate($this->clock->now()),
				'project_id' => $run->projectId,
				'run_id' => $run->id,
				'evaluator_id' => $evaluatorId,
				'created_at' => $now,
			]);
		}

		return $this->rows("WHERE e.run_id = %d AND e.evaluator_id = %d", [$run->id, $evaluatorId])[0];
	}

	/**
	 * Oceny projektu (najnowsze najpierw), opcjonalnie jednego uruchomienia.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forProject(int $projectId, ?int $runId = null, int $limit = 100): array
	{
		return $runId === null
			? $this->rows('WHERE e.project_id = %d ORDER BY e.updated_at DESC, e.id DESC LIMIT %d', [$projectId, max(1, min(500, $limit))])
			: $this->rows('WHERE e.project_id = %d AND e.run_id = %d ORDER BY e.updated_at DESC, e.id DESC LIMIT %d', [$projectId, $runId, max(1, min(500, $limit))]);
	}

	/**
	 * Oceny do raportu porównawczego (projekt albo cała instalacja; opcjonalnie jeden typ analizy).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forReport(?int $projectId, ?string $task, int $limit = 5000): array
	{
		$where = [];
		$params = [];

		if ($projectId !== null) {
			$where[] = 'e.project_id = %d';
			$params[] = $projectId;
		}

		if ($task !== null) {
			$where[] = 'e.task = %s';
			$params[] = $task;
		}

		$params[] = max(1, $limit);

		return $this->rows(($where === [] ? '' : 'WHERE ' . implode(' AND ', $where)) . ' ORDER BY e.id DESC LIMIT %d', $params);
	}

	/**
	 * @param list<mixed> $params
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $tail, array $params): array
	{
		$rows = $this->db->fetchAll(
			"SELECT e.*, r.public_id AS run_public_id, p.public_id AS project_public_id
			FROM `{$this->table()}` e
			LEFT JOIN `{$this->db->table('ai_runs')}` r ON r.id = e.run_id AND r.project_id = e.project_id
			LEFT JOIN `{$this->db->table('projects')}` p ON p.id = e.project_id {$tail}",
			$params,
		);

		return array_map(static fn (array $row): array => [
			'id' => (string) $row['public_id'],
			'project' => $row['project_public_id'],
			'run' => $row['run_public_id'],
			'evaluator_id' => (int) $row['evaluator_id'],
			'task' => (string) $row['task'],
			'provider' => (string) $row['provider'],
			'model' => (string) $row['model'],
			'prompt_version' => (string) $row['prompt_version'],
			'contract_version' => (int) $row['contract_version'],
			'rubric_version' => (int) $row['rubric_version'],
			'case' => $row['case_id'],
			'scores' => (array) json_decode((string) $row['scores'], true),
			'issues' => (array) json_decode((string) $row['issues'], true),
			'verdict' => (string) $row['verdict'],
			'action' => (string) $row['fix_action'],
			'notes' => $row['notes'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		], $rows);
	}

	private function table(): string
	{
		return $this->db->table('ai_evaluations');
	}
}
