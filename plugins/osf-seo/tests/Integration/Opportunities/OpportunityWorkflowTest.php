<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Opportunities;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Auth\Roles;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Opportunities\AnalysisResult;
use OsfSeo\Opportunities\OpportunityAnalyzer;
use OsfSeo\Opportunities\OpportunityScheduler;
use OsfSeo\Opportunities\OpportunityStatus;
use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Support\DateRange;
use OsfSeo\Support\ValidationException;
use OsfSeo\Sync\PlannedJob;
use OsfSeo\Sync\SyncRunRepository;
use OsfSeo\Sync\TriggerType;

final class OpportunityWorkflowTest extends OpportunitiesTestCase
{
	public function test_capability_is_granted_to_agency_roles_only(): void
	{
		self::assertTrue(user_can($this->createUser('administrator'), Capabilities::MANAGE_OPPORTUNITIES));
		self::assertTrue(user_can($this->createUser(Roles::ADMIN), Capabilities::MANAGE_OPPORTUNITIES));
		self::assertFalse(user_can($this->createUser(Roles::CLIENT), Capabilities::MANAGE_OPPORTUNITIES));
	}

	public function test_client_can_read_but_not_change_or_recalculate(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context);
		$opportunity = $this->listed($context)[0];
		$client = $this->createUser(Roles::CLIENT);
		$this->projects->assign($context->projectId(), $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);

		self::assertSame($opportunity->publicId, $this->opportunities->find($clientContext, $opportunity->publicId)->publicId);

		try {
			$this->opportunities->update($clientContext, $opportunity->publicId, ['status' => 'dismissed'], '2026-03-30');
			self::fail('Klient nie może zmieniać statusu.');
		} catch (AccessDenied) {
		}

		try {
			$this->opportunities->requestAnalysis($clientContext);
			self::fail('Klient nie może przeliczać szans.');
		} catch (AccessDenied) {
		}

		self::assertSame(OpportunityStatus::New, $this->opportunities->find($context, $opportunity->publicId)->status);
	}

	public function test_unassigned_user_cannot_reach_project_opportunities(): void
	{
		$context = $this->readyProject();
		$stranger = $this->createUser(Roles::CLIENT);

		$this->expectException(ProjectNotFound::class);
		$this->guard->authorize($context->publicId(), $stranger, Capabilities::MANAGE_OPPORTUNITIES);
	}

	public function test_invalid_or_foreign_identifiers_are_not_found(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);

		foreach (['', 'abc', '1', "01J0000000000000000000TEST' OR 1=1 --", '01J0000000000000000000TEST'] as $id) {
			try {
				$this->opportunities->find($context, $id);
				self::fail('Identyfikator spoza projektu: ' . $id);
			} catch (\OsfSeo\Opportunities\OpportunityNotFound) {
			}
		}

		// Wewnętrzne ID (liczba) nigdy nie działa jako identyfikator w URL.
		$this->expectException(\OsfSeo\Opportunities\OpportunityNotFound::class);
		$this->opportunities->find($context, (string) $this->listed($context)[0]->id);
	}

	public function test_validation_of_workflow_input(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);
		$id = $this->listed($context)[0]->publicId;

		$cases = [
			[['status' => 'done'], 'status'],
			[['status' => 'review', 'note' => str_repeat('a', 2001)], 'note'],
			[['status' => 'completed', 'completed_on' => '2026-04-01'], 'completed_on'],
			[['status' => 'completed', 'completed_on' => '30.03.2026'], 'completed_on'],
		];

		foreach ($cases as [$input, $field]) {
			try {
				$this->opportunities->update($context, $id, $input, '2026-03-30');
				self::fail('Brak błędu walidacji: ' . $field);
			} catch (ValidationException $exception) {
				self::assertArrayHasKey($field, $exception->errors());
			}
		}

		self::assertSame(OpportunityStatus::New, $this->opportunities->find($context, $id)->status);
	}

	public function test_completion_records_baseline_and_observational_after_period(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);
		$lowCtr = $this->findByType($context, OpportunityType::LowCtr);

		// Grupa niskiego CTR /buty/: „buty damskie” (2,0) i „buty zimowe” (4,8 — CTR 2% przy referencji 7%).
		// Wdrożenie 2026-02-28: okres przed = 31.01–27.02 (22 / 2000 + 28 / 1400), po = 01.03–28.03 (20 / 2000 + 30 / 1500).
		$completed = $this->opportunities->update($context, $lowCtr->publicId, ['status' => 'completed', 'completed_on' => '2026-02-28', 'note' => 'Nowy title.'], '2026-03-30');

		self::assertSame(OpportunityStatus::Completed, $completed->status);
		self::assertSame('2026-02-28', $completed->completedOn);
		self::assertSame(['2026-01-31', '2026-02-27'], $completed->baseline['window']);
		self::assertSame(['buty damskie', 'buty zimowe'], $completed->baseline['keywords']);
		self::assertSame(['clicks' => 50, 'impressions' => 3400, 'position_sum' => 11000.0], $completed->baseline['before']);

		$after = $this->opportunities->afterImplementation($context, $completed);
		self::assertTrue($after['ready']);
		self::assertSame(['2026-03-01', '2026-03-28'], $after['window']);
		self::assertSame(50, $after['after']->clicks);
		self::assertSame(3500, $after['after']->impressions);

		// Za wcześnie na porównanie: wdrożenie 2026-03-20 → potrzeba danych do 2026-04-17.
		$recent = $this->opportunities->update($context, $lowCtr->publicId, ['status' => 'completed', 'completed_on' => '2026-03-20'], '2026-03-30');
		$pending = $this->opportunities->afterImplementation($context, $recent);
		self::assertFalse($pending['ready']);
		self::assertSame(8, $pending['available_days']);
		self::assertSame(28, $pending['required_days']);
		self::assertNull($pending['after']);

		// Ponowne otwarcie czyści datę wdrożenia i baseline.
		$reopened = $this->opportunities->update($context, $lowCtr->publicId, ['status' => 'in_progress', 'note' => 'Wracamy.'], '2026-03-30');
		self::assertNull($reopened->completedOn);
		self::assertNull($reopened->baseline);
		self::assertSame('Wracamy.', $reopened->note);
	}

	public function test_completion_defaults_to_today(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);

		$completed = $this->opportunities->update($context, $this->listed($context)[0]->publicId, ['status' => 'completed'], '2026-03-30');

		self::assertSame('2026-03-30', $completed->completedOn);
	}

	public function test_manual_recalculation_is_rate_limited(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);

		$first = $this->opportunities->requestAnalysis($context);
		self::assertSame('done', $first['status']);
		self::assertSame(AnalysisResult::SUCCESS, $first['results'][0]->status);
		self::assertSame('rate_limited', $this->opportunities->requestAnalysis($context)['status']);
	}

	public function test_scheduler_analyzes_projects_with_changed_data_only_in_system_processes(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$runs = new SyncRunRepository(self::db(), $this->clock);
		$scheduler = new OpportunityScheduler($this->analyzer, $this->repository, $runs, $this->guard, $this->captureLogger());

		self::assertSame(['checked' => 0, 'analyzed' => 0, 'skipped_pending_sync' => 0], $scheduler->run(), 'Poza WP-Cron/WP-CLI nic się nie dzieje.');

		add_filter('wp_doing_cron', '__return_true');

		try {
			// Czeka odświeżanie najnowszych danych → analiza później.
			$job = $runs->enqueue($context->projectId(), new PlannedJob(Dataset::Query, TriggerType::Schedule, new DateRange('2026-03-22', '2026-03-28'), 20), 'sc-domain:example.pl');
			self::assertSame(1, $scheduler->run()['skipped_pending_sync']);
			self::assertSame(0, self::rows('opportunities', $context->projectId()));

			// Kolejne sprawdzenie dopiero po CHECK_INTERVAL (transient).
			self::db()->execute("UPDATE `" . self::db()->table('sync_runs') . "` SET status = 'success' WHERE id = %d", [$job]);
			self::assertSame(0, $scheduler->run()['checked']);

			self::assertSame(1, $scheduler->run(ignoreInterval: true)['analyzed']);
			self::assertGreaterThan(0, self::rows('opportunities', $context->projectId()));
			self::assertSame(OpportunityAnalyzer::TRIGGER_AUTO, $this->opportunities->analyses($context)[28]['trigger_type']);

			// Dane bez zmian → bez ponownej analizy.
			self::assertSame(0, $scheduler->run(ignoreInterval: true)['analyzed']);
		} finally {
			remove_filter('wp_doing_cron', '__return_true');
		}

		self::assertSame(300, OpportunityScheduler::CHECK_INTERVAL);
	}
}
