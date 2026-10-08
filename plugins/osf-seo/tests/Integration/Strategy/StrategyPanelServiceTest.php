<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Auth\Roles;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyRefresher;
use OsfSeo\Strategy\Topics\TopicFilters;
use OsfSeo\Strategy\Topics\TopicRow;
use OsfSeo\Strategy\Topics\TopicStatus;
use OsfSeo\Support\Ulid;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * API panelu Strategii (STEP 16, faza D) na prawdziwej bazie: przegląd (liczniki, wysoki priorytet, zmiany po decyzji, zdarzenia,
 * aktualność źródeł), filtry backlogu, zlecenie przeliczenia (bez przeliczania w żądaniu), notatka, widok klienta (bez odrzuconych
 * tematów, notatek i identyfikatorów użytkowników), odnośniki z innych modułów i SERP Intelligence — bez żadnych żądań do API.
 */
final class StrategyPanelServiceTest extends StrategyTestCase
{
	private const PAGE = 'https://example.pl/pozycjonowanie/';

	public function test_overview_and_backlog_filters_read_stored_state_only(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$requests = count($this->dataForSeoRequests());

		$overview = $this->strategy->overview($context);
		self::assertSame(3, $overview['counts']['open']);
		self::assertSame(['investigate' => 1, 'optimize' => 2], $overview['counts']['actions']);
		self::assertSame(0, $overview['counts']['dismissed']);
		self::assertSame(3, $overview['counts']['no_fresh_serp'], 'Bez pomiaru SERP.');
		self::assertTrue($overview['state']['supported']);
		self::assertTrue($overview['state']['up_to_date']);
		self::assertFalse($overview['state']['running']);
		self::assertNull($overview['state']['requested_at']);
		self::assertSame(4, $overview['state']['candidates']['active']);
		self::assertSame([], $overview['attention'], 'Bez zmian po decyzji.');
		self::assertCount(3, $overview['events']);
		self::assertSame(['gsc', 'serp', 'labs'], array_keys($overview['freshness']));
		self::assertNull($overview['freshness']['serp']['last_checked_at']);

		// Filtry backlogu (kolumny tematu, bez dekodowania analizy).
		$labels = fn (array $input): array => array_map(static fn (TopicRow $row): string => (string) $row->label, $this->strategy->topics($context, TopicFilters::fromInput($input))['rows']);
		self::assertSame(['agencja seo łódź'], $labels(['source' => 'manual']));
		self::assertSame(['pozycjonowanie stron', 'audyt seo'], $labels(['source' => 'gsc', 'sort' => 'demand']));
		self::assertSame(['pozycjonowanie stron'], $labels(['q' => 'stron www']), 'Wyszukiwanie obejmuje frazy tematu.');
		self::assertSame(['agencja seo łódź'], $labels(['target' => 'unknown']));
		self::assertSame(['investigate'], array_values(array_unique(array_map(fn (string $label): string => (string) $this->strategy->topic($context, $label)['topic']->action, $labels(['action' => 'investigate'])))));
		self::assertCount(3, $labels(['serp' => 'none']));
		self::assertSame([], $labels(['serp' => 'fresh']));
		self::assertSame([], $labels(['max_kd' => 100]), 'Brak trudności SEO nigdy nie jest jak KD 0.');
		self::assertSame(['pozycjonowanie stron'], $labels(['sort' => 'gsc_position', 'q' => 'pozycjonowanie']));
		self::assertSame(['audyt seo', 'pozycjonowanie stron'], $labels(['sort' => 'gsc_position', 'source' => 'gsc']), 'Średnia pozycja (GSC) rosnąco.');
		self::assertSame(count($this->dataForSeoRequests()), $requests, 'Odczyty panelu nie wysyłają żądań.');

		// Odrzucenie i zmiana po decyzji: flaga uwagi, filtr „changed”, liczniki.
		$this->strategy->setStatus($context, 'audyt seo', TopicStatus::Dismissed, 'Nie robimy audytów.');
		$this->gscKeyword($context, 'audyt seo', 200, 8.0, 'https://example.pl/blog/audyt/', 0, '2026-01-13');
		$this->strategy->refresh($context, true);
		$overview = $this->strategy->overview($context);
		self::assertSame([2, 1, 1], [$overview['counts']['open'], $overview['counts']['dismissed'], $overview['counts']['changed']]);
		self::assertSame(['audyt seo'], array_map(static fn (TopicRow $row): string => (string) $row->label, $overview['attention']));
		self::assertTrue($overview['attention'][0]->needsAttention());
		self::assertSame(['audyt seo'], $labels(['changed' => '1', 'status' => 'all']));
		self::assertSame(TopicStatus::Dismissed->value, $this->strategy->topic($context, 'audyt seo')['topic']->status, 'Przeliczenie nie zmienia statusu pracy.');
	}

	public function test_refresh_request_note_and_client_view(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$main = $this->strategy->setStatus($context, 'pozycjonowanie stron', TopicStatus::Planned, 'Pierwsza notatka');
		$this->strategy->setStatus($context, 'audyt seo', TopicStatus::Dismissed, 'Klient nie chce');

		// Notatka bez zmiany statusu.
		$noted = $this->strategy->setNote($context, $main->publicId, '  Druga notatka  ');
		self::assertSame(['planned', 'Druga notatka'], [$noted->status, $noted->note]);

		// Zlecenie przeliczenia: tylko zapis zlecenia i unieważnienie klucza — bez przeliczenia w żądaniu.
		$before = $this->strategy->topic($context, $main->publicId)['topic']->refreshedAt;
		$this->clock->advance(60);
		$this->strategy->requestRefresh($context);
		$state = $this->strategy->panelState($context);
		self::assertFalse($state['up_to_date']);
		self::assertNotNull($state['requested_at']);
		self::assertSame($context->userId(), $state['requested_by']);
		self::assertSame($before, $this->strategy->topic($context, $main->publicId)['topic']->refreshedAt, 'Zlecenie nie przelicza tematów.');
		$this->strategy->refresh($context);
		$state = $this->strategy->panelState($context);
		self::assertSame([true, null], [$state['up_to_date'], $state['requested_at']], 'Przeliczenie (CLI / krok w tle) kasuje zlecenie.');
		self::assertSame(['planned', 'Druga notatka'], [$this->strategy->topic($context, $main->publicId)['topic']->status, $this->strategy->topic($context, $main->publicId)['topic']->note]);

		// Przeliczenie w toku — odczyt blokady projektu.
		self::assertTrue(self::db()->acquireLock(StrategyRefresher::lock($context->projectId()), 0));
		self::assertTrue($this->strategy->panelState($context)['running']);
		self::db()->releaseLock(StrategyRefresher::lock($context->projectId()));
		self::assertFalse($this->strategy->panelState($context)['running']);

		// Odnośniki z innych modułów (tekst frazy → temat).
		$links = $this->strategy->topicsForTexts($context, ['pozycjonowanie stron www', 'audyt seo', 'fraza spoza strategii']);
		self::assertSame(['pozycjonowanie stron www', 'audyt seo'], array_keys($links));
		self::assertSame([$main->publicId, 'planned'], [$links['pozycjonowanie stron www']['topic'], $links['pozycjonowanie stron www']['status']]);

		// Klient: tylko odczyt, bez odrzuconych, notatek i identyfikatorów użytkowników.
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);
		$overview = $this->strategy->overview($clientContext);
		self::assertNull($overview['counts']['dismissed']);
		self::assertSame(2, $overview['counts']['open']);
		self::assertNull($overview['state']['requested_by']);
		self::assertSame([], array_filter(array_column($overview['events'], 'user')));
		self::assertNotContains('audyt seo', array_column($overview['events'], 'label'));
		self::assertSame(['pozycjonowanie stron www'], array_keys($this->strategy->topicsForTexts($clientContext, ['pozycjonowanie stron www', 'audyt seo'])));
		$view = $this->strategy->topicView($clientContext, $main->publicId);
		self::assertNull($view['topic']->note);
		self::assertNull($view['topic']->statusChangedBy);
		self::assertNull($view['context']['workflow']['note']);
		self::assertSame([], array_filter(array_column($view['events'], 'user')));
		$manual = $this->strategy->topicView($clientContext, 'agencja seo łódź')['evidence'];
		self::assertArrayNotHasKey('added_by', reset($manual)['manual'] ?? [], 'Bez identyfikatora użytkownika wpisu ręcznego.');
		self::assertArrayHasKey('added_by', reset($this->strategy->topicView($context, 'agencja seo łódź')['evidence'])['manual']);

		foreach ([
			'zlecenie przeliczenia' => fn () => $this->strategy->requestRefresh($clientContext),
			'notatka' => fn () => $this->strategy->setNote($clientContext, $main->publicId, 'x'),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Klient nie może: ' . $operation);
			} catch (AccessDenied) {
			}
		}

		try {
			$this->strategy->topicView($clientContext, 'audyt seo');
			self::fail('Odrzucony temat niewidoczny dla klienta.');
		} catch (StrategyNotFound) {
		}

		self::assertSame('Druga notatka', $this->strategy->topic($context, $main->publicId)['topic']->note);
	}

	public function test_module_links_use_strategy_evidence_and_hide_dismissed_topics_from_the_client(): void
	{
		$context = $this->scenario();
		$opportunity = $this->opportunity($context, 'near_top', self::PAGE, null, self::PAGE . "\npozycjonowanie stron", 70);
		$this->strategy->refresh($context);

		// Szansa SEO → temat wyłącznie przez dowód Strategii (powiązanie bezpośrednie query × page); wspólna podstrona to tylko kontekst.
		$links = $this->strategy->topicsForOpportunity($context, $opportunity, ['pozycjonowanie stron', 'pozycjonowanie stron www', 'audyt seo']);
		self::assertSame(['pozycjonowanie stron'], array_keys($links));
		self::assertSame([], $this->strategy->topicsForOpportunity($context, Ulid::generate(), ['pozycjonowanie stron']), 'Inna szansa — bez powiązania.');

		// Filtr „bez świeżego pomiaru SERP” daje dokładnie listę z licznika przeglądu.
		$overview = $this->strategy->overview($context);
		self::assertSame($overview['counts']['no_fresh_serp'], $this->strategy->topics($context, TopicFilters::fromInput(['serp' => 'nofresh']))['total']);
		$priorities = array_map(static fn (TopicRow $row): ?int => $row->priority, $overview['top']);
		self::assertNotSame([], $priorities);
		$sorted = $priorities;
		rsort($sorted);
		self::assertSame($sorted, $priorities, 'Najwyższy priorytet — malejąco.');

		// Frazy rynkowe → temat; klient nie widzi odrzuconych tematów ani SERP Intelligence ich fraz.
		$audit = $this->strategy->keyword($context, 'audyt seo');
		$this->strategy->setStatus($context, 'audyt seo', TopicStatus::Dismissed);
		self::assertSame('dismissed', $this->strategy->topicsForMarketKeywords($context, [$audit->marketKeywordId, 0])[$audit->marketKeywordId]['status'] ?? null);
		self::assertNotContains('audyt seo', array_map(static fn (TopicRow $row): string => (string) $row->label, $this->strategy->overview($context)['top']));
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);
		self::assertSame([], $this->strategy->topicsForMarketKeywords($clientContext, [$audit->marketKeywordId]));
		self::assertSame('audyt seo', $this->strategy->serpDetail($context, 'audyt seo')['candidate']->keyword);

		try {
			$this->strategy->serpDetail($clientContext, 'audyt seo');
			self::fail('Fraza odrzuconego tematu niewidoczna dla klienta.');
		} catch (StrategyNotFound) {
		}
	}

	public function test_serp_intelligence_list_topic_view_and_dominant_domains_from_stored_measurements(): void
	{
		$context = $this->trackedProject(['buty do biegania', 'obuwie do biegania', 'kurtka zimowa']);
		$shared = ['https://sklep-a.example/biegowe/', 'https://sklep-b.example/biegowe/', 'https://sklep-c.example/biegowe/', 'https://sklep-d.example/biegowe/', 'https://sklep-e.example/biegowe/'];
		$this->measure($context, [
			'buty do biegania' => self::urls([...$shared, 'https://example.pl/buty/'], 'buty'),
			'obuwie do biegania' => self::urls(array_reverse($shared), 'obuwie'),
			'kurtka zimowa' => self::urls(['https://sklep-a.example/kurtki/'], 'kurtka'),
		]);
		$this->strategy->refresh($context);
		$requests = count($this->dataForSeoRequests());

		$list = $this->strategy->serpKeywords($context, 'fresh');
		self::assertSame(['fresh' => 3, 'stale' => 0, 'missing' => 0, 'all' => 3], $list['counts']);
		self::assertSame(['fresh' => 2, 'stale' => 0, 'none' => 0], $list['topics']);
		$buty = array_values(array_filter($list['rows'], static fn (array $row): bool => $row['keyword'] === 'buty do biegania'))[0];
		self::assertSame(['fresh', 6], [$buty['freshness'], $buty['intel']['project']['rank'] ?? null], 'Pozycja SERP projektu ze świeżego pomiaru.');
		self::assertNotNull($buty['topic']);

		$view = $this->strategy->topicView($context, 'obuwie do biegania');
		self::assertSame('buty do biegania', $view['serp']['keyword']['keyword']);
		self::assertCount(20, $view['serp']['detail']['results']);
		self::assertSame('Tytuł sklep-a.example #1', $view['serp']['detail']['results'][0]['title']);
		self::assertTrue($view['serp']['detail']['results'][5]['project']);
		self::assertSame([['obuwie do biegania', 'strong']], array_map(static fn (array $row): array => [$row['keyword'], $row['level']], $view['serp']['overlap']));
		self::assertSame([2, []], [$view['context']['context_version'], $view['context']['discovery']]);

		$domains = $this->strategy->serpDomains($context);
		self::assertSame(3, $domains['measurements']);
		self::assertSame(['sklep-a.example', 3], [$domains['domains'][0]['host'], $domains['domains'][0]['count']]);

		$detail = $this->strategy->serpDetail($context, 'kurtka zimowa');
		self::assertSame('kurtka zimowa', $detail['candidate']->keyword);
		self::assertSame('kurtka zimowa', $detail['topic']['label'] ?? null);
		self::assertSame(count($this->dataForSeoRequests()), $requests, 'SERP Intelligence w panelu nie wysyła żądań.');
		self::assertSame(0, (int) self::db()->fetchValue('SELECT COUNT(*) FROM `' . self::db()->table('serp_snapshot_profiles') . '` WHERE project_id = %d AND created_at > %s', [$context->projectId(), $this->clock->now()->format('Y-m-d H:i:s')]));

		// Po 40 dniach pomiar jest nieaktualny: bez Pozycji SERP projektu, kształt z obniżoną pewnością.
		$this->clock->advance(40 * 86400);
		$stale = $this->strategy->serpKeywords($context, 'stale');
		self::assertSame(3, $stale['counts']['stale']);
		self::assertNull(array_values(array_filter($stale['rows'], static fn (array $row): bool => $row['keyword'] === 'buty do biegania'))[0]['intel']['project']);
		self::assertSame([], $this->strategy->serpKeywords($context, 'fresh')['rows']);
	}

	/**
	 * Projekt example.pl: dwie frazy z tą samą stroną (GSC), osobna strona audytu, wpis ręczny bez danych.
	 */
	private function scenario(): ProjectContext
	{
		$context = $this->gapProject();
		$this->gscKeyword($context, 'pozycjonowanie stron', 600, 14.0, self::PAGE);
		$this->gscKeyword($context, 'pozycjonowanie stron www', 300, 16.0, self::PAGE);
		$this->gscKeyword($context, 'audyt seo', 200, 8.0, 'https://example.pl/audyt/');
		$this->strategy->addKeywords($context, ['agencja seo łódź']);

		return $context;
	}

	/**
	 * TOP20: podane adresy na pierwszych pozycjach, reszta — unikalne adresy frazy.
	 *
	 * @param list<string> $urls
	 * @return list<array<string, mixed>>
	 */
	private static function urls(array $urls, string $slug): array
	{
		$items = [];

		for ($position = 1; $position <= 20; $position++) {
			$url = $urls[$position - 1] ?? 'https://inny-' . $position . '-' . $slug . '.example/' . $slug . '/';
			$items[] = DataForSeoFakes::serpOrganic($position, (string) parse_url($url, PHP_URL_HOST), $url);
		}

		return $items;
	}
}
