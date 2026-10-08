<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\PageIntelligence;

use OsfSeo\PageIntelligence\Fetch\FetchRequest;
use OsfSeo\PageIntelligence\Fetch\FetchResult;
use OsfSeo\PageIntelligence\PageIntelligenceConfig;
use OsfSeo\PageIntelligence\PageRefused;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\PageIntelligence\PageTarget;
use OsfSeo\Tests\Support\AiFakes;
use OsfSeo\Tests\Support\FakePageFetcher;

/**
 * Pobieranie i snapshoty: cache i TTL (29), idempotencja (30), nieudane pobranie zachowuje ostatni poprawny snapshot (31), zmiana treści
 * zmienia odcisk (32), sama zmiana daty / znaczników bez treści nie tworzy zmiany (33), kody HTTP i robots.txt (14), obejście polityki
 * przez ręczny adres (15), równoległe zlecenia tego samego adresu (45), limity zlecenia.
 */
final class PageFetchTest extends PageTestCase
{
	public function test_29_cache_ttl_force_and_host_interval(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());

		$first = $this->fetchOne($context, self::PAGE);
		self::assertSame('created', $first['outcome']);
		self::assertCount(1, $this->pageFetcher->pageRequests());
		self::assertCount(2, $this->pageFetcher->requests, 'Strona i robots.txt.');

		// Świeży snapshot: bez HTTP (plan i pobranie).
		$plan = $this->pageService->plan($context, PageSelection::urls([self::PAGE]));
		self::assertSame(['fresh', false, 0], [$plan['items'][0]['cache'], $plan['items'][0]['would_fetch'], $plan['fetches']]);
		self::assertSame('cached', $this->fetchOne($context, self::PAGE)['outcome']);
		self::assertCount(1, $this->pageFetcher->pageRequests());

		// Wymuszenie przed upływem odstępu hosta — odmowa bez HTTP; po odstępie — pobranie.
		$cooldown = $this->fetchOne($context, self::PAGE, true);
		self::assertSame(['refused', 'domain_cooldown'], [$cooldown['outcome'], $cooldown['error']]);
		self::assertGreaterThan(0, $cooldown['retry_in']);
		$this->afterHostInterval();
		self::assertSame('unchanged', $this->fetchOne($context, self::PAGE, true)['outcome']);
		self::assertCount(2, $this->pageFetcher->pageRequests());

		// Po TTL — nieaktualny, pobranie bez wymuszania.
		$this->clock->advance(24 * 3600 + 1);
		self::assertSame('stale', $this->pageService->plan($context, PageSelection::urls([self::PAGE]))['items'][0]['cache']);
		self::assertSame('unchanged', $this->fetchOne($context, self::PAGE)['outcome']);
		self::assertCount(3, $this->pageFetcher->pageRequests());
		self::assertSame(1, self::pageRows('page_snapshots'));
		self::assertSame(1, self::pageRows('page_targets'));
	}

	public function test_30_33_identical_content_is_idempotent_and_date_only_changes_create_no_snapshot(): void
	{
		$context = $this->aiProject();
		$html = AiFakes::pageHtml();
		$this->pageFetcher->html(self::PAGE, $html);
		$created = $this->fetchOne($context, self::PAGE);
		$before = $this->pageService->page($context, $created['page']);

		// Ten sam HTML dzień później, potem HTML różniący się wyłącznie skryptem z datą, komentarzem i znacznikiem czasu w nagłówkach.
		$this->clock->advance(86400 + 1);
		self::assertSame('unchanged', $this->fetchOne($context, self::PAGE)['outcome']);
		$this->clock->advance(86400 + 1);
		$dated = str_replace('</head>', '<script>window.generated="2026-01-17T08:00:00Z";</script><!-- cache 2026-01-17 08:00 --></head>', $html);
		$this->pageFetcher->html(self::PAGE, $dated, ['last-modified' => 'Sat, 17 Jan 2026 08:00:00 GMT', 'etag' => '"2026-01-17"']);
		$result = $this->fetchOne($context, self::PAGE);
		$after = $this->pageService->page($context, $created['page']);

		self::assertSame('unchanged', $result['outcome']);
		self::assertSame($created['snapshot'], $result['snapshot']);
		self::assertSame(1, self::pageRows('page_snapshots'));
		self::assertSame($before['snapshot']['fetched_at'], $after['snapshot']['fetched_at'], 'Data pierwszego pobrania treści bez zmian.');
		self::assertSame($before['snapshot']['content_hash'], $after['snapshot']['content_hash']);
		self::assertNotSame($before['snapshot']['last_seen_at'], $after['snapshot']['last_seen_at'], 'Ponowne potwierdzenie treści.');
		self::assertSame(['unchanged', 'unchanged', 'created'], array_column(array_slice($after['fetches'], 0, 3), 'outcome'));
		self::assertCount(1, $after['snapshots']);
	}

	public function test_32_content_change_creates_snapshot_and_changes_fingerprints(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$this->pageService->fetch($context, PageSelection::topic('pozycjonowanie stron'));
		$aiBefore = $this->ai->context($context, 'pozycjonowanie stron');
		$first = $this->target($context, self::PAGE);

		$this->clock->advance(86400 + 1);
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml(h1: 'Pozycjonowanie stron dla e-commerce'));
		$changed = $this->fetchOne($context, self::PAGE);
		$page = $this->pageService->page($context, $first->publicId);
		$aiAfter = $this->ai->context($context, 'pozycjonowanie stron');

		self::assertSame('changed', $changed['outcome']);
		self::assertTrue($changed['changed']);
		self::assertCount(2, $page['snapshots']);
		self::assertNotSame($page['snapshots'][0]['content_hash'], $page['snapshots'][1]['content_hash']);
		self::assertSame($changed['snapshot'], $page['snapshot']['id']);
		self::assertSame('Pozycjonowanie stron dla e-commerce', $page['snapshot']['data']['headings']['list'][0]['text']);
		self::assertNotSame($aiBefore->fingerprint(), $aiAfter->fingerprint());
		self::assertNotSame($aiBefore->evidenceFingerprint(), $aiAfter->evidenceFingerprint());
		self::assertContains('page:' . $changed['snapshot'], $aiAfter->refs());

		// Limit snapshotów strony — najnowsze zostają, ostatni poprawny nigdy nie jest usuwany.
		putenv(PageIntelligenceConfig::MAX_SNAPSHOTS . '=2');
		$this->buildAi();

		for ($version = 3; $version <= 4; $version++) {
			$this->clock->advance(86400 + 1);
			$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml(h1: 'Wersja ' . $version));
			$last = $this->fetchOne($context, self::PAGE);
		}

		self::assertSame(2, self::pageRows('page_snapshots'));
		self::assertSame($last['snapshot'], $this->pageService->page($context, $first->publicId)['snapshot']['id']);
	}

	public function test_31_14_failed_fetch_keeps_last_good_snapshot_and_records_http_errors(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$good = $this->fetchOne($context, self::PAGE);

		foreach ([[500, 'http_500'], [403, 'http_403'], [404, 'http_404']] as [$status, $code]) {
			$this->clock->advance(86400 + 1);
			$this->pageFetcher->status(self::PAGE, $status);
			$result = $this->fetchOne($context, self::PAGE);
			$page = $this->pageService->page($context, $good['page']);

			self::assertSame(['http_error', $code, $status], [$result['outcome'], $result['error'], $result['http_status']]);
			self::assertSame($good['snapshot'], $result['snapshot'], 'Ostatni poprawny snapshot bez zmian.');
			self::assertSame($good['snapshot'], $page['snapshot']['id']);
			self::assertSame('Pozycjonowanie stron internetowych', $page['snapshot']['data']['headings']['list'][0]['text']);
			self::assertSame([PageTarget::STATUS_FAILED, $code, $status], [$page['page']['status'], $page['page']['last_error'], $page['page']['last_http_status']]);
			self::assertSame('stale', $page['cache'], 'Snapshot nadal jest — nieaktualny, nie „brak”.');
		}

		// Timeout: bez treści, bez nowego snapshotu; brak automatycznego ponowienia.
		$this->clock->advance(3600);
		$this->pageFetcher->failure(self::PAGE, 'timeout');
		$requests = count($this->pageFetcher->pageRequests());
		self::assertSame(['failed', 'timeout'], array_values(array_intersect_key($this->fetchOne($context, self::PAGE), array_flip(['outcome', 'error']))));
		self::assertCount($requests + 1, $this->pageFetcher->pageRequests(), 'Jedno żądanie, bez ponowień.');
		self::assertSame(1, self::pageRows('page_snapshots'));

		// 429 z Retry-After: kolejne pobranie hosta wstrzymane (wszystkie projekty) do upływu czasu.
		$this->clock->advance(3600);
		$this->pageFetcher->status(self::PAGE, 429, 120);
		self::assertSame('http_429', $this->fetchOne($context, self::PAGE)['error']);
		$this->afterHostInterval();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$blocked = $this->fetchOne($context, self::PAGE, true);
		self::assertSame(['refused', 'host_retry_after'], [$blocked['outcome'], $blocked['error']]);
		$this->clock->advance(120);
		self::assertSame('unchanged', $this->fetchOne($context, self::PAGE, true)['outcome']);

		// Nowa strona z błędem: brak snapshotu → stan „failed”, nie „brak strony”.
		$missing = 'https://example.pl/nie-ma/';
		$this->afterHostInterval();
		$this->pageFetcher->status($missing, 404);
		$failed = $this->fetchOne($context, $missing);
		$listed = array_values(array_filter($this->pageService->pages($context), static fn (array $page): bool => $page['url'] === $missing))[0];
		self::assertNull($failed['snapshot']);
		self::assertSame(['failed', null, 404], [$listed['cache'], $listed['snapshot'], $listed['last_http_status']]);
	}

	public function test_14_robots_txt_is_respected_and_unreachable_robots_blocks(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->robots('https://example.pl', "User-agent: *\nAllow: /\n\nUser-agent: whack-a-mole\nDisallow: /pozycjonowanie/\n");
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$this->pageFetcher->html('https://example.pl/audyt/', AiFakes::pageHtml());

		$blocked = $this->fetchOne($context, self::PAGE);
		self::assertSame(['robots', 'robots_disallowed'], [$blocked['outcome'], $blocked['error']]);
		self::assertSame([], $this->pageFetcher->pageRequests(), 'Zablokowana strona nie jest pobierana.');
		self::assertSame(PageTarget::STATUS_BLOCKED, $this->target($context, self::PAGE)->status);

		$this->afterHostInterval();
		self::assertSame('created', $this->fetchOne($context, 'https://example.pl/audyt/')['outcome']);

		// robots.txt niedostępny (5xx) — ostrożnie: brak pobrania.
		$this->pageFetcher->status('https://www.example.pl/robots.txt', 503);
		$this->pageFetcher->html('https://www.example.pl/oferta/', AiFakes::pageHtml());
		$unreachable = $this->fetchOne($context, 'https://www.example.pl/oferta/');
		self::assertSame('robots_unreachable', $unreachable['error']);
		self::assertCount(1, $this->pageFetcher->pageRequests());
	}

	public function test_conditional_request_uses_etag_and_304_keeps_snapshot(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->respond(self::PAGE, static fn (FetchRequest $request): FetchResult => $request->etag === '"v1"'
			? FakePageFetcher::result(self::PAGE, FetchResult::NOT_MODIFIED, null, 304, headers: ['etag' => '"v1"'])
			: FakePageFetcher::result(self::PAGE, FetchResult::OK, null, 200, AiFakes::pageHtml(), ['content-type' => 'text/html', 'etag' => '"v1"']));

		$created = $this->fetchOne($context, self::PAGE);
		$this->clock->advance(86400 + 1);
		$unchanged = $this->fetchOne($context, self::PAGE);
		$requests = $this->pageFetcher->pageRequests();

		self::assertSame(['created', 'unchanged'], [$created['outcome'], $unchanged['outcome']]);
		self::assertSame(304, $unchanged['http_status']);
		self::assertNull($requests[0]->etag);
		self::assertSame('"v1"', $requests[1]->etag);
		self::assertSame('fresh', $this->pageService->page($context, $created['page'])['cache']);

		$this->afterHostInterval();
		$this->fetchOne($context, self::PAGE, true);
		self::assertNull($this->pageFetcher->pageRequests()[2]->etag, 'Wymuszenie pobiera pełną treść.');
	}

	public function test_15_manual_url_cannot_bypass_scope_or_policy(): void
	{
		$context = $this->aiProject();
		$urls = [
			'https://evil.example/' => 'url_not_allowed',
			'https://example.pl.evil.example/' => 'url_not_allowed',
			'http://127.0.0.1/' => 'ip_literal_not_allowed',
			'http://[::1]/' => 'ip_literal_not_allowed',
			'http://2130706433/' => 'ip_literal_not_allowed',
			'http://169.254.169.254/latest/meta-data/' => 'ip_literal_not_allowed',
			'file:///etc/passwd' => 'scheme_not_allowed',
			'gopher://example.pl/' => 'scheme_not_allowed',
			'https://user:' . 'x@example.pl/' => 'credentials_not_allowed',
			'https://example.pl:8443/' => 'port_not_allowed',
			'http://localhost/' => 'host_not_allowed',
		];

		foreach (array_chunk(array_keys($urls), 5) as $chunk) {
			foreach ($this->fetchUrls($context, $chunk) as $result) {
				self::assertSame('refused', $result['outcome'], (string) $result['input']);
				self::assertSame($urls[$result['input']], $result['error'], (string) $result['input']);
			}
		}

		self::assertSame([], $this->pageFetcher->requests, 'Żadnego żądania (także robots.txt).');
		self::assertSame(0, self::pageRows('page_targets'));
		self::assertArrayNotHasKey('families', $this->fetchUrls($context, ['https://evil.example/'])[0], 'Wynik bez wewnętrznego zakresu.');

		// Host w rodzinie domeny projektu, ale rozwiązywany do sieci wewnętrznej — diagnostyka DNS bez HTTP.
		$check = $this->pageService->checkUrl($context, 'https://intranet.example.pl/');
		self::assertFalse($check['allowed']);
		self::assertSame('ip_private', $check['reason']);
		self::assertTrue($this->pageService->checkUrl($context, self::PAGE)['allowed']);
		self::assertSame([], $this->pageFetcher->requests);

		// Konkurent z listy projektu — dozwolony jawnie; wyłącznik pobierania konkurencji.
		$this->pageFetcher->html(self::COMPETITOR_PAGE, AiFakes::pageHtml('Konkurent'));
		self::assertSame(['created', 'competitor'], array_values(array_intersect_key($this->fetchOne($context, self::COMPETITOR_PAGE), array_flip(['outcome', 'kind']))));
		putenv(PageIntelligenceConfig::COMPETITORS_ENABLED . '=0');
		$this->buildAi();
		self::assertSame('kind_disabled', $this->fetchOne($context, 'https://konkurent.pl/inna/')['error']);
	}

	public function test_request_limits_and_unavailable_transport_refuse_before_any_request(): void
	{
		$context = $this->aiProject();
		$urls = array_map(static fn (int $i): string => 'https://example.pl/strona-' . $i . '/', range(1, 6));

		foreach ([
			'too_many_urls' => fn () => $this->fetchUrls($context, $urls),
			'nothing_selected' => fn () => $this->fetchUrls($context, ['  ']),
		] as $reason => $call) {
			try {
				$call();
				self::fail($reason);
			} catch (PageRefused $refused) {
				self::assertSame($reason, $refused->reason());
			}
		}

		self::assertSame('too_many_urls', $this->pageService->plan($context, PageSelection::urls($urls))['refused']);
		self::assertCount(1, $this->fetchUrls($context, [self::PAGE, self::PAGE, ' ' . self::PAGE]), 'Powtórzony adres w zleceniu — jeden element.');

		$this->buildPages(transport: false);

		try {
			$this->fetchUrls($context, ['https://example.pl/audyt/']);
			self::fail('Brak bezpiecznego transportu.');
		} catch (PageRefused $refused) {
			self::assertSame('transport_unavailable', $refused->reason());
		}

		self::assertSame('unavailable', $this->pageService->status($context)['transport']);
	}

	public function test_pages_of_one_host_are_fetched_one_after_another_with_bounded_waiting(): void
	{
		$context = $this->aiProject();
		$urls = array_map(static fn (int $i): string => 'https://example.pl/strona-' . $i . '/', range(1, 4));

		foreach ($urls as $url) {
			$this->pageFetcher->html($url, AiFakes::pageHtml(h1: $url));
		}

		// Bez czekania (np. panel): po pierwszym adresie odmowa z czasem do ponowienia.
		$noWait = $this->fetchUrls($context, $urls);
		self::assertSame(['created', 'refused', 'refused', 'refused'], array_column($noWait, 'outcome'));
		self::assertSame('domain_cooldown', $noWait[1]['error']);

		// CLI: po kolei, z odstępem hosta (zegar przesuwa się o czas czekania), bez równoległości.
		$started = $this->clock->now()->getTimestamp();
		$waited = $this->pageService->fetch($context, PageSelection::urls($urls), false, 'cli', true);
		self::assertSame(['cached', 'created', 'created', 'created'], array_column($waited, 'outcome'));
		self::assertGreaterThanOrEqual(30, $this->clock->now()->getTimestamp() - $started);
		$starts = array_map('strtotime', array_column(self::db()->fetchAll('SELECT started_at FROM `' . self::db()->table('page_fetches') . '` WHERE network = 1 ORDER BY id'), 'started_at'));
		$gaps = array_map(static fn (int $a, int $b): int => $b - $a, array_slice($starts, 0, -1), array_slice($starts, 1));
		self::assertGreaterThanOrEqual(10, min($gaps), 'Odstęp hosta między każdym pobraniem.');

		// Łączny limit czekania w zleceniu — dalej odmowa zamiast długiego blokowania.
		putenv(PageIntelligenceConfig::DOMAIN_INTERVAL . '=60');
		$this->buildAi();
		$this->clock->advance(86400 + 1);
		$capped = $this->pageService->fetch($context, PageSelection::urls($urls), false, 'cli', true);
		self::assertSame(['unchanged', 'unchanged', 'unchanged', 'refused'], array_column($capped, 'outcome'));
		self::assertSame('domain_cooldown', $capped[3]['error']);
	}

	public function test_45_parallel_fetch_of_the_same_url_or_host_is_refused_without_request(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$this->fetchOne($context, self::PAGE);
		$target = $this->target($context, self::PAGE);
		$this->clock->advance(86400 + 1);

		$held = $this->holdLock('page_fetch_' . $target->id);

		try {
			$result = $this->fetchOne($context, self::PAGE);
		} finally {
			$this->releaseHeldLock($held, 'page_fetch_' . $target->id);
		}

		self::assertSame(['refused', 'fetch_in_progress'], [$result['outcome'], $result['error']]);
		self::assertCount(1, $this->pageFetcher->pageRequests());

		$hostLock = 'page_host_' . substr(md5('example.pl'), 0, 16);
		$held = $this->holdLock($hostLock);

		try {
			$busy = $this->fetchOne($context, self::PAGE);
		} finally {
			$this->releaseHeldLock($held, $hostLock);
		}

		self::assertSame('host_busy', $busy['error']);
		self::assertCount(1, $this->pageFetcher->pageRequests());
		self::assertSame('unchanged', $this->fetchOne($context, self::PAGE)['outcome']);
		self::assertSame(1, self::pageRows('page_snapshots'));
	}
}
