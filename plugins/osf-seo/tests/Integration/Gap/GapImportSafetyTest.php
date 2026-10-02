<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Gap;

use mysqli;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Gap\GapConfig;
use OsfSeo\Gap\GapDomain;
use OsfSeo\Gap\GapRun;
use OsfSeo\Gap\GapService;
use OsfSeo\Gap\GapStartResult;
use OsfSeo\Gap\PlannedTarget;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Tests\Support\DataForSeoFakes;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;

/**
 * Bezpieczeństwo importu zbiorów domen (review STEP 15): odświeżenie przerwane, wstrzymane albo niespójne nie traci
 * poprzedniego zbioru ani nie zapisuje fałszywej utraty fraz; nieobecność blisko granicy wolumenu nie jest utratą;
 * dwa projekty z tą samą domeną płacą za import raz, a utrzymanie nie domyka importu w trakcie zapisu strony.
 */
final class GapImportSafetyTest extends GapTestCase
{
	private const DOMAIN = 'konkurent.pl';

	/** Frazy zbioru: 2 strony po 1000 (najmniej, by sprawdzić stronicowanie). */
	private const ROWS = 1200;

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function rows(string $domain, int $count, int $startVolume = 5000, int $rank = 5): array
	{
		$rows = [];

		for ($i = 0; $i < $count; $i++) {
			$rows[] = self::ranked($domain, sprintf('fraza %s %04d', explode('.', $domain)[0], $i), max(10, $startVolume - $i), $rank);
		}

		return $rows;
	}

	/**
	 * Kompletny pierwszy import (1 200 fraz, 2 strony), po 31 dniach dostawca ma 1 100 fraz (100 najpopularniejszych
	 * wypadło — przy wiarygodnym odświeżeniu byłyby utracone).
	 *
	 * @return array{0: ProjectContext, 1: GapDomain, 2: list<array<string, mixed>>}
	 */
	private function completeDatasetThenShrink(): array
	{
		$context = $this->gapProject();
		$this->setRanked(self::DOMAIN, self::rows(self::DOMAIN, self::ROWS));
		$first = $this->gapRun($context, ['baseline' => '0']);
		self::assertSame(GapRun::COMPLETED, $first->status);
		$dataset = $this->dataset($context);
		self::assertTrue($dataset->complete);
		self::assertSame(10, $dataset->coveredMinVolume);

		$this->clock->advance(31 * 86400);
		$refreshed = array_slice($this->ranked[self::DOMAIN], 100);
		$this->setRanked(self::DOMAIN, $refreshed);

		return [$context, $dataset, $this->ranked[self::DOMAIN]];
	}

	private function dataset(ProjectContext $context, string $domain = self::DOMAIN): GapDomain
	{
		return $this->gapDomains->find($this->gaps->market($context), $domain) ?? throw new \RuntimeException('No dataset ' . $domain);
	}

	/**
	 * Zbiór zachował stan poprzedniego udanego importu, a frazy, które wypadły, nie są utracone.
	 */
	private function assertPreviousDatasetKept(GapDomain $before, GapDomain $after, int $runId): void
	{
		self::assertSame(GapDomain::PARTIAL, $after->status);
		self::assertTrue($after->complete, 'Kompletność poprzedniego importu zostaje.');
		self::assertSame($before->coveredMinVolume, $after->coveredMinVolume);
		self::assertSame($before->coverage?->toArray(), $after->coverage?->toArray());
		self::assertSame($before->importedAt, $after->importedAt);
		self::assertSame($before->staleAfter, $after->staleAfter, 'Świeżość bez zmian — kolejny plan pobierze zbiór ponownie.');
		self::assertArrayNotHasKey('lost', $this->gapDomains->eventCounts($before->id, $runId));
		self::assertSame(0, self::tableCount('gap_domain_events', "event = 'lost'"));
		self::assertSame(0, self::tableCount('gap_domain_keywords', 'present <> 1'), 'Żadna fraza nie wypadła ze zbioru.');
		self::assertGreaterThanOrEqual(self::ROWS, self::tableCount('gap_domain_keywords', 'present = 1'));
		self::assertSame('1', $this->datasetRow(self::DOMAIN, 'fraza konkurent 0000')['present']);
	}

	private function target(int $runId): array
	{
		$db = self::db();

		return $db->fetchRow("SELECT * FROM `{$db->table('gap_run_targets')}` WHERE run_id = %d", [$runId]) ?? [];
	}

	public function test_paused_then_cancelled_refresh_keeps_previous_complete_dataset_and_marks_nothing_lost(): void
	{
		[$context, $before] = $this->completeDatasetThenShrink();
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=0.2');

		$paused = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame([GapRun::PAUSED, 'daily_limit'], [$paused->status, $paused->blockedBy]);
		$during = $this->dataset($context);
		self::assertSame(GapDomain::IMPORTING, $during->status);
		self::assertTrue($during->complete);
		self::assertSame(10, $during->coveredMinVolume);
		self::assertSame(0, self::tableCount('gap_domain_events', "event = 'lost'"), 'Wstrzymany import niczego nie oznacza jako utraconego.');
		self::assertSame('1', $this->datasetRow(self::DOMAIN, 'fraza konkurent 0000')['present']);
		$this->refresher->refresh($context->projectId(), true);
		self::assertSame('1', $this->gap($context, 'fraza konkurent 0000')['active'], 'Analiza nadal korzysta z poprzedniego zbioru.');

		self::assertTrue($this->gaps->cancel($context, $paused->publicId));

		$this->assertPreviousDatasetKept($before, $this->dataset($context), $paused->id);
		self::assertSame(['partial', 'cancelled'], [$this->target($paused->id)['status'], $this->target($paused->id)['error_code']]);
		$this->refresher->refresh($context->projectId(), true);
		self::assertSame('1', $this->gap($context, 'fraza konkurent 0000')['active']);

		// Dopiero kompletne, spójne odświeżenie zapisuje utratę.
		putenv(MarketDataConfig::DAILY_COST_LIMIT);
		$this->clock->advance(GapConfig::MANUAL_COOLDOWN + 1);
		$complete = $this->gapRun($context, ['baseline' => '0']);
		self::assertSame(GapRun::COMPLETED, $complete->status);
		self::assertSame(100, $this->gapDomains->eventCounts($before->id, $complete->id)['lost'] ?? 0);
		self::assertSame('0', $this->datasetRow(self::DOMAIN, 'fraza konkurent 0000')['present']);
		self::assertSame(GapDomain::READY, $this->dataset($context)->status);
	}

	public function test_refresh_failed_after_network_error_keeps_previous_dataset(): void
	{
		[$context, $before, $refreshed] = $this->completeDatasetThenShrink();
		$this->rankedOverrides[self::DOMAIN] = [
			['status' => 200, 'json' => DataForSeoFakes::rankedResult(self::DOMAIN, array_slice($refreshed, 0, 1000), 1100, 0.132)],
			new WP_Error('http_request_failed', 'Operation timed out'),
		];

		$run = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame(GapRun::PARTIAL, $run->status);
		$this->assertPreviousDatasetKept($before, $this->dataset($context), $run->id);
		self::assertSame('partial', $this->target($run->id)['status']);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function inconsistentPages(): array
	{
		return [
			'powtórzone frazy między stronami' => ['duplicates'],
			'zmiana liczby fraz u dostawcy' => ['total_changed'],
			'krótka strona przy zapowiedzi kolejnych' => ['short_page'],
			'odwrócona kolejność wolumenu' => ['order'],
			'nieczytelny wynik' => ['unreadable'],
			'pusta odpowiedź dla zbioru z frazami' => ['empty'],
		];
	}

	#[DataProvider('inconsistentPages')]
	public function test_inconsistent_refresh_never_marks_lost_and_keeps_previous_dataset(string $reason): void
	{
		[$context, $before, $refreshed] = $this->completeDatasetThenShrink();
		$page = static fn (int $offset, int $limit = 1000): array => array_slice($refreshed, $offset, $limit);
		$response = static fn (array $items, int $total, int $offset = 0): array => ['status' => 200, 'json' => DataForSeoFakes::rankedResult(self::DOMAIN, $items, $total, round(0.012 + count($items) * 0.00012, 6), $offset)];
		$unreadable = $page(0);
		$unreadable[500]['ranked_serp_element']['serp_item']['rank_group'] = null;
		$reordered = $page(1000);
		$reordered[0] = self::ranked(self::DOMAIN, 'fraza wyżej niż poprzednia strona', 9000, 3);
		$this->rankedOverrides[self::DOMAIN] = match ($reason) {
			'duplicates' => [$response($page(0), 1100), $response($page(999), 1100, 1000)],
			'total_changed' => [$response($page(0), 1100), $response($page(1000), 1150, 1000)],
			'short_page' => [$response($page(0, 600), 1100)],
			'order' => [$response($page(0), 1100), $response($reordered, 1100, 1000)],
			'unreadable' => [$response($unreadable, 1100)],
			'empty' => [$response([], 0)],
		};

		$run = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame(GapRun::PARTIAL, $run->status);
		self::assertSame([$reason, 'partial', $reason], [$this->target($run->id)['unreliable'], $this->target($run->id)['status'], $this->target($run->id)['error_code']]);
		$this->assertPreviousDatasetKept($before, $this->dataset($context), $run->id);
		self::assertNotSame($reason, GapRun::reasonLabel($reason), 'Powód ma etykietę w panelu.');

		if ($reason === 'empty') {
			// Druga z rzędu pusta odpowiedź potwierdza: domena nie ma już fraz w zakresie.
			$this->clock->advance(GapConfig::MANUAL_COOLDOWN + 1);
			$this->setRanked(self::DOMAIN, []);
			$confirmed = $this->gapRun($context, ['baseline' => '0']);
			self::assertSame(GapRun::COMPLETED, $confirmed->status);
			self::assertNull($this->target($confirmed->id)['unreliable']);
			self::assertSame(self::ROWS, $this->gapDomains->eventCounts($before->id, $confirmed->id)['lost'] ?? 0);
			self::assertSame(0, $this->dataset($context)->rowsPresent);
		}
	}

	public function test_first_import_with_inconsistent_pages_keeps_rows_but_gives_no_reliable_absence(): void
	{
		$context = $this->gapProject();
		$rows = self::rows(self::DOMAIN, 1500);
		$this->setRanked(self::DOMAIN, $rows);
		$sorted = $this->ranked[self::DOMAIN];
		$this->rankedOverrides[self::DOMAIN] = [
			['status' => 200, 'json' => DataForSeoFakes::rankedResult(self::DOMAIN, array_slice($sorted, 0, 1000), 1500, 0.132)],
			['status' => 200, 'json' => DataForSeoFakes::rankedResult(self::DOMAIN, array_slice($sorted, 1000), 1600, 0.072, 1000)],
		];

		$run = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame(GapRun::COMPLETED, $run->status, 'Pierwszy import zapisuje to, co pobrał.');
		self::assertSame(['total_changed', 'done'], [$this->target($run->id)['unreliable'], $this->target($run->id)['status']]);
		$dataset = $this->dataset($context);
		self::assertSame([GapDomain::READY, false, null, 1500], [$dataset->status, $dataset->complete, $dataset->coveredMinVolume, $dataset->rowsPresent]);
		self::assertFalse($dataset->absenceReliable(4999));
	}

	public function test_absence_near_the_volume_filter_is_unconfirmed_not_lost(): void
	{
		$context = $this->gapProject();
		$stable = self::ranked(self::DOMAIN, 'fraza stabilna', 300, 4);
		$strong = self::ranked(self::DOMAIN, 'fraza mocna', 500, 3);
		$edge = self::ranked(self::DOMAIN, 'fraza graniczna', 12, 6);
		$this->setRanked(self::DOMAIN, [$stable, $strong, $edge]);
		$this->gapRun($context, ['baseline' => '0']);
		$dataset = $this->dataset($context);
		self::assertSame(10, $dataset->coveredMinVolume);

		$this->clock->advance(31 * 86400);
		$this->setRanked(self::DOMAIN, [$stable]);
		$second = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame(['lost' => 1], $this->gapDomains->eventCounts($dataset->id, $second->id), 'Utrata tylko z zapasem nad filtrem wolumenu (≥ 15).');
		self::assertSame('0', $this->datasetRow(self::DOMAIN, 'fraza mocna')['present']);
		self::assertSame((string) GapDomain::ROW_UNCONFIRMED, $this->datasetRow(self::DOMAIN, 'fraza graniczna')['present'], 'Wolumen 12 mógł spaść poniżej filtra 10 — niepotwierdzona, nie utracona.');
		self::assertSame(1, json_decode((string) $this->target($second->id)['stats'], true)['unconfirmed']);
		self::assertSame('0', $this->gap($context, 'fraza graniczna')['active'], 'Niepotwierdzona pozycja nie jest już dowodem luki.');

		$this->clock->advance(31 * 86400);
		$this->setRanked(self::DOMAIN, [$stable, $strong, $edge]);
		$third = $this->gapRun($context, ['baseline' => '0']);

		self::assertSame(['back' => 1], $this->gapDomains->eventCounts($dataset->id, $third->id), 'Bez „powrotu” frazy, której utraty nie zapisano.');
		self::assertSame('1', $this->datasetRow(self::DOMAIN, 'fraza graniczna')['present']);
		self::assertSame('1', $this->gap($context, 'fraza graniczna')['active']);
	}

	public function test_two_projects_queued_for_the_same_domain_pay_once(): void
	{
		$first = $this->gapProject();
		$second = $this->gapProject([self::DOMAIN => 'Ten sam konkurent'], 'drugi-projekt.pl');
		$this->setRanked(self::DOMAIN, self::rows(self::DOMAIN, self::ROWS));

		$a = $this->gaps->start($first, $this->gaps->request($first, ['baseline' => '0']), GapService::TRIGGER_CLI);
		$b = $this->gaps->start($second, $this->gaps->request($second, ['baseline' => '0']), GapService::TRIGGER_CLI);
		self::assertSame([GapStartResult::QUEUED, GapStartResult::QUEUED], [$a->status, $b->status]);
		add_filter('wp_doing_cron', '__return_true');
		$this->gaps->runBackground(60.0);

		self::assertCount(2, $this->rankedBodies(), 'Jeden import zbioru (2 strony) dla obu projektów.');
		self::assertSame(2, self::tableCount('market_tasks'));
		self::assertSame([GapRun::COMPLETED, GapRun::COMPLETED], [$this->gapRuns->findById($a->run->id)->status, $this->gapRuns->findById($b->run->id)->status]);
		self::assertSame(['done', 'cached'], [$this->target($a->run->id)['status'], $this->target($b->run->id)['status']]);
		self::assertSame(self::ROWS, self::tableCount('gap_domain_keywords'));
		self::assertNotNull($this->gap($second, 'fraza konkurent 0000'));
	}

	public function test_project_waits_for_import_of_another_project_and_force_reuses_newer_data(): void
	{
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=0.2');
		$first = $this->gapProject();
		$second = $this->gapProject([self::DOMAIN => 'Ten sam konkurent'], 'drugi-projekt.pl');
		$third = $this->gapProject([self::DOMAIN => 'I ten sam'], 'trzeci-projekt.pl');
		$this->setRanked(self::DOMAIN, self::rows(self::DOMAIN, self::ROWS));

		$importing = $this->gapRun($first, ['baseline' => '0']);
		self::assertSame(GapRun::PAUSED, $importing->status, 'Import pierwszego projektu wstrzymany po pierwszej stronie.');
		self::assertSame(GapDomain::IMPORTING, $this->dataset($first)->status);

		$plan = $this->gaps->plan($second, $this->gaps->request($second, ['baseline' => '0', 'force' => '1']));
		self::assertSame(PlannedTarget::WAITING, $plan->targets[0]->state);
		self::assertSame([0, 0.0], [$plan->expectedRequests(), $plan->expectedCost()], 'Zwykle bez kosztu…');
		self::assertSame(10, $plan->requests(), '…ale maksimum obejmuje import (limit 10 000 fraz), gdyby tamten się nie udał.');
		self::assertEqualsWithDelta(1.32, $plan->estimatedCost(), 1e-6);
		$wider = $this->gaps->plan($third, $this->gaps->request($third, ['baseline' => '0', 'max_rank' => '50']));
		self::assertSame(PlannedTarget::IMPORT, $wider->targets[0]->state, 'Trwający import węższego zakresu nie wystarczy.');

		$this->clock->advance(60);
		$waiting = $this->gaps->start($second, $plan->request, GapService::TRIGGER_CLI, $plan->requests(), $plan->estimatedCost());
		self::assertSame(GapStartResult::QUEUED, $waiting->status);
		self::assertFalse($this->gapDomains->claimImport($this->dataset($first)->id, $waiting->run->id), 'Zbiór importuje inny przebieg.');
		$this->gaps->execute($second, $waiting->run);
		self::assertCount(1, $this->rankedBodies(), 'Czekający przebieg nie wysyła żądań.');

		putenv(MarketDataConfig::DAILY_COST_LIMIT);
		$this->clock->advance(86400);
		add_filter('wp_doing_cron', '__return_true');
		$this->gaps->runBackground(60.0);

		self::assertCount(2, $this->rankedBodies(), 'Pierwszy projekt dokończył import; drugi (force, zlecony wcześniej) skorzystał bez opłaty.');
		self::assertSame('cached', $this->target($waiting->run->id)['status']);
		self::assertSame(GapRun::COMPLETED, $this->gapRuns->findById($waiting->run->id)->status);

		// `force` zlecony po zakończeniu importu — pobiera ponownie.
		$this->clock->advance(60);
		$forced = $this->gapRun($third, ['baseline' => '0', 'force' => '1']);
		self::assertSame('done', $this->target($forced->id)['status']);
		self::assertCount(4, $this->rankedBodies());
	}

	public function test_maintenance_and_cancel_cleanup_never_touch_an_import_while_paid_requests_are_locked(): void
	{
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=0.2');
		$context = $this->gapProject();
		$this->setRanked(self::DOMAIN, self::rows(self::DOMAIN, self::ROWS));
		$run = $this->gapRun($context, ['baseline' => '0']);
		self::assertSame(GapRun::PAUSED, $run->status);
		$db = self::db();
		// Inny proces właśnie zapisuje kolejną stronę: zadanie dostawcy już zakończone, znacznik żądania jeszcze ustawiony.
		$taskId = $this->tasks->create('dataforseo', $this->rankedProvider->endpoint(), 'gap', $context->projectId(), $this->gaps->market($context), [self::DOMAIN], 0.132);
		$this->tasks->markCompleted($taskId, 1000, 0.132);
		$db->execute("UPDATE `{$db->table('gap_run_targets')}` SET inflight_task_id = %d WHERE run_id = %d", [$taskId, $run->id]);
		add_filter('wp_doing_cron', '__return_true');
		$other = $this->lockPaidRequests();

		try {
			$report = $this->gaps->runBackground(60.0);
			self::assertNull($report['maintenance'], 'Utrzymanie czeka na blokadę płatnych żądań.');
			self::assertSame((string) $taskId, $this->target($run->id)['inflight_task_id']);
			self::assertSame(GapDomain::IMPORTING, $this->dataset($context)->status);

			$db->execute("UPDATE `{$db->table('gap_run_targets')}` SET inflight_task_id = NULL WHERE run_id = %d", [$run->id]);
			self::assertTrue($this->gaps->cancel($context, $run->publicId));
			self::assertSame('running', $this->target($run->id)['status'], 'Anulowanie nie domyka importu w trakcie cudzej strony.');
			self::assertSame(GapDomain::IMPORTING, $this->dataset($context)->status);
		} finally {
			$this->unlock($other);
		}

		$report = $this->gaps->runBackground(60.0);

		self::assertSame(1, $report['maintenance']['closed'], 'Po zwolnieniu blokady utrzymanie domyka anulowany import.');
		self::assertSame(['partial', 'cancelled'], [$this->target($run->id)['status'], $this->target($run->id)['error_code']]);
		self::assertSame(GapDomain::PARTIAL, $this->dataset($context)->status);
		self::assertSame(1000, self::tableCount('gap_domain_keywords'));
	}

	private function lockPaidRequests(): mysqli
	{
		$host = DB_HOST;
		$port = null;
		$socket = null;

		if (str_contains($host, ':')) {
			[$host, $suffix] = explode(':', $host, 2);
			is_numeric($suffix) ? $port = (int) $suffix : $socket = $suffix;
		}

		$other = new mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket);
		$lock = self::db()->lockName(MarketSyncService::LOCK);
		self::assertSame('1', (string) $other->query(sprintf("SELECT GET_LOCK('%s', 0)", $other->real_escape_string($lock)))->fetch_row()[0]);

		return $other;
	}

	private function unlock(mysqli $other): void
	{
		$other->query(sprintf("SELECT RELEASE_LOCK('%s')", $other->real_escape_string(self::db()->lockName(MarketSyncService::LOCK))));
		$other->close();
	}
}
