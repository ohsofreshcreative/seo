<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Gsc\GscCalendar;
use OsfSeo\Projects\Project;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Projects\ProjectStatus;
use OsfSeo\Support\Logger;

/**
 * Zleca zadania synchronizacji projektu na podstawie jego stanu (WindowPlanner) — idempotentnie:
 * przy oczekującym zadaniu danego rodzaju nic nie dodaje, więc wielokrotne wywołania (cron, po każdym
 * zadaniu, „Synchronizuj teraz”) nie mnożą zadań. Planowanie jednego projektu jest serializowane
 * blokadą wiersza projektu (SELECT … FOR UPDATE).
 *
 * Projekt jest planowany tylko, gdy: aktywny, ma aktywne połączenie Google, wybraną property
 * i dane z tej samej property.
 */
final class SyncPlanner
{
	public function __construct(
		private readonly Connection $db,
		private readonly ProjectRepository $projects,
		private readonly ConnectionRepository $connections,
		private readonly SyncStateRepository $states,
		private readonly SyncRunRepository $runs,
		private readonly GscCalendar $calendar,
		private readonly SyncConfig $config,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @return list<int> identyfikatory nowych zadań
	 */
	public function plan(ProjectContext $context, TriggerType $trigger = TriggerType::Schedule, bool $force = false): array
	{
		return $this->db->transaction(function () use ($context, $trigger, $force): array {
			$project = $this->projects->lockForUpdate($context->projectId());

			if ($project === null || ! $this->isEligible($project)) {
				return [];
			}

			$input = new PlanInput(
				projectId: $project->internalId,
				today: $this->calendar->today(),
				now: $this->states->now(),
				states: $this->states->forProject($project->internalId),
				pending: $this->runs->pendingKinds($project->internalId),
				density: $this->runs->densities($project->internalId),
				historyMonths: $this->config->historyMonths(),
				refreshDays: $this->config->refreshDays(),
				force: $force,
				refreshTrigger: $trigger,
			);

			$ids = [];

			foreach (WindowPlanner::plan($input) as $job) {
				$ids[] = $this->runs->enqueue($project->internalId, $job, (string) $project->gscProperty);
				$this->states->update($project->internalId, $job->dataset, ['status' => RunStatus::Queued->value]);
			}

			if ($ids !== []) {
				$this->logger->debug('Planned {count} GSC sync job(s) for project {project}.', ['count' => count($ids), 'project' => $context->publicId()]);
			}

			return $ids;
		});
	}

	public function isEligible(Project $project): bool
	{
		if ($project->status !== ProjectStatus::Active || $project->gscProperty === null || $project->gscProperty !== $project->gscDataProperty || $project->connectionId === null) {
			return false;
		}

		return $this->connections->find($project->connectionId)?->isActive() ?? false;
	}

	/**
	 * Publiczne ID projektów, które mogą być synchronizowane (zadanie systemowe — cron).
	 *
	 * @return list<string>
	 */
	public function eligibleProjectIds(): array
	{
		$rows = $this->db->fetchAll(
			"SELECT p.public_id FROM `{$this->db->table('projects')}` p
			JOIN `{$this->db->table('connections')}` c ON c.id = p.connection_id
			WHERE p.status = 'active' AND p.gsc_property IS NOT NULL AND p.gsc_property = p.gsc_data_property AND c.status = 'active'
			ORDER BY p.id",
		);

		return array_map(static fn (array $row): string => (string) $row['public_id'], $rows);
	}
}
