<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Serp;

use OsfSeo\Market\MarketTaskRepository;
use OsfSeo\Serp\RankChange;
use OsfSeo\Serp\SerpRun;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Pomiar od zlecenia do zapisu: treść task_post, paczki po 100 zadań, odbiór (gotowe, w kolejce, brak wyników,
 * wygaśnięcie), pełne TOP100 w słownikach, Pozycja SERP projektu (z subdomenami, bez wyróżnionego fragmentu jako #1),
 * zmiany i historia w jednym kontekście.
 */
final class SerpMeasurementTest extends SerpTestCase
{
	public function test_adding_keywords_and_planning_never_call_the_api(): void
	{
		$context = $this->trackedProject(['buty damskie', 'Buty  Damskie', 'żółte buty']);
		$plan = $this->serp->plan($context);

		self::assertSame(2, $plan->tracked, 'Ta sama fraza rynkowa (wielkość liter, spacje) monitorowana raz.');
		self::assertSame(2, $plan->tasks());
		self::assertSame(1, $plan->posts());
		self::assertEqualsWithDelta(2 * self::TOP100_COST, $plan->estimatedCost(), 1e-9);
		self::assertTrue($plan->toArray()['dry_run']);
		self::assertSame(0, $plan->toArray()['api_requests']);
		self::assertSame([], $this->dataForSeoRequests(), 'Dodanie fraz i plan bez żadnego żądania.');
		self::assertSame(0, self::tableCount('market_tasks'));
	}

	public function test_measurement_posts_standard_tasks_and_stores_full_top100(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$items = DataForSeoFakes::serpTop([3 => 'konkurent.pl', 7 => 'blog.example.pl', 12 => 'www.example.pl']);
		$run = $this->measure($context, ['buty damskie' => $items]);

		$bodies = $this->serpPostBodies();
		self::assertCount(1, $bodies);
		self::assertCount(1, $bodies[0]);
		$task = $bodies[0][0];
		self::assertSame('buty damskie', $task['keyword']);
		self::assertSame(2616, $task['location_code']);
		self::assertSame('pl', $task['language_code']);
		self::assertSame('desktop', $task['device']);
		self::assertSame('windows', $task['os']);
		self::assertSame(100, $task['depth']);
		self::assertSame(10, $task['max_crawl_pages']);
		self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $task['tag']);
		self::assertSame(['srsltid'], $task['remove_from_url']);

		foreach (['priority', 'calculate_rectangles', 'load_async_ai_overview', 'people_also_ask_click_depth', 'postback_url', 'pingback_url'] as $paid) {
			self::assertArrayNotHasKey($paid, $task, 'Bez płatnych opcji i bez priorytetu: ' . $paid);
		}

		self::assertSame(SerpRun::COMPLETED, $run->status);
		self::assertSame(1, $run->tasksCompleted);
		self::assertSame(100, self::tableCount('serp_results'), 'Pełne TOP100 — wszystkie domeny, nie tylko projekt i konkurenci.');
		self::assertSame(100, self::tableCount('serp_domains'));
		self::assertSame(100, self::tableCount('serp_urls'));
		self::assertSame(100, self::tableCount('serp_snippets'));

		$row = $this->row($context, 'buty damskie');
		self::assertSame(7, $row->rank, 'Subdomena projektu (blog.) liczy się do domeny projektu; najlepsza pozycja wygrywa.');
		self::assertSame('https://blog.example.pl/strona-7/', $row->url);
		self::assertSame(RankChange::NEW, $row->changeType);
		self::assertSame('#7', $row->rankLabel());

		$snapshot = self::db()->fetchRow('SELECT * FROM `' . self::db()->table('serp_snapshots') . '`');
		self::assertSame('completed', $snapshot['status']);
		self::assertSame('2', $snapshot['project_results'], 'Dwa wyniki rodziny domen projektu (blog. i www.).');
		self::assertSame('google.pl', $snapshot['se_domain']);
		self::assertSame('100', $snapshot['organic_count']);
		self::assertEqualsWithDelta(self::TOP100_COST, (float) $snapshot['cost'], 1e-9);

		$task = self::db()->fetchRow('SELECT * FROM `' . self::db()->table('market_tasks') . '`');
		self::assertSame(MarketTaskRepository::ENDPOINT_SERP, $task['endpoint']);
		self::assertSame('standard', $task['mode']);
		self::assertSame('completed', $task['status']);
		self::assertSame('1', $task['keywords_count']);
		self::assertEqualsWithDelta(self::TOP100_COST, (float) $task['cost'], 1e-9, 'Koszt zgłoszony przez dostawcę jest rozstrzygający.');

		$requests = array_map(static fn (array $request): string => $request['method'] . ' ' . $request['url'], $this->dataForSeoRequests());
		self::assertSame(1, count(array_filter($requests, static fn (string $line): bool => str_starts_with($line, 'POST '))), 'Jedno płatne zlecenie.');
	}

	public function test_featured_snippet_is_stored_separately_and_never_counted_as_first_position(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$items = [
			DataForSeoFakes::serpOrganic(1, 'example.pl', 'https://example.pl/poradnik/', 1, ['type' => 'featured_snippet']),
			...DataForSeoFakes::serpTop([5 => 'example.pl'], 100, 1),
		];
		$this->measure($context, ['buty damskie' => $items]);

		$row = $this->row($context, 'buty damskie');
		self::assertSame(5, $row->rank, 'Pozycja SERP = rank_group wyniku organicznego, nie wyróżniony fragment.');
		self::assertSame(6, $row->rankAbsolute);
		self::assertTrue($row->featured);
		self::assertSame(101, self::tableCount('serp_results'));
		self::assertSame(1, self::tableCount('serp_results', 'result_type = 2'));
	}

	public function test_keyword_outside_top100_is_out_not_zero(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->measure($context, ['buty damskie' => DataForSeoFakes::serpTop([])]);

		$row = $this->row($context, 'buty damskie');
		self::assertNull($row->rank);
		self::assertFalse($row->found);
		self::assertSame('Poza TOP100', $row->rankLabel());
		self::assertSame(['buty damskie'], $this->positionsList($context, ['band' => 'out']));
		self::assertSame([], $this->positionsList($context, ['band' => 'found']));
	}

	public function test_changes_between_comparable_measurements(): void
	{
		$context = $this->trackedProject(['awans', 'spadek', 'wejscie', 'wypadniecie', 'top10']);
		$this->measure($context, [
			'awans' => DataForSeoFakes::serpTop([12 => 'example.pl']),
			'spadek' => DataForSeoFakes::serpTop([4 => 'example.pl']),
			'wejscie' => DataForSeoFakes::serpTop([]),
			'wypadniecie' => DataForSeoFakes::serpTop([40 => 'example.pl']),
			'top10' => DataForSeoFakes::serpTop([9 => 'example.pl']),
		]);
		$this->measureNextWeek($context, [
			'awans' => DataForSeoFakes::serpTop([7 => 'example.pl']),
			'spadek' => DataForSeoFakes::serpTop([9 => 'example.pl']),
			'wejscie' => DataForSeoFakes::serpTop([55 => 'example.pl']),
			'wypadniecie' => DataForSeoFakes::serpTop([]),
			'top10' => DataForSeoFakes::serpTop([11 => 'example.pl']),
		]);

		$up = $this->row($context, 'awans');
		self::assertSame([RankChange::UP, 5, 'entered', '+5'], [$up->changeType, $up->changeValue, $up->top10Change, $up->changeLabel()]);
		$down = $this->row($context, 'spadek');
		self::assertSame([RankChange::DOWN, -5, null], [$down->changeType, $down->changeValue, $down->top10Change]);
		$entered = $this->row($context, 'wejscie');
		self::assertSame([RankChange::ENTERED, null, 'Weszła do TOP100'], [$entered->changeType, $entered->changeValue, $entered->changeLabel()]);
		$left = $this->row($context, 'wypadniecie');
		self::assertSame([RankChange::LEFT, null, 'Wypadła z TOP100'], [$left->changeType, $left->changeValue, $left->changeLabel()]);
		$top10 = $this->row($context, 'top10');
		self::assertSame([RankChange::DOWN, -2, 'left'], [$top10->changeType, $top10->changeValue, $top10->top10Change]);

		self::assertSame(['awans'], $this->positionsList($context, ['change' => 'top10_entered']));
		self::assertSame(['top10'], $this->positionsList($context, ['change' => 'top10_left']));
		self::assertSame(['wypadniecie'], $this->positionsList($context, ['change' => 'left']));
		self::assertSame(['awans', 'spadek', 'top10', 'wejscie', 'wypadniecie'], $this->positionsList($context, ['sort' => 'rank']), 'Sortowanie po Pozycji SERP, poza TOP na końcu.');

		$detail = $this->serp->keyword($context, $up->publicId);
		self::assertSame([7, 12], array_map(static fn (array $item): ?int => $item['project_rank'] === null ? null : (int) $item['project_rank'], $detail['history']), 'Historia od najnowszych.');
	}

	public function test_measurement_in_another_context_is_not_compared(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->measure($context, ['buty damskie' => DataForSeoFakes::serpTop([8 => 'example.pl'])]);
		$this->serp->saveSettings($context, ['enabled' => '0', 'depth' => '10', 'device' => 'desktop', 'frequency' => 'weekly']);
		$this->measureNextWeek($context, ['buty damskie' => DataForSeoFakes::serpTop([3 => 'example.pl'], 10)]);

		$row = $this->row($context, 'buty damskie');
		self::assertSame(3, $row->rank);
		self::assertSame(10, $row->depth);
		self::assertSame(RankChange::INCOMPARABLE, $row->changeType, 'TOP10 i TOP100 to różne konteksty — bez liczbowej zmiany.');
		self::assertNull($row->changeValue);
		self::assertSame(1, $this->serpPostBodies()[1][0]['max_crawl_pages']);
		self::assertSame(2, self::tableCount('serp_contexts'));
	}

	public function test_more_than_one_hundred_keywords_are_split_into_posts_of_one_hundred(): void
	{
		$keywords = array_map(static fn (int $i): string => 'fraza testowa ' . $i, range(1, 150));
		$context = $this->trackedProject($keywords);
		$this->mockSerpPost();
		$run = $this->queueAndSubmit($context);

		self::assertSame([100, 50], array_map('count', $this->serpPostBodies()));
		self::assertSame(150, $run->tasksSubmitted);
		self::assertSame(2, self::tableCount('market_tasks', "endpoint = 'google_organic_serp'"));
		self::assertSame(150, (int) self::db()->fetchValue('SELECT SUM(keywords_count) FROM `' . self::db()->table('market_tasks') . '`'));
		self::assertSame(150, count(array_unique(array_merge(...array_map(static fn (array $body): array => array_column($body, 'tag'), $this->serpPostBodies())))), 'Każde zadanie ma własny tag.');
	}

	public function test_pending_task_is_rescheduled_then_collected(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->mockSerpPost();
		$this->queueAndSubmit($context);
		$task = array_values($this->serpTasks)[0];
		$this->clock->advance(600);
		$this->google->json(self::SERP_GET . $task['id'], 200, DataForSeoFakes::envelope(['id' => $task['id'], 'status_code' => 40602, 'status_message' => 'Task In Queue.', 'result' => null]));

		$report = $this->serp->collect(60.0);
		self::assertSame(1, $report['pending']);
		self::assertSame('submitted', self::db()->fetchValue('SELECT status FROM `' . self::db()->table('serp_snapshots') . '`'));

		$this->clock->advance(300);
		self::assertSame(0, $this->serp->collect(60.0)['checked'], 'Kolejna próba dopiero po odstępie.');

		$this->clock->advance(600);
		$this->mockSerpResults(['buty damskie' => DataForSeoFakes::serpTop([2 => 'example.pl'])]);
		self::assertSame(1, $this->serp->collect(60.0)['completed']);
		self::assertSame(2, $this->row($context, 'buty damskie')->rank);
	}

	public function test_ready_list_speeds_up_collection(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->mockSerpPost();
		$this->queueAndSubmit($context);
		$task = array_values($this->serpTasks)[0];
		$this->clock->advance(60);
		$this->google->json(self::SERP_READY, 200, DataForSeoFakes::serpTasksReady([[$task['id'], array_key_first($this->serpTasks)], [DataForSeoFakes::taskId(), null]]));
		$this->mockSerpResults(['buty damskie' => DataForSeoFakes::serpTop([1 => 'example.pl'])]);

		$report = $this->serp->collect(60.0);
		self::assertSame(1, $report['ready']);
		self::assertSame(1, $report['completed'], 'Gotowe zadanie odebrane przed terminem; obce zadania z listy ignorowane.');
		self::assertSame(1, $this->row($context, 'buty damskie')->rank);
	}

	public function test_no_results_completes_the_measurement_as_not_found(): void
	{
		$context = $this->trackedProject(['bardzo rzadka fraza']);
		$this->mockSerpPost();
		$this->queueAndSubmit($context);
		$task = array_values($this->serpTasks)[0];
		$this->clock->advance(600);
		$this->google->json(self::SERP_GET . $task['id'], 200, DataForSeoFakes::envelope(['id' => $task['id'], 'status_code' => 40102, 'status_message' => 'No Search Results.', 'result' => null]));

		self::assertSame(1, $this->serp->collect(60.0)['completed']);
		$row = $this->row($context, 'bardzo rzadka fraza');
		self::assertFalse($row->found);
		self::assertSame(0, self::tableCount('serp_results'));
	}

	public function test_task_never_ready_expires_after_configured_hours(): void
	{
		putenv('OSF_SEO_SERP_EXPIRE_HOURS=24');
		$this->buildServices();
		$context = $this->trackedProject(['buty damskie']);
		$this->mockSerpPost();
		$run = $this->queueAndSubmit($context);
		$task = array_values($this->serpTasks)[0];
		$this->google->always(self::SERP_GET . $task['id'], ['status' => 200, 'json' => DataForSeoFakes::envelope(['id' => $task['id'], 'status_code' => 40602, 'status_message' => 'Task In Queue.', 'result' => null])]);

		for ($i = 0; $i < 6; $i++) {
			$this->clock->advance(7200);
			$this->serp->collect(60.0);
		}

		self::assertSame('submitted', self::db()->fetchValue('SELECT status FROM `' . self::db()->table('serp_snapshots') . '`'));
		$this->clock->advance(13 * 3600);
		$report = $this->serp->collect(60.0);

		self::assertSame(1, $report['expired']);
		self::assertSame(SerpRun::FAILED, $this->serpRuns->findById($run->id)->status);
		self::assertSame('—', $this->row($context, 'buty damskie')->rankLabel(), 'Wygasły pomiar nie jest „poza TOP”.');
	}

	public function test_older_result_collected_after_newer_one_does_not_replace_current_state(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->mockSerpPost();
		$this->queueAndSubmit($context);
		$older = array_values($this->serpTasks)[0];
		$olderTime = $this->clock->now()->modify('+5 minutes')->format('Y-m-d H:i:s') . ' +00:00';
		$pending = DataForSeoFakes::envelope(['id' => $older['id'], 'status_code' => 40602, 'status_message' => 'Task In Queue.', 'result' => null]);

		$this->clock->advance(7 * 3600);
		$this->google->json(self::SERP_GET . $older['id'], 200, $pending);
		$this->queueAndSubmit($context);
		$newer = array_values($this->serpTasks)[1];
		$this->clock->advance(600);
		$this->google->json(self::SERP_GET . $newer['id'], 200, DataForSeoFakes::serpResult($newer['id'], 'buty damskie', DataForSeoFakes::serpTop([4 => 'example.pl']), $this->clock->now()->format('Y-m-d H:i:s') . ' +00:00'));
		$this->serp->collect(60.0);
		self::assertSame([4, RankChange::NEW], [$this->row($context, 'buty damskie')->rank, $this->row($context, 'buty damskie')->changeType]);

		$this->clock->advance(3600);
		$this->google->json(self::SERP_GET . $older['id'], 200, DataForSeoFakes::serpResult($older['id'], 'buty damskie', DataForSeoFakes::serpTop([9 => 'example.pl']), $olderTime));
		self::assertSame(1, $this->serp->collect(60.0)['completed']);

		$row = $this->row($context, 'buty damskie');
		self::assertSame(4, $row->rank, 'Bieżąca Pozycja SERP = najnowszy pomiar wg daty sprawdzenia, nie kolejności odbioru.');
		self::assertSame([9, RankChange::UP, 5], [$row->previousRank, $row->changeType, $row->changeValue]);
	}

	public function test_dictionaries_are_shared_and_do_not_burn_ids(): void
	{
		$context = $this->trackedProject(['pierwsza', 'druga']);
		$items = DataForSeoFakes::serpTop([1 => 'example.pl']);
		$this->measure($context, ['pierwsza' => $items, 'druga' => $items]);

		self::assertSame(200, self::tableCount('serp_results'));
		self::assertSame(100, self::tableCount('serp_domains'), 'Te same domeny i adresy w obu SERP-ach — jeden wpis słownika.');
		self::assertSame(100, self::tableCount('serp_urls'));
		self::assertSame(100, (int) self::db()->fetchValue('SELECT MAX(id) FROM `' . self::db()->table('serp_domains') . '`') - (int) self::db()->fetchValue('SELECT MIN(id) FROM `' . self::db()->table('serp_domains') . '`') + 1);
	}
}
