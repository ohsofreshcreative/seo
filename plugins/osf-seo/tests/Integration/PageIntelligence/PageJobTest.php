<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\PageIntelligence;

use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\Roles;
use OsfSeo\PageIntelligence\PageIntelligenceConfig;
use OsfSeo\PageIntelligence\PageJob;
use OsfSeo\PageIntelligence\PageJobService;
use OsfSeo\PageIntelligence\PageNotFound;
use OsfSeo\PageIntelligence\PageRefused;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Tests\Support\AiFakes;

/**
 * Pobieranie stron z panelu w tle (STEP 17, faza D): dozwolone zlecenie i wykonanie wyłącznie przez Page Intelligence (16), odmowa adresu
 * spoza projektu i limit 5 adresów (17), robots.txt, `Retry-After` i odstęp hosta (19), pamięć bez HTTP
 * (20), kopia nieaktualna (21), klient (23), IDOR stron, snapshotów i zleceń (28), podwójne kliknięcie, przerwany proces, lista stron
 * z filtrami i stronicowaniem w SQL, brak pobrań przy odczycie i po przeliczeniu Strategii. Ochrona SSRF zleceń z panelu na prawdziwym
 * transporcie (18) — `PageRealTransportTest`.
 */
final class PageJobTest extends PageTestCase
{
	private const TOPIC = 'pozycjonowanie stron';

	public function test_16_allowed_fetch_is_queued_without_http_and_executed_only_by_the_background_step(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());

		$plan = $this->pageJobs->plan($context, PageSelection::topic(self::TOPIC));
		self::assertSame([null, 1, true, 'missing'], [$plan['refused'], $plan['fetches'], $plan['items'][0]['allowed'], $plan['items'][0]['cache']]);
		$queued = $this->pageJobs->queue($context, PageSelection::topic(self::TOPIC), false, self::TOPIC);
		$job = $queued['job'];
		self::assertSame([false, PageJob::STATUS_QUEUED, 1, null], [$queued['existing'], $job->status, $job->itemsTotal, $job->errorCode]);
		self::assertNotNull($job->topicPublicId);
		self::assertSame([], $this->pageFetcher->requests, 'Plan i zlecenie bez żadnego żądania.');
		self::assertSame(['recovered' => 0, 'jobs' => 0, 'items' => 0], $this->pageJobs->runBackground(30.0), 'Poza procesem systemowym (żądanie WWW) krok w tle nic nie robi.');

		$result = $this->runPageWorker();
		self::assertSame([1, 1], [$result['jobs'], $result['items']]);
		$done = $this->pageJobs->job($context, $job->publicId);
		self::assertSame([PageJob::STATUS_COMPLETED, 1, 1, 'created'], [$done->status, $done->itemsDone, $done->succeeded(), $done->items[0]['outcome']]);
		self::assertCount(1, $this->pageFetcher->pageRequests());
		self::assertSame('fresh', $this->workspace->topicSection($context, self::TOPIC)['evidence']['project_page']['cache']);
		self::assertSame(1, self::pageRows('page_fetches', $context->projectId()), 'Pobranie zapisane w historii prób jak w CLI.');
		self::assertSame('panel', self::db()->fetchValue("SELECT trigger_type FROM `" . self::db()->table('page_fetches') . "` LIMIT 1"));
		self::assertSame(['recovered' => 0, 'jobs' => 0, 'items' => 0], $this->runPageWorker(), 'Zakończone zlecenie nie jest wykonywane ponownie.');
	}

	public function test_17_urls_outside_project_scope_and_too_many_urls_are_refused_as_a_whole(): void
	{
		$context = $this->aiProject();
		putenv(PageIntelligenceConfig::MAX_URLS . '=10');
		$this->buildAi();

		foreach ([
			'url_not_allowed' => ['https://obca-domena.pl/strona/'],
			'mixed' => [self::PAGE, 'https://obca-domena.pl/strona/'],
			'scheme_not_allowed' => ['ftp://example.pl/plik'],
			'too_many_urls' => array_map(static fn (int $i): string => 'https://example.pl/strona-' . $i . '/', range(1, 6)),
		] as $case => $urls) {
			$plan = $this->pageJobs->plan($context, PageSelection::urls($urls));
			self::assertNotNull($plan['refused'], $case);

			try {
				$this->pageJobs->queue($context, PageSelection::urls($urls));
				self::fail('Zlecenie odrzucone w całości: ' . $case);
			} catch (PageRefused $refused) {
				self::assertSame($plan['refused'], $refused->reason(), $case);
			}
		}

		self::assertSame(PageJobService::MAX_URLS, $this->pageJobs->maxUrls(), 'Limit panelu 5 niezależnie od wyższego limitu CLI.');
		self::assertSame([[], 0], [$this->pageFetcher->requests, self::pageRows('page_jobs')]);
	}

	public function test_19_robots_retry_after_and_host_interval(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$this->pageFetcher->html('https://example.pl/audyt/', AiFakes::pageHtml('Audyt SEO', 'Audyt', 2));

		// Dwa adresy tego samego hosta: drugi czeka na odstęp (termin kolejnej próby), bez czekania w procesie.
		$job = $this->pageJobs->queue($context, PageSelection::urls([self::PAGE, 'https://example.pl/audyt/']))['job'];
		$this->runPageWorker();
		$partial = $this->pageJobs->job($context, $job->publicId);
		self::assertSame([PageJob::STATUS_QUEUED, PageJob::ITEM_DONE, PageJob::ITEM_PENDING, 'domain_cooldown'], [$partial->status, $partial->items[0]['state'], $partial->items[1]['state'], $partial->items[1]['error']]);
		self::assertGreaterThan($this->clock->now()->format('Y-m-d H:i:s'), $partial->runAfter);
		self::assertSame(['recovered' => 0, 'jobs' => 0, 'items' => 0], $this->runPageWorker(), 'Przed terminem zlecenie czeka.');
		$this->afterHostInterval();
		$this->runPageWorker();
		$done = $this->pageJobs->job($context, $job->publicId);
		self::assertSame([PageJob::STATUS_COMPLETED, 2], [$done->status, $done->succeeded()]);

		// robots.txt zabrania — wynik pozycji, bez ponowień.
		$this->pageFetcher->robots('https://konkurent.pl', "User-agent: *\nDisallow: /");
		$robots = $this->pageJobs->queue($context, PageSelection::urls([self::COMPETITOR_PAGE]))['job'];
		$this->runPageWorker();
		$blocked = $this->pageJobs->job($context, $robots->publicId);
		self::assertSame([PageJob::STATUS_COMPLETED, 'robots', 'robots_disallowed'], [$blocked->status, $blocked->items[0]['outcome'], $blocked->items[0]['error']]);
		self::assertSame([], array_filter($this->pageFetcher->pageRequests(), static fn ($request): bool => str_starts_with($request->url, 'https://konkurent.pl/pozycjonowanie')));

		// Retry-After: odpowiedź 503 z prośbą o przerwę → kolejne zlecenie odrzucone bez żądania i bez automatycznego ponowienia.
		$this->afterHostInterval();
		$this->pageFetcher->status('https://example.pl/kontakt/', 503, 3600);
		$first = $this->pageJobs->queue($context, PageSelection::urls(['https://example.pl/kontakt/']))['job'];
		$this->runPageWorker();
		self::assertSame('http_error', $this->pageJobs->job($context, $first->publicId)->items[0]['outcome']);
		$this->afterHostInterval();
		$requests = count($this->pageFetcher->requests);
		$second = $this->pageJobs->queue($context, PageSelection::urls([self::PAGE]), true)['job'];
		$this->runPageWorker();
		$after = $this->pageJobs->job($context, $second->publicId);
		self::assertSame([PageJob::STATUS_COMPLETED, 'refused', 'host_retry_after'], [$after->status, $after->items[0]['outcome'], $after->items[0]['error']]);
		self::assertCount($requests, $this->pageFetcher->requests);
	}

	public function test_20_21_fresh_copy_is_served_from_cache_and_stale_copy_lowers_readiness(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$this->pageJobs->queue($context, PageSelection::topic(self::TOPIC));
		$this->runPageWorker();
		$requests = count($this->pageFetcher->requests);

		// 20: aktualna kopia — zlecenie potwierdza ją z pamięci, bez HTTP.
		$this->afterHostInterval();
		$plan = $this->pageJobs->plan($context, PageSelection::topic(self::TOPIC));
		self::assertSame([0, false, 'fresh'], [$plan['fetches'], $plan['items'][0]['would_fetch'], $plan['items'][0]['cache']]);
		$cached = $this->pageJobs->queue($context, PageSelection::topic(self::TOPIC))['job'];
		$this->runPageWorker();
		self::assertSame('cached', $this->pageJobs->job($context, $cached->publicId)->items[0]['outcome']);
		self::assertCount($requests, $this->pageFetcher->requests);

		// 21: po oknie świeżości — kopia nieaktualna: plan pobiera, gotowość analizy częściowa z jawnym ograniczeniem.
		$this->clock->advance(25 * 3600);
		$plan = $this->pageJobs->plan($context, PageSelection::topic(self::TOPIC));
		self::assertSame([1, 'stale'], [$plan['fetches'], $plan['items'][0]['cache']]);
		$section = $this->workspace->topicSection($context, self::TOPIC);
		self::assertSame('stale', $section['evidence']['project_page']['cache']);
		self::assertContains(\OsfSeo\Ai\Workspace\ReportLabels::readinessCode('page_snapshot_stale'), $section['types'][AnalysisType::PAGE_OPTIMIZATION]['readiness']['limitations']);
		self::assertCount($requests, $this->pageFetcher->requests, 'Sekcja tematu niczego nie pobiera.');
	}

	public function test_double_click_interrupted_run_and_strategy_refresh_never_fetch_on_their_own(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$first = $this->pageJobs->queue($context, PageSelection::topic(self::TOPIC));
		$second = $this->pageJobs->queue($context, PageSelection::topic(self::TOPIC));
		self::assertSame([false, true, $first['job']->publicId], [$first['existing'], $second['existing'], $second['job']->publicId], 'Podwójne kliknięcie → to samo zlecenie.');
		self::assertSame(1, self::pageRows('page_jobs'));

		// Najwyżej 3 aktywne zlecenia projektu.
		$this->pageJobs->queue($context, PageSelection::urls(['https://example.pl/a/']));
		$this->pageJobs->queue($context, PageSelection::urls(['https://example.pl/b/']));

		try {
			$this->pageJobs->queue($context, PageSelection::urls(['https://example.pl/c/']));
			self::fail('Limit aktywnych zleceń.');
		} catch (PageRefused $refused) {
			self::assertSame('too_many_jobs', $refused->reason());
		}

		// Przeliczenie Strategii i otwarcie tematu nie pobierają stron.
		$this->strategy->refresh($context);
		$this->workspace->topicSection($context, self::TOPIC);
		self::assertSame([], $this->pageFetcher->requests);

		// Przerwany proces: zlecenie w toku bez znaku życia wraca do kolejki i kończy się w kolejnym kroku.
		$job = $first['job'];
		$db = self::db();
		$db->execute("UPDATE `{$db->table('page_jobs')}` SET status = 'running', started_at = %s, heartbeat_at = %s WHERE id = %d", [$this->clock->now()->format('Y-m-d H:i:s'), $this->clock->now()->format('Y-m-d H:i:s'), $job->id]);
		$this->clock->advance(PageJobService::STALE_SECONDS + 1);
		self::assertSame(1, $this->runPageWorker()['recovered']);

		// Pozostałe zlecenia tego samego hosta — kolejne kroki po odstępie hosta (bez czekania w procesie).
		for ($step = 0; $step < 5 && $this->pageJobs->job($context, $job->publicId)->isActive(); $step++) {
			$this->afterHostInterval();
			$this->runPageWorker();
		}

		self::assertSame([PageJob::STATUS_COMPLETED, 'created'], [$this->pageJobs->job($context, $job->publicId)->status, $this->pageJobs->job($context, $job->publicId)->items[0]['outcome']]);
		self::assertSame(2, $this->pageJobs->job($context, $job->publicId)->attempts, 'Przerwany przebieg + ponowne przejęcie.');
	}

	public function test_23_client_reads_pages_but_cannot_plan_queue_or_see_fetch_diagnostics(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->failure(self::PAGE, 'timeout');
		$job = $this->pageJobs->queue($context, PageSelection::topic(self::TOPIC))['job'];
		$this->runPageWorker();
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);
		$requests = count($this->pageFetcher->requests);

		foreach ([
			'plan' => fn () => $this->pageJobs->plan($clientContext, PageSelection::topic(self::TOPIC)),
			'queue' => fn () => $this->pageJobs->queue($clientContext, PageSelection::topic(self::TOPIC)),
			'job' => fn () => $this->pageJobs->job($clientContext, $job->publicId),
			'recent' => fn () => $this->pageJobs->recent($clientContext),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Klient nie może: ' . $operation);
			} catch (AccessDenied) {
			}
		}

		$list = $this->pageService->list($clientContext, []);
		self::assertSame([1, null, 'failed'], [$list['total'], $list['rows'][0]['last_error'], $list['rows'][0]['cache']]);
		$view = $this->pageService->pageView($clientContext, $list['rows'][0]['id']);
		self::assertSame([[], false, null], [$view['fetches'], $view['can_fetch'], $view['page']['last_error']]);
		self::assertSame('timeout', $this->pageService->pageView($context, $list['rows'][0]['id'])['page']['last_error'], 'Administrator widzi powód.');
		self::assertNotSame([], $this->pageService->pageView($context, $list['rows'][0]['id'])['fetches']);
		self::assertArrayNotHasKey('diagnostics', $this->pageService->pageView($context, $list['rows'][0]['id'])['fetches'][0], 'Bez diagnostyki sieciowej w panelu.');
		self::assertCount($requests, $this->pageFetcher->requests);
	}

	public function test_28_pages_snapshots_and_jobs_never_cross_projects(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$this->pageFetcher->html('https://example.pl/audyt/', AiFakes::pageHtml('Audyt', 'Audyt', 2));
		$page = $this->fetchOne($context, self::PAGE);
		$this->afterHostInterval();
		$other = $this->fetchOne($context, 'https://example.pl/audyt/');
		$job = $this->pageJobs->queue($context, PageSelection::topic(self::TOPIC), true)['job'];
		$second = $this->gapProject(['inny.pl' => 'Inny'], 'drugi-projekt.pl');

		foreach ([
			'page of another project' => fn () => $this->pageService->pageView($second, $page['page']),
			'snapshot of another page' => fn () => $this->pageService->pageView($context, $page['page'], $other['snapshot']),
			'snapshot of another project' => fn () => $this->pageService->pageView($second, $page['page'], $page['snapshot']),
			'guessed page id' => fn () => $this->pageService->pageView($context, '01ZZZZZZZZZZZZZZZZZZZZZZZZ'),
			'malformed id' => fn () => $this->pageService->pageView($context, "' OR 1=1 --"),
			'job of another project' => fn () => $this->pageJobs->job($second, $job->publicId),
		] as $case => $call) {
			try {
				$call();
				self::fail('IDOR: ' . $case);
			} catch (PageNotFound) {
			}
		}

		self::assertSame($page['snapshot'], $this->pageService->pageView($context, $page['page'], $page['snapshot'])['snapshot']['id']);
		self::assertSame(0, $this->pageService->list($second, [])['total']);
		self::assertSame([], $this->pageJobs->recent($second));
	}

	public function test_page_list_filters_and_paginates_in_sql_with_constant_queries(): void
	{
		global $wpdb;
		$context = $this->aiProject();

		foreach (range(1, 7) as $i) {
			$this->pageFetcher->html('https://example.pl/strona-' . $i . '/', AiFakes::pageHtml('Strona ' . $i, 'Nagłówek ' . $i, 2));
			$this->fetchOne($context, 'https://example.pl/strona-' . $i . '/');
			$this->afterHostInterval();
		}

		$this->pageFetcher->html(self::COMPETITOR_PAGE, AiFakes::pageHtml('Konkurent', 'Oferta', 2));
		$this->fetchOne($context, self::COMPETITOR_PAGE);

		$queries = $wpdb->num_queries;
		$first = $this->pageService->list($context, [], 1, 5);
		$firstQueries = $wpdb->num_queries - $queries;
		$queries = $wpdb->num_queries;
		$second = $this->pageService->list($context, [], 2, 5);

		self::assertSame([8, 2, 5, 3], [$first['total'], $first['pages'], count($first['rows']), count($second['rows'])]);
		self::assertSame($firstQueries, $wpdb->num_queries - $queries, 'Liczba zapytań nie zależy od liczby wierszy.');
		self::assertLessThanOrEqual(2, $firstQueries);
		self::assertSame(['project' => 7, 'competitor' => 1], $first['counts']);
		self::assertSame(1, $this->pageService->list($context, ['kind' => 'competitor'])['total']);
		self::assertSame(1, $this->pageService->list($context, ['q' => 'strona-3'])['total']);
		self::assertSame(8, $this->pageService->list($context, ['cache' => 'fresh'])['total']);
		self::assertSame(0, $this->pageService->list($context, ['cache' => 'missing'])['total']);
		self::assertSame(8, $this->pageService->list($context, ['quality' => $first['rows'][0]['snapshot']['content_quality']])['total']);
		self::assertSame(1, $this->pageService->list($context, ['q' => "%' OR 1=1 -- "])['total'] + 1, 'Wyszukiwanie jako tekst (bez wstrzyknięcia).');
	}

	/**
	 * Krok w tle (jak WP-Cron): zlecenia pobrania stron.
	 *
	 * @return array{recovered: int, jobs: int, items: int}
	 */
	private function runPageWorker(): array
	{
		add_filter('wp_doing_cron', '__return_true');

		try {
			return $this->pageJobs->runBackground(30.0);
		} finally {
			remove_filter('wp_doing_cron', '__return_true');
		}
	}
}
