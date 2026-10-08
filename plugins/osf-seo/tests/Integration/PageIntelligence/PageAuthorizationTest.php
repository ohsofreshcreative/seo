<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\PageIntelligence;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\Roles;
use OsfSeo\PageIntelligence\PageNotFound;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Tests\Support\AiFakes;

/**
 * Izolacja i uprawnienia: brak IDOR przez identyfikator strony i snapshotu, osobne treści projektów dla tego samego adresu (42), klient
 * tylko odczytuje i nie może uruchomić żadnego żądania (43), retencja i usuwanie danych (44).
 */
final class PageAuthorizationTest extends PageTestCase
{
	public function test_42_pages_and_snapshots_never_cross_projects(): void
	{
		$first = $this->aiProject();
		$second = $this->gapProject(['example.pl' => 'Example'], 'drugi-projekt.pl');
		$this->gscKeyword($second, 'kurs fotografii', 400, 9.0, 'https://drugi-projekt.pl/kurs/', 5);
		$this->strategy->refresh($second);
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$own = $this->fetchOne($first, self::PAGE);
		$before = $this->pageService->page($first, $own['page']);

		foreach ([
			'page' => fn () => $this->pageService->page($second, $own['page']),
			'snapshot' => fn () => $this->pageService->snapshot($second, $own['snapshot']),
			'delete' => fn () => $this->pageService->delete($second, $own['page']),
			'garbage page' => fn () => $this->pageService->page($second, "' OR 1=1 --"),
			'garbage snapshot' => fn () => $this->pageService->snapshot($second, '01J000000000000000000000ZZ'),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Dane innego projektu: ' . $operation);
			} catch (PageNotFound) {
			}
		}

		self::assertSame([], $this->pageService->pages($second));

		// Ten sam adres w drugim projekcie (konkurent): własne pobranie i własny snapshot — bez współdzielenia treści.
		// Uprzejmość wobec hosta jest wspólna dla wszystkich projektów (odstęp), treści — nie.
		self::assertSame('domain_cooldown', $this->fetchOne($second, self::PAGE)['error']);
		$this->afterHostInterval();
		$foreign = $this->fetchOne($second, self::PAGE);

		self::assertSame(['created', 'competitor'], [$foreign['outcome'], $foreign['kind']]);
		self::assertNotSame($own['page'], $foreign['page']);
		self::assertNotSame($own['snapshot'], $foreign['snapshot']);
		self::assertCount(2, $this->pageFetcher->pageRequests(), 'Drugi projekt pobrał stronę sam (bez kopii z pierwszego).');
		self::assertSame(1, self::pageRows('page_snapshots', $first->projectId()));
		self::assertSame(1, self::pageRows('page_snapshots', $second->projectId()));

		try {
			$this->pageService->snapshot($first, $foreign['snapshot']);
			self::fail('Snapshot drugiego projektu.');
		} catch (PageNotFound) {
		}

		// Kontekst AI drugiego projektu bez treści pierwszego; dane pierwszego bez zmian.
		self::assertStringNotContainsString($own['snapshot'], $this->ai->context($second, 'kurs fotografii')->json());
		self::assertSame($before['snapshot'], $this->pageService->page($first, $own['page'])['snapshot']);

		// Strona projektu A nie jest stroną konkurenta B z SERP-u A (brak pomiaru w B) — odmowa.
		self::assertSame('url_not_allowed', $this->fetchOne($second, 'https://wynik-1.example/seo/')['error']);
	}

	public function test_43_client_reads_only_and_cannot_trigger_any_request(): void
	{
		$context = $this->aiProject();
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$fetched = $this->fetchOne($context, self::PAGE);
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);
		$requests = count($this->pageFetcher->requests);

		self::assertTrue(get_role(Roles::ADMIN)?->has_cap(Capabilities::MANAGE_PAGE_INTELLIGENCE));
		self::assertTrue(get_role(Roles::WP_ADMINISTRATOR)?->has_cap(Capabilities::MANAGE_PAGE_INTELLIGENCE));
		self::assertFalse(get_role(Roles::CLIENT)?->has_cap(Capabilities::MANAGE_PAGE_INTELLIGENCE));

		foreach ([
			'plan' => fn () => $this->pageService->plan($clientContext, PageSelection::urls([self::PAGE])),
			'fetch' => fn () => $this->pageService->fetch($clientContext, PageSelection::urls([self::PAGE]), true),
			'fetch topic' => fn () => $this->pageService->fetch($clientContext, PageSelection::topic('pozycjonowanie stron')),
			'check-url' => fn () => $this->pageService->checkUrl($clientContext, 'https://intranet.example.pl/'),
			'delete' => fn () => $this->pageService->delete($clientContext, $fetched['page']),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Klient nie może: ' . $operation);
			} catch (AccessDenied) {
			}
		}

		try {
			$this->guard->authorize($context->publicId(), $client, Capabilities::MANAGE_PAGE_INTELLIGENCE);
			self::fail('Kontekst z uprawnieniem pobierania nie powstaje dla klienta.');
		} catch (AccessDenied) {
		}

		// Odczyt zapisanych danych (bez HTTP, bez identyfikatorów użytkowników).
		self::assertFalse($this->pageService->status($clientContext)['can_fetch']);
		self::assertCount(1, $this->pageService->pages($clientContext));
		$page = $this->pageService->page($clientContext, $fetched['page']);
		self::assertSame($fetched['snapshot'], $page['snapshot']['id']);
		self::assertStringNotContainsString('requested_by', (string) json_encode($page));
		self::assertStringNotContainsString('created_by', (string) json_encode($page));
		self::assertCount($requests, $this->pageFetcher->requests);
		self::assertTrue($this->pageService->status($context)['can_fetch']);
	}

	public function test_44_retention_and_explicit_delete_remove_only_their_data(): void
	{
		$context = $this->aiProject();
		$other = $this->gapProject(['konkurent.pl' => 'Konkurent'], 'drugi-projekt.pl');
		$this->pageFetcher->html(self::PAGE, AiFakes::pageHtml());
		$this->pageFetcher->html('https://example.pl/audyt/', AiFakes::pageHtml());
		$old = $this->fetchOne($context, self::PAGE);
		$this->afterHostInterval();
		$kept = $this->fetchOne($context, 'https://example.pl/audyt/');

		// Retencja: snapshoty niepotwierdzone i próby starsze niż N dni (domyślnie 90) — dla wszystkich projektów, bez HTTP.
		$this->clock->advance(60 * 86400);
		$this->pageFetcher->html('https://konkurent.pl/', AiFakes::pageHtml());
		$recent = $this->fetchOne($other, 'https://konkurent.pl/');
		$this->clock->advance(31 * 86400);
		self::assertSame('unchanged', $this->fetchOne($context, 'https://example.pl/audyt/')['outcome'], 'Potwierdzona treść zostaje.');
		$requests = count($this->pageFetcher->requests);
		$purged = $this->pageService->maintenance(true);

		self::assertSame(1, $purged['snapshots']);
		self::assertGreaterThanOrEqual(2, $purged['fetches']);
		self::assertCount($requests, $this->pageFetcher->requests);
		$expired = $this->pageService->page($context, $old['page']);
		self::assertNull($expired['snapshot']);
		self::assertSame('missing', $expired['cache']);
		self::assertSame($kept['snapshot'], $this->pageService->page($context, $kept['page'])['snapshot']['id']);
		self::assertSame($recent['snapshot'], $this->pageService->page($other, $recent['page'])['snapshot']['id']);
		self::assertContains('page_content_not_fetched', $this->ai->context($context, 'pozycjonowanie stron')->dataGaps());
		self::assertNull($this->pageService->maintenance(), 'Poza krokiem w tle — bez porządków (tylko jawnie).');

		// Jawne usunięcie strony: treści, próby i powiązania tylko tej strony.
		$this->pageService->delete($context, $kept['page']);

		foreach ([fn () => $this->pageService->page($context, $kept['page']), fn () => $this->pageService->snapshot($context, $kept['snapshot'])] as $call) {
			try {
				$call();
				self::fail('Usunięta strona.');
			} catch (PageNotFound) {
			}
		}

		self::assertSame(0, self::pageRows('page_snapshots', $context->projectId()));
		self::assertSame(1, self::pageRows('page_targets', $context->projectId()), 'Druga strona projektu zostaje.');
		self::assertSame(0, (int) self::db()->fetchValue('SELECT COUNT(*) FROM `' . self::db()->table('page_fetches') . '` WHERE project_id = %d AND target_id NOT IN (SELECT id FROM `' . self::db()->table('page_targets') . '`)', [$context->projectId()]));
		self::assertSame(1, self::pageRows('page_snapshots', $other->projectId()));
	}
}
