<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\PageIntelligence;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\PageIntelligence\Fetch\CurlPageFetcher;
use OsfSeo\PageIntelligence\Fetch\UrlSafetyPolicy;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\Tests\Support\AiFakes;
use OsfSeo\Tests\Support\FixtureNetwork;
use OsfSeo\Tests\Support\FixtureServers;
use PHPUnit\Framework\Attributes\Group;

/**
 * Usługa Page Intelligence na PRAWDZIWYM transporcie (ext-curl z przypięciem IP) i lokalnych serwerach testowych — bez internetu:
 * robots.txt serwera, zapis i zmiana treści, przekierowanie do sieci wewnętrznej, DNS rebinding, błąd HTTP po poprawnym snapshocie.
 * 127.0.0.1 gra rolę adresu publicznego, 127.0.0.2 — sieci wewnętrznej (ten sam port; odpowiedź „internal” = wyciek).
 */
#[Group('transport')]
final class PageRealTransportTest extends PageTestCase
{
	private static int $port;

	private static string $content;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		if (! CurlPageFetcher::available() || ! FixtureServers::supported()) {
			self::markTestSkipped('ext-curl z CURLOPT_RESOLVE i proc_open są wymagane do testów transportu.');
		}

		self::$content = sys_get_temp_dir() . '/osf-seo-page-' . bin2hex(random_bytes(4)) . '.html';
		file_put_contents(self::$content, AiFakes::pageHtml());
		$env = ['FIXTURE_CONTENT' => self::$content, 'FIXTURE_ROBOTS' => 'User-agent: whack-a-mole\nDisallow: /private\n\nUser-agent: *\nAllow: /\n'];
		self::$port = FixtureServers::http('127.0.0.1', 'public', $env);
		FixtureServers::http('127.0.0.2', 'internal', $env, self::$port);
	}

	public static function tearDownAfterClass(): void
	{
		FixtureServers::stopAll();

		if (isset(self::$content) && is_file(self::$content)) {
			unlink(self::$content);
		}

		parent::tearDownAfterClass();
	}

	protected function setUp(): void
	{
		parent::setUp();
		file_put_contents(self::$content, AiFakes::pageHtml());
		$network = new FixtureNetwork([
			'www.fixture.example' => ['127.0.0.1'],
			'internal.fixture.example' => ['127.0.0.2'],
			'rebind.fixture.example' => static fn (int $lookup): array => $lookup === 1 ? ['127.0.0.1'] : ['127.0.0.2'],
		], [self::$port]);
		$policy = new UrlSafetyPolicy($network, $network);
		$this->buildPages(new CurlPageFetcher($policy), $policy);
	}

	public function test_real_fetch_stores_snapshot_follows_robots_and_detects_content_change(): void
	{
		$context = $this->fixtureProject();
		$url = $this->url('www', '/content');

		$created = $this->fetchOne($context, $url);
		$page = $this->pageService->page($context, $created['page']);
		self::assertSame('created', $created['outcome'], (string) $created['error']);
		self::assertSame(200, $page['snapshot']['http_status']);
		self::assertSame('Pozycjonowanie stron internetowych', $page['snapshot']['data']['headings']['list'][0]['text']);
		self::assertSame('allowed', $page['fetches'][0]['diagnostics']['robots']);

		// Ta sama treść — bez nowego snapshotu; zmieniony HTML — nowy snapshot i inny odcisk treści.
		$this->afterHostInterval();
		self::assertSame('unchanged', $this->fetchOne($context, $url, true)['outcome']);
		file_put_contents(self::$content, AiFakes::pageHtml(h1: 'Nowy nagłówek strony'));
		$this->afterHostInterval();
		$changed = $this->fetchOne($context, $url, true);
		$snapshots = $this->pageService->page($context, $created['page'])['snapshots'];
		self::assertSame('changed', $changed['outcome']);
		self::assertCount(2, $snapshots);
		self::assertNotSame($snapshots[0]['content_hash'], $snapshots[1]['content_hash']);

		// robots.txt serwera: ścieżka zablokowana dla tokenu whack-a-mole.
		$this->afterHostInterval();
		self::assertSame('robots_disallowed', $this->fetchOne($context, $this->url('www', '/private/strona'))['error']);

		// Błąd HTTP po poprawnym snapshocie — snapshot zostaje.
		$this->afterHostInterval();
		$error = $this->fetchOne($context, $this->url('www', '/status/500'));
		self::assertSame(['http_error', 500], [$error['outcome'], $error['http_status']]);
		self::assertSame($changed['snapshot'], $this->pageService->page($context, $created['page'])['snapshot']['id']);
	}

	public function test_real_redirect_to_internal_network_and_dns_rebinding_never_reach_the_internal_server(): void
	{
		$context = $this->fixtureProject();
		$redirect = $this->url('www', '/redirect?to=' . rawurlencode($this->url('internal', '/identity')));

		$result = $this->fetchOne($context, $redirect);
		self::assertSame('refused', $result['outcome']);
		self::assertSame('redirect_ip_loopback', $result['error'], '127.0.0.2 — adres pętli zwrotnej, zawsze blokowany.');
		self::assertNull($result['snapshot']);

		$this->afterHostInterval();
		$rebind = $this->fetchOne($context, $this->url('rebind', '/identity'));
		// robots.txt pobrany przy pierwszym rozwiązaniu (publiczny adres), strona — przy drugim (adres wewnętrzny): odmowa przed połączeniem.
		self::assertSame(['refused', 'ip_loopback'], [$rebind['outcome'], $rebind['error']]);
		self::assertSame(0, self::pageRows('page_snapshots'), 'Żadna treść z serwera wewnętrznego.');
		self::assertSame([['blocked', null, null]], array_values(array_unique(array_map(static fn (array $page): array => [$page['status'], $page['snapshot'], $page['final_url']], $this->pageService->pages($context)), SORT_REGULAR)));

		$this->afterHostInterval();
		self::assertSame('ip_loopback', $this->fetchOne($context, $this->url('internal', '/identity'))['error'] ?? null);
	}

	private function fixtureProject(): ProjectContext
	{
		return $this->gapProject([], 'fixture.example');
	}

	private function url(string $host, string $path): string
	{
		return 'http://' . $host . '.fixture.example:' . self::$port . $path;
	}
}
