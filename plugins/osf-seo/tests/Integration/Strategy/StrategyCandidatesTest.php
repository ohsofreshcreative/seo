<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Discovery\CandidateStatus;
use OsfSeo\Discovery\ExclusionList;
use OsfSeo\Gap\GapStatus;
use OsfSeo\Strategy\StrategyConfig;
use OsfSeo\Strategy\StrategyRefresher;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Kandydaci Strategii (STEP 16, faza A): źródła i ich progi, decyzje użytkownika w modułach, filtry marki i wykluczeń,
 * fakty i dowody per fraza, klucz danych (także mutacje ręczne), limit kandydatów, podgląd bez zapisu — bez żadnych żądań do API.
 */
final class StrategyCandidatesTest extends StrategyTestCase
{
	public function test_refresh_materializes_candidates_of_all_sources_with_facts_and_evidence_without_requests(): void
	{
		$context = $this->scenario();
		$requests = count($this->dataForSeoRequests());

		$report = $this->strategy->refresh($context);

		self::assertNull($report['skipped']);
		self::assertSame($requests, count($this->dataForSeoRequests()), 'Przeliczenie Strategii nie wysyła żądań.');
		self::assertSame([
			'audyt seo' => ['gsc'],
			'buty damskie' => ['serp'],
			'content marketing' => ['manual'],
			'kampanie google ads' => ['discovery'],
			'pozycjonowanie stron' => ['opportunity', 'gsc'],
			'sklep internetowy' => ['gap'],
			'sklep internetowy cena' => ['gap'],
			'tworzenie stron' => ['discovery'],
		], $this->activeCandidates($context), 'Bez fraz poniżej progów GSC, odrzuconych, wykluczonych i markowych.');
		self::assertSame([], $this->inactiveCandidates($context));
		self::assertSame(['brand_competitor' => 1, 'excluded' => 1], $report['stats']['filtered']);
		self::assertSame(2, $report['stats']['new_market_keywords'], 'Frazy GSC bez wiersza rynkowego dostają go przy zapisie.');
		self::assertSame(['inserted' => 7, 'updated' => 1, 'unchanged' => 0, 'deactivated' => 0], array_intersect_key($report, array_flip(['inserted', 'updated', 'unchanged', 'deactivated'])));

		$tiers = [];

		foreach ($this->strategy->candidates($context, new \OsfSeo\Strategy\CandidateFilters(perPage: 500))['rows'] as $row) {
			$tiers[$row->keyword] = $row->tier;
		}

		self::assertSame([
			'content marketing' => 0,
			'buty damskie' => 1,
			'pozycjonowanie stron' => 2,
			'kampanie google ads' => 2,
			'sklep internetowy' => 3,
			'sklep internetowy cena' => 3,
			'tworzenie stron' => 5,
			'audyt seo' => 6,
		], $tiers, 'Kolejność: ręczne, Pozycje, decyzje, luki, Nowe frazy, GSC.');

		// Fakty GSC: wszystkie warianty zapisu frazy, średnia pozycja ważona wyświetleniami, strony scalone po adresie bez fragmentu.
		$row = $this->strategy->keyword($context, 'Pozycjonowanie  stron');
		self::assertSame([400, 7, 13.0, 1], [$row->gscImpressions, $row->gscClicks, $row->gscPosition, $row->gscPages]);
		self::assertSame(1, $row->opportunities);
		$evidence = $row->evidence;
		self::assertSame(['v', 'keyword', 'sources', 'gsc', 'opportunity'], array_keys($evidence));
		self::assertSame(2, $evidence['gsc']['variants']);
		self::assertSame([['url' => 'https://example.pl/pozycjonowanie/', 'impressions' => 400, 'clicks' => 7, 'share' => 1.0]], $evidence['gsc']['pages']);
		self::assertSame(['near_top', 'member', 'new'], [$evidence['opportunity'][0]['type'], $evidence['opportunity'][0]['link'], $evidence['opportunity'][0]['status']]);
		$db = self::db();
		$facts = $db->fetchRow("SELECT s.gsc_top_url_id, s.gsc_top_share, u.url, s.evidence FROM `{$db->table('strategy_keywords')}` s LEFT JOIN `{$db->table('serp_urls')}` u ON u.id = s.gsc_top_url_id WHERE s.public_id = %s", [$row->publicId]);
		self::assertSame(['https://example.pl/pozycjonowanie/', '1.0000'], [$facts['url'], $facts['gsc_top_share']], 'Strona GSC w słowniku adresów wspólnym z SERP i Labs.');
		self::assertStringNotContainsString('_facts', (string) $facts['evidence']);
		self::assertStringNotContainsString('url_id', (string) $facts['evidence'], 'W dowodach wyłącznie publiczne identyfikatory.');

		// Odrzucona szansa nie wprowadza frazy, ale zostaje w dowodach (fraza weszła źródłem GSC).
		$audit = $this->strategy->keyword($context, 'audyt seo');
		self::assertSame(['low_ctr', 'dismissed', 'member'], [$audit->evidence['opportunity'][0]['type'], $audit->evidence['opportunity'][0]['status'], $audit->evidence['opportunity'][0]['link']]);
		self::assertSame(StrategyConfig::EVIDENCE_PAGES >= 1, isset($audit->evidence['gsc']['pages'][0]));

		// Luka: wyniki przeliczenia Luk SEO (pozycja konkurenta z bazy Labs), grupa i luka treści jako dowód, nie źródło.
		$gap = $this->strategy->keyword($context, 'sklep internetowy');
		self::assertSame([0, 0, null, 0], [$gap->gscImpressions, $gap->gscClicks, $gap->gscPosition, $gap->gscPages], 'Projekt ma dane GSC — zero, nie NULL; to nie dowód braku widoczności.');
		self::assertSame(['unknown', 'unknown', 1, 'konkurent.pl'], [$gap->evidence['gap']['gap_type'], $gap->evidence['gap']['visibility'], $gap->evidence['gap']['best_competitor']['rank_labs'], $gap->evidence['gap']['best_competitor']['domain']]);
		self::assertSame(['unclear', 'low'], [$gap->evidence['content_gap']['content_gap'], $gap->evidence['content_gap']['confidence']]);
		self::assertSame([800, 30, 'commercial'], [$gap->searchVolume, $gap->keywordDifficulty, $gap->intent], 'Metryki rynkowe czytane z market_keywords, nie kopiowane.');
		$ids = $db->fetchRow("SELECT gap_keyword_id, gap_cluster_id FROM `{$db->table('strategy_keywords')}` WHERE public_id = %s", [$gap->publicId]);
		self::assertSame($this->gap($context, 'sklep internetowy')['id'], $ids['gap_keyword_id']);
		self::assertNotNull($ids['gap_cluster_id']);

		// Nowe frazy: decyzja użytkownika w dowodach, „Widoczność GSC” tylko jako informacja.
		$discovery = $this->strategy->keyword($context, 'kampanie google ads');
		self::assertSame(['accepted', 10, 'unknown'], [$discovery->evidence['discovery']['status'], $discovery->evidence['discovery']['priority'], $discovery->evidence['discovery']['visibility_gsc']]);

		// Wpis ręczny: kto i kiedy (bez odpytywania API o metryki).
		$manual = $this->strategy->keyword($context, 'content marketing');
		self::assertTrue($manual->manual);
		self::assertSame($context->userId(), $manual->evidence['manual']['added_by']);
		self::assertNull($manual->searchVolume);

		// Monitorowana fraza bez pomiaru: brak faktów SERP (nie 0 i nie „poza TOP”).
		$tracked = $this->strategy->keyword($context, 'buty damskie');
		self::assertSame([null, null, null], [$tracked->serpCheckedAt, $tracked->serpFound, $tracked->serpRank]);
		self::assertSame('active', $tracked->evidence['serp']['status']);
		self::assertSame($requests, count($this->dataForSeoRequests()));
	}

	public function test_serp_measurement_becomes_facts_and_rank_change_evidence(): void
	{
		$context = $this->trackedProject(['buty damskie', 'kozaki']);
		$this->measure($context, ['buty damskie' => DataForSeoFakes::serpTop([4 => 'example.pl']), 'kozaki' => DataForSeoFakes::serpTop([18 => 'example.pl'])]);
		$this->measureNextWeek($context, ['buty damskie' => DataForSeoFakes::serpTop([12 => 'example.pl']), 'kozaki' => DataForSeoFakes::serpTop([])]);
		$requests = count($this->dataForSeoRequests());

		$this->strategy->refresh($context);

		$shoes = $this->strategy->keyword($context, 'buty damskie');
		self::assertSame([true, 12], [$shoes->serpFound, $shoes->serpRank]);
		self::assertSame(['down', -8, 4], [$shoes->evidence['serp']['change'], $shoes->evidence['serp']['change_value'], $shoes->evidence['serp']['prev_rank']]);
		self::assertSame(['top3' => null, 'top10' => 'left', 'top20' => null], $shoes->evidence['serp']['bands']);
		self::assertTrue($shoes->evidence['serp']['decline']);
		self::assertNull($shoes->gscImpressions, 'Projekt bez danych GSC: NULL, nie 0.');
		self::assertNull($shoes->evidence['gsc']);

		$boots = $this->strategy->keyword($context, 'kozaki');
		self::assertSame([false, null], [$boots->serpFound, $boots->serpRank], 'Poza TOP to nie pozycja 0.');
		self::assertSame(['left', 'left'], [$boots->evidence['serp']['change'], $boots->evidence['serp']['bands']['top20']]);
		self::assertTrue($boots->evidence['serp']['decline']);
		self::assertSame($requests, count($this->dataForSeoRequests()));
		$db = self::db();
		self::assertSame('1', $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('strategy_keywords')}` WHERE project_id = %d AND serp_url_id IS NOT NULL", [$context->projectId()]));
	}

	public function test_manual_changes_in_every_module_change_the_data_key_and_refresh_candidates(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$requests = count($this->dataForSeoRequests());
		$expect = fn (string $label): array => $this->assertRefreshed($context, $label);

		self::assertSame(StrategyRefresher::SKIPPED_UNCHANGED, $this->strategy->refresh($context)['skipped'], 'Bez zmian — bez przeliczenia.');
		self::assertTrue($this->strategy->status($context)['up_to_date']);

		$this->discoveryCandidates->bulkStatus($context->projectId(), [$this->discoveryId($context, 'tworzenie stron')], CandidateStatus::Dismissed, $context->userId());
		$expect('Nowe frazy: odrzucenie');
		self::assertSame('no_source', $this->inactiveCandidates($context)['tworzenie stron']);
		$dropped = $this->strategy->keyword($context, 'tworzenie stron');
		self::assertSame([false, [], null], [$dropped->active, $dropped->sources, $dropped->tier], 'Nieaktywny: bez źródeł i poziomu.');
		self::assertSame('new', $dropped->evidence['discovery']['status'], 'Dowody — stan z ostatniego przeliczenia, w którym fraza była kandydatem.');

		$this->gaps->setStatus($context, 'keyword', [(string) $this->gap($context, 'sklep internetowy cena')['public_id']], GapStatus::Dismissed);
		$expect('Luki SEO: odrzucenie luki');
		self::assertSame('no_source', $this->inactiveCandidates($context)['sklep internetowy cena']);

		$db = self::db();
		$opportunity = (int) $db->fetchValue("SELECT id FROM `{$db->table('opportunities')}` WHERE project_id = %d AND type = 'near_top'", [$context->projectId()]);
		$this->opportunities->updateWorkflow($context->projectId(), $opportunity, ['status' => 'dismissed', 'status_changed_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
		$expect('Szanse SEO: odrzucenie szansy');
		self::assertSame(['gsc'], $this->activeCandidates($context)['pozycjonowanie stron'], 'Fraza zostaje ze źródła GSC.');
		self::assertSame(6, $this->strategy->keyword($context, 'pozycjonowanie stron')->tier);

		$this->serp->removeKeywords($context, [(string) $this->serp->trackedId($context, 'buty damskie')]);
		$expect('Pozycje: usunięcie z monitorowania');
		self::assertSame('no_source', $this->inactiveCandidates($context)['buty damskie']);

		$this->discoverySettings->saveExclusions($context->projectId(), ExclusionList::parse("darmowe\naudyt"), $context->userId());
		$expect('Wykluczenia projektu');
		self::assertSame('excluded', $this->inactiveCandidates($context)['audyt seo']);

		$this->competitorRepository->updateBrandTerms($this->competitor($context, 'konkurent.pl'), 'konkurent, sklep', $context->userId());
		$expect('Marka konkurenta');
		self::assertSame('brand_competitor', $this->inactiveCandidates($context)['sklep internetowy']);
		self::assertSame('no_source', $this->inactiveCandidates($context)['sklep internetowy cena'], 'Odrzucona luka zostaje z powodem „brak źródła”.');

		self::assertSame(1, $this->strategy->removeKeywords($context, ['content marketing']));
		$expect('Strategia: zdjęcie wpisu ręcznego');
		self::assertSame('no_source', $this->inactiveCandidates($context)['content marketing']);
		$publicId = $this->strategy->keyword($context, 'content marketing')->publicId;

		self::assertSame(['added' => 1, 'existing' => 0, 'rejected' => []], $this->strategy->addKeywords($context, ['Content  Marketing']));
		$expect('Strategia: ponowny wpis ręczny');
		$again = $this->strategy->keyword($context, 'content marketing');
		self::assertTrue($again->active);
		self::assertSame($publicId, $again->publicId, 'Identyfikator kandydata się nie zmienia.');

		// Wpis ręczny nie podlega filtrom marki i wykluczeń (jawna decyzja użytkownika).
		self::assertSame(1, $this->strategy->addKeywords($context, 'audyt seo')['added']);
		$expect('Strategia: wpis ręczny frazy wykluczonej');
		self::assertSame(['manual', 'gsc'], $this->activeCandidates($context)['audyt seo']);

		$this->clock->advance(86400);
		self::assertNull($this->strategy->refresh($context)['skipped'], 'Nowy dzień — przeliczenie (intencja i metryki dostawcy).');
		self::assertSame(StrategyRefresher::SKIPPED_UNCHANGED, $this->strategy->refresh($context)['skipped']);
		self::assertNull($this->strategy->refresh($context, true)['skipped'], 'Wymuszone przeliczenie.');
		self::assertSame($requests, count($this->dataForSeoRequests()), 'Mutacje i przeliczenia bez żądań.');
	}

	public function test_preview_writes_nothing(): void
	{
		$context = $this->scenario();
		$db = self::db();
		$before = $this->tableCounts();
		$requests = count($this->dataForSeoRequests());

		$preview = $this->strategy->preview($context, 3);

		self::assertNull($preview['skipped']);
		self::assertTrue($preview['dry_run']);
		self::assertSame(0, $preview['api_requests']);
		self::assertSame($before, $this->tableCounts(), 'Podgląd bez zapisu (frazy rynkowe, słownik adresów, kandydaci, ustawienia, klucze fraz GSC).');
		self::assertSame($requests, count($this->dataForSeoRequests()));
		self::assertSame(7, $preview['gsc']['unkeyed'], 'Frazy GSC bez klucza rynkowego (wyliczy go krok w tle albo przeliczenie).');
		self::assertSame(['content marketing', 'buty damskie', 'kampanie google ads'], array_column($preview['sample'], 'keyword'));
		self::assertSame(0, $preview['stats']['signals']['gsc'] ?? 0, 'Bez kluczy rynkowych źródło GSC jest puste w podglądzie.');

		$this->strategy->refresh($context);
		$preview = $this->strategy->preview($context, 50);
		self::assertSame(0, $preview['gsc']['unkeyed']);
		self::assertSame(8, $preview['stats']['selected']);
		self::assertSame(0, $preview['stats']['new_market_keywords']);
		self::assertSame('1', $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('strategy_settings')}` WHERE project_id = %d", [$context->projectId()]));
	}

	public function test_limit_marks_lower_tier_candidates_as_overflow_including_gsc_keywords_beyond_the_source_limit(): void
	{
		putenv(StrategyConfig::MAX_KEYWORDS . '=100');
		$this->buildStrategy();
		$context = $this->gapProject();

		foreach (range(1, 103) as $i) {
			$this->gscKeyword($context, 'fraza ' . $i, 1000 + $i, 10.0);
		}

		$report = $this->strategy->refresh($context);
		self::assertSame(100, $report['stats']['selected']);
		self::assertSame(3, $report['stats']['overflow'], 'Frazy ponad limit są liczone, nie pomijane po cichu.');
		self::assertSame(100, $this->strategy->status($context)['counts']['active']);
		self::assertNull($this->candidateRow($context, 'fraza 1'), 'Najsłabsze frazy GSC ponad limitem nie dostają wiersza.');

		// Wpisy ręczne mają pierwszeństwo — najsłabsze frazy GSC wypadają z powodem „ponad limit”.
		$this->strategy->addKeywords($context, ['strategia seo', 'plan treści']);
		$this->strategy->refresh($context);
		self::assertSame(100, $this->strategy->status($context)['counts']['active']);
		self::assertSame(['fraza 4' => 'overflow', 'fraza 5' => 'overflow'], $this->inactiveCandidates($context));

		// Nowe, silniejsze frazy GSC: dotychczasowi kandydaci spoza zwróconych przez źródło też dostają „ponad limit”, nie „brak źródła”.
		foreach (range(1, 3) as $i) {
			$this->gscKeyword($context, 'nowa fraza ' . $i, 5000 + $i, 10.0);
		}

		$this->strategy->refresh($context);
		self::assertSame(100, $this->strategy->status($context)['counts']['active']);
		self::assertSame(['fraza 4' => 'overflow', 'fraza 5' => 'overflow', 'fraza 6' => 'overflow', 'fraza 7' => 'overflow', 'fraza 8' => 'overflow'], $this->inactiveCandidates($context));
		self::assertSame(['active' => 100, 'manual' => 2, 'inactive' => ['overflow' => 5]], $this->strategy->status($context)['counts']);
	}

	public function test_market_change_deactivates_candidates_of_the_previous_market(): void
	{
		$context = $this->gapProject();
		$this->gscKeyword($context, 'sklep internetowy', 300, 8.0);
		$this->strategy->addKeywords($context, 'audyt seo');
		$this->strategy->refresh($context);
		self::assertCount(2, $this->activeCandidates($context));

		$this->projects->update($context->projectId(), ['country' => 'de', 'language' => 'de']);
		$context = $context->withProject($this->projects->reload($context->project()));
		$report = $this->strategy->refresh($context);

		self::assertNull($report['skipped']);
		self::assertSame(['audyt seo' => 'market_changed', 'sklep internetowy' => 'market_changed'], $this->inactiveCandidates($context), 'Wpis ręczny i fraza GSC poprzedniego rynku.');
		// Fraza GSC wraca jako kandydat nowego rynku (osobna fraza rynkowa); wpis ręczny — nie (decyzja dotyczyła innego rynku).
		self::assertSame(['sklep internetowy' => ['gsc']], $this->activeCandidates($context));
		self::assertNull($this->candidateRow($context, 'audyt seo'));
		self::assertSame(['active' => 1, 'manual' => 0, 'inactive' => ['market_changed' => 2]], $this->strategy->status($context)['counts']);
		self::assertSame('Niemcy / niemiecki', $this->strategy->status($context)['market']);
	}

	public function test_refresh_is_skipped_while_another_process_refreshes_the_project(): void
	{
		$context = $this->gapProject();
		$this->strategy->addKeywords($context, 'audyt seo');
		$other = $this->holdLock('strategy_refresh_' . $context->projectId());

		try {
			self::assertSame(StrategyRefresher::SKIPPED_LOCKED, $this->strategy->refresh($context)['skipped']);
		} finally {
			$this->releaseHeldLock($other, 'strategy_refresh_' . $context->projectId());
		}

		self::assertNull($this->strategy->refresh($context)['skipped']);
	}

	/**
	 * Projekt z danymi wszystkich źródeł: Luki SEO (import atrapą), GSC, wykluczenia i marka konkurenta, monitorowana fraza,
	 * Nowe frazy, szanse SEO i wpis ręczny.
	 */
	private function scenario(): ProjectContext
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [
			self::ranked('konkurent.pl', 'sklep internetowy', 800, 1, '/sklep/'),
			self::ranked('konkurent.pl', 'sklep internetowy cena', 300, 2, '/sklep/'),
		]);
		$this->gapRun($context, ['baseline' => '0']);
		$this->gscKeyword($context, 'pozycjonowanie stron', 300, 12.0, 'https://example.pl/pozycjonowanie/', 6);
		$this->gscKeyword($context, 'Pozycjonowanie Stron', 100, 16.0, 'https://example.pl/pozycjonowanie/#oferta', 1);
		$this->gscKeyword($context, 'audyt seo', 60, 30.0, 'https://example.pl/audyt/');
		$this->gscKeyword($context, 'rzadka fraza', 10, 5.0);
		$this->gscKeyword($context, 'fraza daleka', 100, 70.0);
		$this->gscKeyword($context, 'darmowe pozycjonowanie', 200, 20.0);
		$this->gscKeyword($context, 'konkurent cennik', 150, 9.0);
		$this->discoverySettings->saveExclusions($context->projectId(), ExclusionList::parse('darmowe'), $context->userId());
		$this->competitorRepository->updateBrandTerms($this->competitor($context, 'konkurent.pl'), 'konkurent', $context->userId());
		$this->serp->addKeywords($context, 'manual', ['buty damskie']);
		$this->discoveryCandidate($context, 'kampanie google ads', 'accepted', 10);
		$this->discoveryCandidate($context, 'tworzenie stron', 'new', 60);
		$this->discoveryCandidate($context, 'odrzucona fraza', 'dismissed', 95);
		$this->discoveryCandidate($context, 'słaba fraza', 'new', 20);
		$this->discoveryCandidate($context, 'wykluczona fraza', 'review', 90, true);
		$this->opportunity($context, 'near_top', 'https://example.pl/pozycjonowanie/', null, "https://example.pl/pozycjonowanie/\npozycjonowanie stron\nseo lokalne", 70);
		$this->opportunity($context, 'low_ctr', 'https://example.pl/audyt/', null, "https://example.pl/audyt/\naudyt seo", 40, 'dismissed');
		$this->strategy->addKeywords($context, 'content marketing');

		return $context;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function assertRefreshed(ProjectContext $context, string $label): array
	{
		self::assertFalse($this->strategy->status($context)['up_to_date'], $label . ': klucz danych zmieniony.');
		$report = $this->strategy->refresh($context);
		self::assertNull($report['skipped'], $label);
		self::assertTrue($this->strategy->status($context)['up_to_date'], $label);

		return $report;
	}

	private function discoveryId(ProjectContext $context, string $keyword): string
	{
		$db = self::db();

		return (string) $db->fetchValue(
			"SELECT c.public_id FROM `{$db->table('discovery_candidates')}` c JOIN `{$db->table('market_keywords')}` m ON m.id = c.market_keyword_id
			WHERE c.project_id = %d AND m.keyword_key = UNHEX(%s)",
			[$context->projectId(), md5($keyword)],
		);
	}

	/**
	 * @return array<string, int>
	 */
	private function tableCounts(): array
	{
		$db = self::db();
		$result = [];

		foreach (['market_keywords', 'serp_urls', 'serp_domains', 'strategy_keywords', 'strategy_settings'] as $table) {
			$result[$table] = (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}`");
		}

		$result['keyed'] = (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('keywords')}` WHERE market_key IS NOT NULL");

		return $result;
	}
}
