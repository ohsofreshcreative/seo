<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Opportunities;

use OsfSeo\Opportunities\AnalysisResult;
use OsfSeo\Opportunities\Opportunity;
use OsfSeo\Opportunities\OpportunityState;
use OsfSeo\Opportunities\OpportunityStatus;
use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Opportunities\Stats;

final class OpportunityAnalyzerTest extends OpportunitiesTestCase
{
	public function test_analysis_detects_and_persists_all_types_from_gsc_tables(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);

		$results = $this->analyze($context, periods: [28]);

		self::assertSame(AnalysisResult::SUCCESS, $results[0]->status);
		$byType = [];

		foreach ($this->listed($context) as $opportunity) {
			$byType[$opportunity->type->value][] = $opportunity->title();
		}

		self::assertSame(['/buty/'], $byType['low_ctr']);
		self::assertSame(['/buty/'], $byType['near_top']);
		self::assertEqualsCanonicalizing(['/sandaly/', '/kozaki/'], $byType['decline'], '„kozaki”: średnia pozycja 5,0 → 7,2.');
		self::assertSame(['/kozaki/ ↔ /buty/'], $byType['cannibalization'], '„trampki” z adresem #opinie to ta sama podstrona.');

		foreach ($this->listed($context) as $opportunity) {
			self::assertSame(OpportunityStatus::New, $opportunity->status);
			self::assertSame(OpportunityState::Active, $opportunity->state);
			self::assertSame('sc-domain:example.pl', $opportunity->property);
			self::assertSame('2026-03-28', $opportunity->latestDate);
		}
	}

	public function test_sql_aggregation_uses_weighted_position_and_ctr_from_sums(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);

		$nearTop = $this->findByType($context, OpportunityType::NearTop);
		$item = $nearTop->evidence['keywords'][0];
		$current = Stats::fromArray($item['current']);

		self::assertSame('buty zimowe', $item['keyword']);
		self::assertSame(30, $current->clicks);
		self::assertSame(1500, $current->impressions);
		self::assertEqualsWithDelta(4.8, $current->position(), 1e-9, '(500 × 4,0 + 1000 × 5,2) / 1500 — nie AVG(4,0; 5,2) = 4,6.');
		self::assertEqualsWithDelta(0.02, $current->ctr(), 1e-12, '30 / 1500 — nie średnia CTR dni (2% i 2%).');
		self::assertSame(['current' => ['2026-03-01', '2026-03-28'], 'previous' => ['2026-02-01', '2026-02-28']], array_intersect_key($nearTop->evidence['period'], ['current' => 1, 'previous' => 1]));
	}

	public function test_reanalysis_does_not_duplicate_and_keeps_identity(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context);
		$before = array_map(static fn (Opportunity $o): string => $o->publicId, $this->listed($context));
		$count = self::rows('opportunities', $context->projectId());

		self::assertSame(AnalysisResult::UNCHANGED, $this->analyze($context, periods: [28])[0]->status, 'Te same dane — bez ponownego liczenia.');
		self::assertSame(AnalysisResult::SUCCESS, $this->analyze($context, true, [28])[0]->status);

		self::assertSame($count, self::rows('opportunities', $context->projectId()));
		self::assertSame($before, array_map(static fn (Opportunity $o): string => $o->publicId, $this->listed($context)));
	}

	public function test_fingerprint_survives_reimport_with_new_dictionary_ids(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);
		$lowCtr = $this->findByType($context, OpportunityType::LowCtr);

		// Ponowny import tej samej property: nowe ID fraz i adresów w słownikach.
		$db = self::db();

		foreach (['gsc_query_daily', 'gsc_query_page_daily', 'gsc_site_daily', 'keywords', 'pages'] as $table) {
			$db->execute("DELETE FROM `{$db->table($table)}` WHERE project_id = %d", [$context->projectId()]);
		}

		$this->seedScenario($context);
		$this->analyze($context, true, [28]);

		self::assertSame($lowCtr->publicId, $this->findByType($context, OpportunityType::LowCtr)->publicId);
	}

	public function test_manual_status_and_note_survive_reanalysis(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context);
		$lowCtr = $this->findByType($context, OpportunityType::LowCtr);

		$this->opportunities->update($context, $lowCtr->publicId, ['status' => 'planned', 'note' => 'Nowy title w przyszłym tygodniu.'], '2026-03-30');
		$this->analyze($context, true);

		$after = $this->opportunities->find($context, $lowCtr->publicId);
		self::assertSame(OpportunityStatus::Planned, $after->status);
		self::assertSame('Nowy title w przyszłym tygodniu.', $after->note);
		self::assertNotNull($after->statusChangedAt);
	}

	public function test_disappearing_signal_becomes_inactive_and_returns_with_history(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);
		$decline = $this->findByType($context, OpportunityType::Decline, ['q' => 'sandały']);
		$this->opportunities->update($context, $decline->publicId, ['status' => 'in_progress', 'note' => 'Sprawdzamy.'], '2026-03-30');

		// Sygnał znika: poprzedni okres „sandałów” bez wyświetleń → brak spadku.
		$this->deleteKeywordFacts($context, 'sandały', '2026-02-01', '2026-02-28');
		$this->analyze($context, true, [28]);

		$inactive = $this->opportunities->find($context, $decline->publicId);
		self::assertSame(OpportunityState::Inactive, $inactive->state);
		self::assertNotNull($inactive->inactiveSince);
		self::assertSame(OpportunityStatus::InProgress, $inactive->status, 'Stan pracy zostaje.');
		self::assertFalse($inactive->detectedInPeriod);
		self::assertSame('sandały', $inactive->evidence['keywords'][0]['keyword'], 'Ostatni snapshot dowodów zostaje.');
		self::assertNull($this->findByType($context, OpportunityType::Decline, ['q' => 'sandały']), 'Nieaktywne nie są na liście aktywnych.');
		self::assertSame([$decline->publicId], array_map(static fn (Opportunity $o): string => $o->publicId, $this->listed($context, ['state' => 'inactive'])));

		// Sygnał wraca → ta sama szansa znów aktywna, z zachowaną historią.
		$this->seed($context, [['sandały', 'https://example.pl/sandaly/', '2026-02-11', 50, 1000, 3.0]]);
		$this->analyze($context, true, [28]);
		$active = $this->opportunities->find($context, $decline->publicId);

		self::assertSame(OpportunityState::Active, $active->state);
		self::assertNull($active->inactiveSince);
		self::assertSame('Sprawdzamy.', $active->note);
		self::assertSame($decline->firstDetectedAt, $active->firstDetectedAt);
	}

	public function test_project_isolation(): void
	{
		$first = $this->readyProject('example.pl');
		$second = $this->readyProject('drugi.pl');
		$this->seedScenario($first);
		$this->seed($second, [['inna fraza', 'https://drugi.pl/x/', '2026-03-10', 0, 3000, 1.2]]);
		$this->analyze($first);
		$this->analyze($second);

		$firstIds = array_map(static fn (Opportunity $o): string => $o->publicId, $this->listed($first));
		$secondIds = array_map(static fn (Opportunity $o): string => $o->publicId, $this->listed($second));

		self::assertNotEmpty($firstIds);
		self::assertNotEmpty($secondIds);
		self::assertSame([], array_intersect($firstIds, $secondIds));

		foreach ($this->listed($second) as $opportunity) {
			self::assertStringContainsString('drugi.pl', (string) $opportunity->pageUrl);
		}

		// Szansa innego projektu przez kontekst tego projektu — „nie znaleziono” (także przy próbie zmiany).
		$this->expectException(\OsfSeo\Opportunities\OpportunityNotFound::class);
		$this->opportunities->update($first, $secondIds[0], ['status' => 'dismissed'], '2026-03-30');
	}

	public function test_property_reset_archives_opportunities_and_new_property_starts_fresh(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context);
		$old = $this->listed($context);
		$this->opportunities->update($context, $old[0]->publicId, ['status' => 'review'], '2026-03-30');

		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);
		$context = $this->properties->select($context, 'https://www.example.pl/', true);

		self::assertSame([], $this->listed($context), 'Szanse starej property nie są aktualnymi rekomendacjami.');
		self::assertSame(0, self::rows('opportunity_detections', $context->projectId()));
		self::assertSame(0, self::rows('opportunity_analyses', $context->projectId()));
		$archived = $this->listed($context, ['state' => 'archived']);
		self::assertCount(count($old), $archived, 'Historia i stan pracy zostają w archiwum.');
		self::assertSame(OpportunityStatus::Review, $this->opportunities->find($context, $old[0]->publicId)->status);

		// Nowa property: nowe dane → nowe szanse (inny odcisk), archiwum bez zmian.
		$context = $context->withProject($this->projects->reload($context->project()));
		$this->mockSitesAndMarkData($context, 'https://www.example.pl/');
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);
		$new = $this->listed($context);

		self::assertNotEmpty($new);
		self::assertSame([], array_intersect(array_map(static fn (Opportunity $o): string => $o->publicId, $new), array_map(static fn (Opportunity $o): string => $o->publicId, $archived)));
		self::assertSame('https://www.example.pl/', $new[0]->property);
		self::assertCount(count($old), $this->listed($context, ['state' => 'archived']));
	}

	public function test_analysis_is_aborted_when_property_changes_before_commit(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		self::db()->execute("UPDATE `" . self::db()->table('projects') . "` SET gsc_property = 'https://inna.pl/' WHERE id = %d", [$context->projectId()]);

		$results = $this->analyze($context, periods: [28]);

		self::assertSame(AnalysisResult::SKIPPED, $results[0]->status);
		self::assertSame(0, self::rows('opportunities', $context->projectId()));
	}

	public function test_periods_and_missing_history(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);

		$results = [];

		foreach ($this->analyze($context) as $result) {
			$results[$result->days] = $result;
		}

		self::assertSame([28, 7, 90], array_keys($results));
		self::assertSame(AnalysisResult::SUCCESS, $results[28]->status);
		self::assertSame(AnalysisResult::SUCCESS, $results[7]->status, '7 dni: 2026-03-22..28 w pełni zaimportowane.');
		self::assertSame(AnalysisResult::SKIPPED, $results[90]->status, '90 dni wymaga danych od 2025-12-29.');
		self::assertSame('insufficient_history', $results[90]->reason);

		$analyses = $this->opportunities->analyses($context);
		self::assertSame('skipped', $analyses[90]['status']);
		self::assertSame('2026-03-28', $analyses[28]['latest_date']);
	}

	public function test_missing_previous_period_lowers_confidence_and_disables_declines(): void
	{
		$context = $this->readyProject();
		// Dane tylko od 2026-03-01: bieżący okres 28 dni pełny, poprzedni — brak.
		$this->seed($context, [
			['buty damskie', 'https://example.pl/buty/', '2026-03-10', 20, 2000, 2.0],
			['sandały', 'https://example.pl/sandaly/', '2026-03-11', 20, 900, 3.2],
		], '2026-03-01');

		$this->analyze($context, periods: [28]);
		$lowCtr = $this->findByType($context, OpportunityType::LowCtr);

		self::assertNull($this->findByType($context, OpportunityType::Decline));
		self::assertFalse($lowCtr->evidence['period']['previous_covered']);
		self::assertSame('not_covered', $lowCtr->evidence['confidence']['factors'][1]['reason']);
	}

	public function test_page_view_groups_opportunities_by_landing_page(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);

		$page = $this->opportunities->list($context, \OsfSeo\Opportunities\OpportunityFilters::fromInput(['view' => 'pages', 'status' => 'all']));
		$byUrl = [];

		foreach ($page->groups as $group) {
			$byUrl[(string) $group['page_url']] = array_map(static fn (OpportunityType $type): string => $type->value, $group['types']);
			self::assertCount($group['count'], $group['opportunities']);
		}

		self::assertSame(['low_ctr', 'near_top'], $byUrl['https://example.pl/buty/'], 'Jedna podstrona, dwie szanse różnych typów.');
		self::assertSame(['cannibalization', 'decline'], $byUrl['https://example.pl/kozaki/']);
		self::assertSame(count($page->groups), $page->total);
	}

	public function test_filters_are_applied_in_sql(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context, periods: [28]);
		$decline = $this->findByType($context, OpportunityType::Decline, ['q' => 'sandały']);
		$this->opportunities->update($context, $decline->publicId, ['status' => 'dismissed'], '2026-03-30');

		self::assertSame(['decline', 'decline'], array_map(static fn (Opportunity $o): string => $o->type->value, $this->listed($context, ['type' => 'decline'])));
		self::assertNotContains($decline->publicId, array_map(static fn (Opportunity $o): string => $o->publicId, $this->opportunities->list($context, \OsfSeo\Opportunities\OpportunityFilters::fromInput([]))->rows), 'Domyślnie tylko otwarte.');
		self::assertSame([$decline->publicId], array_map(static fn (Opportunity $o): string => $o->publicId, $this->listed($context, ['status' => 'dismissed'])));
		self::assertSame(['/kozaki/ ↔ /buty/'], array_map(static fn (Opportunity $o): string => $o->title(), $this->listed($context, ['q' => 'kozaki', 'type' => 'cannibalization'])));

		foreach ($this->listed($context, ['priority' => 50]) as $opportunity) {
			self::assertGreaterThanOrEqual(50, $opportunity->priority);
		}

		$summary = $this->opportunities->summary($context, 28);
		self::assertSame(['total' => 2, 'open' => 1], $summary['types']['decline']);
	}

	/** Property wybrana po resecie — dane zapisane w testach bez importu, więc znacznik źródła danych ustawiamy wprost. */
	private function mockSitesAndMarkData(\OsfSeo\Auth\ProjectContext $context, string $property): void
	{
		self::db()->execute("UPDATE `" . self::db()->table('projects') . "` SET gsc_data_property = %s WHERE id = %d", [$property, $context->projectId()]);
	}
}
