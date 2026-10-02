<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Gap;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Auth\Roles;
use OsfSeo\Discovery\ExclusionList;
use OsfSeo\Gap\GapDomain;
use OsfSeo\Gap\GapFilters;
use OsfSeo\Gap\GapNotFound;
use OsfSeo\Gap\GapStatus;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Serp\Competitor;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Analiza Luk SEO na zapisanych danych (bez API poza atrapą importu): widoczność projektu (SERP → GSC → Labs), typ luki,
 * filtry, łączenie konkurentów, praca nad luką, grupy, luka treści, strony konkurencji, monitorowanie i autoryzacja.
 */
final class GapAnalysisTest extends GapTestCase
{
	public function test_visibility_hierarchy_serp_then_gsc_then_labs_baseline_and_gap_types(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [
			self::ranked('konkurent.pl', 'pozycjonowanie stron', 1000, 3),
			self::ranked('konkurent.pl', 'audyt seo', 100, 5),
			self::ranked('konkurent.pl', 'kampanie google ads', 500, 2),
			self::ranked('konkurent.pl', 'sklep internetowy', 800, 1),
			self::ranked('konkurent.pl', 'projektowanie logo', 300, 4),
			self::ranked('konkurent.pl', 'hosting stron', 5000, 6),
			self::ranked('konkurent.pl', 'serwis wordpress', 200, 7),
		]);
		$this->setRanked('example.pl', [self::ranked('example.pl', 'projektowanie logo', 300, 8, '/logo/')]);
		$this->gscKeyword($context, 'audyt seo', 300, 4.0);
		$this->gscKeyword($context, 'kampanie google ads', 50, 30.0);
		$this->gscKeyword($context, 'hosting stron', 20, 5.0);
		$this->serp->addKeywords($context, 'manual', ['pozycjonowanie stron', 'serwis wordpress']);
		$this->measure($context, ['pozycjonowanie stron' => DataForSeoFakes::serpTop([25 => 'example.pl']), 'serwis wordpress' => DataForSeoFakes::serpTop([])]);

		$this->gapRun($context);

		$expect = [
			'pozycjonowanie stron' => ['low', 'serp', 'weak', '25.00', '0'],
			'serwis wordpress' => ['none', 'serp', 'missing', null, '0'],
			'audyt seo' => ['visible', 'gsc', 'stronger', '4.00', '0'],
			'kampanie google ads' => ['low', 'gsc', 'weak', '30.00', '0'],
			'hosting stron' => ['low', 'gsc', 'weak', '5.00', '1'],
			'projektowanie logo' => ['visible', 'labs', 'competitive', '8.00', '0'],
			'sklep internetowy' => ['none', 'labs', 'missing', null, '0'],
		];

		foreach ($expect as $keyword => $values) {
			$gap = $this->gap($context, $keyword);
			self::assertNotNull($gap, $keyword);
			self::assertSame($values, [$gap['visibility'], $gap['visibility_source'], $gap['gap_type'], $gap['project_position'], $gap['sporadic']], $keyword);
		}

		self::assertSame('25', $this->gap($context, 'pozycjonowanie stron')['serp_rank']);
		self::assertSame('300', $this->gap($context, 'audyt seo')['gsc_impressions']);
		self::assertSame('8', $this->gap($context, 'projektowanie logo')['project_labs_rank']);
		self::assertSame('https://example.pl/logo/', $this->gaps->keywords($context, GapFilters::fromInput(['type' => 'all', 'q' => 'logo']))['rows'][0]['target_url'], 'Strona docelowa z Labs.');

		// Pomiar SERP starszy niż 30 dni nie jest już dowodem — kolejne źródło w hierarchii.
		$this->clock->advance(31 * 86400);
		$this->gaps->recalculate($context);
		self::assertSame(['none', 'labs', 'missing'], [$this->gap($context, 'pozycjonowanie stron')['visibility'], $this->gap($context, 'pozycjonowanie stron')['visibility_source'], $this->gap($context, 'pozycjonowanie stron')['gap_type']]);
	}

	public function test_baseline_absence_means_no_visibility_only_in_a_reliable_full_top100_dataset(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [
			self::ranked('konkurent.pl', 'fraza mocna', 5000, 3),
			self::ranked('konkurent.pl', 'fraza graniczna', 12, 4),
			self::ranked('konkurent.pl', 'fraza obecna', 800, 2),
		]);
		$this->setRanked('example.pl', [self::ranked('example.pl', 'fraza obecna', 800, 40)]);
		$this->gscKeyword($context, 'inna fraza projektu', 100, 5.0);

		$this->gapRun($context);

		self::assertSame(['none', 'labs'], [$this->gap($context, 'fraza mocna')['visibility'], $this->gap($context, 'fraza mocna')['visibility_source']], 'Pełne TOP100, kompletny import, wolumen z zapasem nad filtrem.');
		self::assertSame('unknown', $this->gap($context, 'fraza graniczna')['visibility'], 'Wolumen 12 przy filtrze 10 — fraza mogła nie przejść filtra.');
		self::assertSame(['low', 'labs'], [$this->gap($context, 'fraza obecna')['visibility'], $this->gap($context, 'fraza obecna')['visibility_source']]);

		// Domena projektu pobrana tylko w TOP30 (jako konkurent innego projektu) — brak frazy nie wyklucza pozycji 31–100.
		$third = $this->gapProject(['konkurent.pl' => 'Konkurent'], 'trzeci.pl');
		$this->gscKeyword($third, 'inna fraza projektu', 100, 5.0);
		$fourth = $this->gapProject(['trzeci.pl' => 'Trzeci'], 'czwarty.pl');
		$this->setRanked('trzeci.pl', [self::ranked('trzeci.pl', 'fraza obecna', 800, 25)]);
		$this->gapRun($fourth, ['baseline' => '0']);
		$top30 = $this->gapDomains->find($this->gaps->market($third), 'trzeci.pl');
		self::assertSame(30, $top30->coverage->maxRank);
		self::assertTrue($top30->absenceReliable(5000), 'Wiarygodny dla TOP30…');
		self::assertFalse($top30->provesNoVisibility(5000), '…ale nie dla braku widoczności.');

		$this->refresher->refresh($third->projectId(), true);

		self::assertSame('unknown', $this->gap($third, 'fraza mocna')['visibility']);
		self::assertSame(['low', 'labs'], [$this->gap($third, 'fraza obecna')['visibility'], $this->gap($third, 'fraza obecna')['visibility_source']], 'Fraza obecna w zbiorze — pozycja Labs.');
		self::assertStringContainsString('TOP30', (string) $top30->absenceDoubt(5000));
	}

	public function test_truncated_or_inconsistent_baseline_gives_unknown_instead_of_no_visibility(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [
			self::ranked('konkurent.pl', 'fraza bardzo popularna', 50000, 2),
			self::ranked('konkurent.pl', 'fraza srednia', 4500, 3),
			self::ranked('konkurent.pl', 'fraza niska', 900, 5),
		]);
		$this->setRanked('example.pl', array_map(static fn (int $i): array => self::ranked('example.pl', sprintf('baza %03d', $i), 10000 - 60 * $i, 20), range(0, 149)));
		$this->gscKeyword($context, 'inna fraza projektu', 100, 5.0);

		$this->gapRun($context, ['max_rows' => '100']);
		$baseline = $this->gapDomains->find($this->gaps->market($context), 'example.pl');

		self::assertSame([false, 4061, 100], [$baseline->complete, $baseline->coveredMinVolume, $baseline->coverage->maxRank], 'Punkt odniesienia przycięty limitem fraz po 100 frazach (ostatnia: 4060).');
		self::assertSame('none', $this->gap($context, 'fraza bardzo popularna')['visibility'], 'Wolumen 50 000 ≥ 6092 (granica z zapasem).');
		self::assertSame('unknown', $this->gap($context, 'fraza srednia')['visibility'], 'Wolumen 4500 nad granicą przycięcia, ale bez zapasu.');
		self::assertSame('unknown', $this->gap($context, 'fraza niska')['visibility'], 'Poniżej granicy przycięcia — mogła się nie zmieścić w limicie.');

		// Pierwszy import punktu odniesienia z nieczytelnym wynikiem — brak frazy niczego nie dowodzi.
		$other = $this->gapProject(['konkurent.pl' => 'Konkurent'], 'piaty.pl');
		$this->gscKeyword($other, 'inna fraza projektu', 100, 5.0);
		$items = [self::ranked('piaty.pl', 'fraza obca', 300, 7), self::ranked('piaty.pl', 'fraza nieczytelna', 200, 9)];
		$items[1]['ranked_serp_element']['serp_item']['rank_group'] = null;
		$this->rankedOverrides['piaty.pl'] = [['status' => 200, 'json' => DataForSeoFakes::rankedResult('piaty.pl', $items, 2, 0.01224)]];
		$this->gapRun($other, ['max_rows' => '100']);
		$unreliable = $this->gapDomains->find($this->gaps->market($other), 'piaty.pl');

		self::assertNull($unreliable->coveredMinVolume);
		self::assertSame('unknown', $this->gap($other, 'fraza bardzo popularna')['visibility']);
	}

	public function test_missing_gsc_row_alone_never_means_no_visibility(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [self::ranked('konkurent.pl', 'sklep internetowy', 800, 1), self::ranked('konkurent.pl', 'audyt seo', 100, 5)]);
		$this->gscKeyword($context, 'audyt seo', 300, 4.0);

		$this->gapRun($context, ['baseline' => '0']);

		$gap = $this->gap($context, 'sklep internetowy');
		self::assertSame(['unknown', null, 'unknown'], [$gap['visibility'], $gap['visibility_source'], $gap['gap_type']], 'Bez SERP i punktu odniesienia Labs: Nieznana, nie Brak.');
		self::assertSame('0', $gap['gsc_impressions']);

		// Niepełny punkt odniesienia (wiarygodny tylko powyżej wolumenu ostatniej frazy) — nieobecność niżej jest niepewna.
		$this->setRanked('example.pl', array_map(static fn (int $i): array => self::ranked('example.pl', 'inna fraza ' . $i, 5000 - $i, 5), range(0, 149)));
		$this->gapRun($context, ['max_rows' => '100']);
		$baseline = $this->gapDomains->find($this->gaps->market($context), 'example.pl');
		self::assertFalse($baseline->complete);
		self::assertSame(4902, $baseline->coveredMinVolume);
		self::assertSame('unknown', $this->gap($context, 'sklep internetowy')['visibility'], 'Wolumen 800 poniżej wiarygodnego zakresu punktu odniesienia.');
	}

	public function test_brand_exclusions_includes_language_volume_and_difficulty_are_filtered_not_deleted(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [
			self::ranked('konkurent.pl', 'example logowanie', 500, 1, null, 10, 'navigational'),
			self::ranked('konkurent.pl', 'konkurent cennik', 400, 1, null, 10, 'navigational'),
			self::ranked('konkurent.pl', 'kmarka sklep', 300, 2),
			self::ranked('konkurent.pl', 'praca seo', 900, 3),
			self::ranked('konkurent.pl', 'website design', 700, 4, null, 20, 'informational', 1.0, null, true),
			self::ranked('konkurent.pl', 'tanie strony', 50, 5),
			self::ranked('konkurent.pl', 'trudna fraza seo', 600, 6, null, 90),
			self::ranked('konkurent.pl', 'strony internetowe', 1000, 7),
			self::ranked('konkurent.pl', 'konkurent', 2000, 8, null, 15, 'commercial'),
		]);
		$this->discoverySettings->saveExclusions($context->projectId(), ExclusionList::parse('praca'), $context->userId());
		$this->gaps->saveBrandTerms($context, $this->competitor($context, 'konkurent.pl')->publicId, 'kmarka');
		$this->gaps->saveSettings($context, ['min_volume' => '100', 'max_difficulty' => '70']);

		$this->gapRun($context, ['baseline' => '0']);

		$reasons = [];

		foreach (['example logowanie', 'konkurent cennik', 'kmarka sklep', 'praca seo', 'website design', 'tanie strony', 'trudna fraza seo', 'strony internetowe', 'konkurent'] as $keyword) {
			$gap = $this->gap($context, $keyword);
			$reasons[$keyword] = [$gap['listed'], $gap['filter_reason']];
		}

		self::assertSame([
			'example logowanie' => ['0', 'brand_own'],
			'konkurent cennik' => ['0', 'brand_competitor'],
			'kmarka sklep' => ['0', 'brand_competitor'],
			'praca seo' => ['0', 'excluded'],
			'website design' => ['1', null],
			'tanie strony' => ['0', 'low_volume'],
			'trudna fraza seo' => ['0', 'high_difficulty'],
			'strony internetowe' => ['1', null],
			'konkurent' => ['1', null],
		], $reasons, 'Nazwa z domeny bez intencji nawigacyjnej nie ukrywa frazy; inny język nie jest filtrem; wiersze odfiltrowane zostają z powodem.');
		self::assertEqualsCanonicalizing(['strony internetowe', 'konkurent', 'website design'], array_column($this->gaps->keywords($context, GapFilters::fromInput([]))['rows'], 'keyword'));
		self::assertCount(6, $this->gaps->keywords($context, GapFilters::fromInput(['filtered' => '1']))['rows']);
		self::assertSame(['brand_competitor' => 2, 'brand_own' => 1, 'excluded' => 1, 'high_difficulty' => 1, 'low_volume' => 1], self::sorted($this->gaps->counts($context)['filter_reasons']));

		$this->gaps->saveSettings($context, ['include_terms' => 'strony']);
		$this->gaps->recalculate($context);
		self::assertSame(['0', 'not_included'], [$this->gap($context, 'konkurent')['listed'], $this->gap($context, 'konkurent')['filter_reason']], 'Słowa tematyczne: tylko frazy z nimi.');
		self::assertSame('1', $this->gap($context, 'strony internetowe')['listed']);
	}

	public function test_other_language_phrases_stay_gaps_with_a_badge_and_rank_threshold_change_recalculates_locally(): void
	{
		// Odtworzenie pierwszego smoke testu (rynek Polska / pl): frazy angielskie konkurenta oznaczone przez dostawcę jako inny język.
		$context = $this->gapProject(['wisepeople.pl' => 'WisePeople'], 'ohsofresh.pl');
		$english = static fn (string $keyword, int $volume, int $rank): array => self::ranked('wisepeople.pl', $keyword, $volume, $rank, null, 30, 'commercial', 2.5, null, true);
		$this->setRanked('wisepeople.pl', [
			$english('wordpress developer', 260, 2),
			$english('neontri', 390, 6),
			$english('heatmap', 2400, 11),
			$english('uxui designer', 720, 16),
			$english('sharebee', 480, 7),
			self::ranked('wisepeople.pl', 'projektowanie aplikacji', 320, 24),
		]);
		$this->setRanked('ohsofresh.pl', [self::ranked('ohsofresh.pl', 'strony internetowe', 1000, 5)]);

		$this->gapRun($context);
		$requests = count($this->dataForSeoRequests());
		$dataset = $this->gapDomains->find($this->gaps->market($context), 'wisepeople.pl');

		self::assertSame(6, $dataset->rowsPresent, 'Zbiór konkurenta: 6 fraz.');
		$listed = $this->gaps->keywords($context, GapFilters::fromInput(['type' => 'all']))['rows'];
		self::assertEqualsCanonicalizing(['wordpress developer', 'neontri', 'heatmap', 'uxui designer', 'sharebee'], array_column($listed, 'keyword'), 'Inny język nie wyklucza frazy.');
		self::assertSame(['1'], array_values(array_unique(array_column($listed, 'other_language'))), 'Informacja o innym języku zostaje przy frazie (badge).');
		self::assertSame(0, $this->gaps->counts($context)['filtered']);
		self::assertSame('1', self::db()->fetchValue("SELECT other_language FROM `" . self::db()->table('market_keywords') . "` WHERE keyword_key = UNHEX(%s)", [md5('heatmap')]), 'Dane dostawcy bez zmian.');
		self::assertNull($this->gap($context, 'projektowanie aplikacji'), 'Pozycja 24 poza progiem znaczącej pozycji (domyślnie TOP20) — fraza zbioru, ale nie luka.');

		// Zmiana progu w ustawieniach: tylko lokalne przeliczenie z zapisanego zbioru, bez żądań.
		$this->gaps->saveSettings($context, ['competitor_max_rank' => '30']);
		$this->gaps->recalculate($context);

		self::assertSame('1', $this->gap($context, 'projektowanie aplikacji')['listed']);
		self::assertCount($requests, $this->dataForSeoRequests(), 'Przeliczenie bez żadnego żądania do DataForSEO.');
		self::assertSame(6, $this->gapDomains->find($this->gaps->market($context), 'wisepeople.pl')->rowsPresent, 'Zbiór domeny bez zmian.');
	}

	public function test_gaps_filtered_by_the_old_language_rule_are_restored_by_background_recalculation_without_requests(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [self::ranked('konkurent.pl', 'heatmap', 2400, 11, null, 30, 'commercial', 2.5, null, true)]);
		$this->gapRun($context, ['baseline' => '0']);
		$requests = count($this->dataForSeoRequests());
		$db = self::db();
		// Stan sprzed poprawki: fraza odfiltrowana regułą języka, klucz danych z poprzedniej wersji przeliczenia.
		$db->execute("UPDATE `{$db->table('gap_keywords')}` SET listed = 0, filter_reason = 'foreign_language' WHERE project_id = %d", [$context->projectId()]);
		$this->gapSettings->invalidate($context->projectId());
		add_filter('wp_doing_cron', '__return_true');

		$this->gaps->runBackground(60.0, true);

		self::assertSame(['1', null], [$this->gap($context, 'heatmap')['listed'], $this->gap($context, 'heatmap')['filter_reason']]);
		self::assertCount($requests, $this->dataForSeoRequests());
	}

	public function test_competitors_are_aggregated_into_one_gap_row_with_best_competitor(): void
	{
		$context = $this->gapProject(['konkurent.pl' => 'Konkurent', 'drugi.pl' => 'Drugi']);
		$this->setRanked('konkurent.pl', [self::ranked('konkurent.pl', 'wspólna fraza', 900, 7), self::ranked('konkurent.pl', 'poza progiem', 800, 25)]);
		$this->setRanked('drugi.pl', [self::ranked('drugi.pl', 'wspólna fraza', 900, 2, '/wspolna/'), self::ranked('drugi.pl', 'druga fraza', 700, 15)]);

		$this->gapRun($context, ['baseline' => '0']);

		self::assertSame(2, self::tableCount('gap_keywords'), 'Jedna luka na frazę; pozycja 25 poza progiem znaczącej pozycji (TOP20).');
		$gap = $this->gap($context, 'wspólna fraza');
		$drugi = $this->competitor($context, 'drugi.pl');
		self::assertSame(['2', '2', (string) $drugi->id, '2'], [$gap['competitors_count'], $gap['competitors_top10'], $gap['best_competitor_id'], $gap['best_competitor_rank']]);
		self::assertSame('https://drugi.pl/wspolna/', $this->gaps->keywords($context, GapFilters::fromInput(['q' => 'wspólna']))['rows'][0]['best_url']);

		$konkurent = $this->competitor($context, 'konkurent.pl');
		self::assertSame(['wspólna fraza'], array_column($this->gaps->keywords($context, GapFilters::fromInput(['competitor' => $konkurent->publicId]))['rows'], 'keyword'));
		self::assertSame(['wspólna fraza', 'druga fraza'], array_column($this->gaps->keywords($context, GapFilters::fromInput(['competitor' => $drugi->publicId]))['rows'], 'keyword'));

		$detail = $this->gaps->keyword($context, (string) $gap['public_id']);
		$ranks = [];

		foreach ($detail['competitors'] as $entry) {
			$ranks[$entry['competitor']->name] = (int) $entry['row']['rank_group'];
		}

		self::assertSame(['Drugi' => 2, 'Konkurent' => 7], self::sorted($ranks), 'Szczegóły: pozycja każdego konkurenta.');
	}

	public function test_work_status_survives_recalculation_and_inactive_competitor(): void
	{
		$context = $this->gapProject(['konkurent.pl' => 'Konkurent', 'drugi.pl' => 'Drugi']);
		$this->setRanked('konkurent.pl', [self::ranked('konkurent.pl', 'wspólna fraza', 900, 7)]);
		$this->setRanked('drugi.pl', [self::ranked('drugi.pl', 'druga fraza', 700, 5)]);
		$this->gapRun($context, ['baseline' => '0']);
		$publicId = (string) $this->gap($context, 'druga fraza')['public_id'];

		self::assertSame(1, $this->gaps->setStatus($context, 'keyword', [$publicId], GapStatus::Accepted, 'Wpis na blogu w maju'));
		$this->gaps->recalculate($context);
		self::assertSame(['accepted', 'Wpis na blogu w maju'], [$this->gap($context, 'druga fraza')['status'], $this->gap($context, 'druga fraza')['note']]);

		$drugi = $this->competitor($context, 'drugi.pl');
		$this->competitorRepository->update($drugi, $drugi->name, $drugi->domain, Competitor::INACTIVE, $context->userId());
		$this->gaps->recalculate($context);
		$gap = $this->gap($context, 'druga fraza');
		self::assertSame(['0', '0', 'inactive', 'accepted'], [$gap['active'], $gap['listed'], $gap['filter_reason'], $gap['status']], 'Bez aktualnych dowodów: nieaktualna, status pracy zostaje.');
		self::assertSame(['wspólna fraza'], array_column($this->gaps->keywords($context, GapFilters::fromInput(['status' => 'all']))['rows'], 'keyword'));

		$this->competitorRepository->update($drugi, $drugi->name, $drugi->domain, Competitor::ACTIVE, $context->userId());
		$this->gaps->recalculate($context);
		$gap = $this->gap($context, 'druga fraza');
		self::assertSame([$publicId, '1', 'accepted'], [$gap['public_id'], $gap['active'], $gap['status']]);

		self::assertSame(1, $this->gaps->setStatus($context, 'keyword', [$publicId], GapStatus::Dismissed));
		self::assertSame(['wspólna fraza'], array_column($this->gaps->keywords($context, GapFilters::fromInput([]))['rows'], 'keyword'), 'Odrzucone domyślnie ukryte.');
		self::assertSame(['druga fraza'], array_column($this->gaps->keywords($context, GapFilters::fromInput(['status' => 'dismissed']))['rows'], 'keyword'));
	}

	public function test_clusters_content_gap_classes_stable_ids_and_competitor_pages(): void
	{
		$context = $this->gapProject(['konkurent.pl' => 'Konkurent', 'drugi.pl' => 'Drugi']);
		$this->setRanked('konkurent.pl', [
			self::ranked('konkurent.pl', 'projektowanie stron internetowych', 1000, 3, '/strony-www/', 30, 'commercial', 2.5, null, false, 'Strony WWW'),
			self::ranked('konkurent.pl', 'strony internetowe cennik', 400, 5, '/strony-www/'),
			self::ranked('konkurent.pl', 'tworzenie stron www', 300, 6, '/strony-www/'),
			self::ranked('konkurent.pl', 'audyt seo', 500, 2, '/audyt-seo/'),
			self::ranked('konkurent.pl', 'audyt seo cena', 200, 3, '/audyt-seo/'),
		]);
		$this->setRanked('drugi.pl', [
			self::ranked('drugi.pl', 'projektowanie stron internetowych', 1000, 4, '/projektowanie-stron/'),
			self::ranked('drugi.pl', 'strony internetowe cennik', 400, 6, '/projektowanie-stron/'),
			self::ranked('drugi.pl', 'tworzenie stron www', 300, 8, '/projektowanie-stron/'),
		]);
		$this->setRanked('example.pl', [self::ranked('example.pl', 'agencja interaktywna', 300, 5, '/')]);
		$this->gscKeyword($context, 'audyt seo', 100, 25.0, 'https://example.pl/audyt/');
		$this->gscKeyword($context, 'audyt seo cena', 30, 30.0, 'https://example.pl/audyt/');

		$this->gapRun($context);

		$clusters = $this->gaps->clusters($context, null)['rows'];
		self::assertCount(2, $clusters);
		$byLabel = array_column($clusters, null, 'label');
		$web = $byLabel['projektowanie stron internetowych'];
		$audit = $byLabel['audyt seo'];
		self::assertSame(['3', '3', '1700', 'new_page', 'no_target', '2'], [$web['keywords_count'], $web['gap_keywords_count'], $web['gap_volume'], $web['content_gap'], $web['content_reason'], $web['competitor_pages']]);
		self::assertSame(['2', 'improve', 'target_gsc', 'high', 'https://example.pl/audyt/'], [$audit['keywords_count'], $audit['content_gap'], $audit['content_reason'], $audit['confidence'], $audit['target_url']]);
		self::assertSame('new_page', $this->gap($context, 'tworzenie stron www')['content_gap']);

		$detail = $this->gaps->cluster($context, (string) $web['public_id']);
		self::assertCount(3, $detail['keywords']);
		self::assertEqualsCanonicalizing(['https://konkurent.pl/strony-www/', 'https://drugi.pl/projektowanie-stron/'], array_column($detail['urls'], 'url'));

		// Praca nad grupą i stabilny identyfikator po przeliczeniu (także gdy jedna fraza wypadnie z listy).
		self::assertSame(1, $this->gaps->setStatus($context, 'cluster', [(string) $web['public_id']], GapStatus::Review));
		$this->gaps->saveSettings($context, ['min_volume' => '350']);
		$this->gaps->recalculate($context);
		$again = array_column($this->gaps->clusters($context, null)['rows'], null, 'public_id');
		self::assertArrayHasKey((string) $web['public_id'], $again);
		self::assertSame(['2', 'review'], [$again[(string) $web['public_id']]['keywords_count'], $again[(string) $web['public_id']]['status']]);
		self::assertCount(1, $this->gaps->clusters($context, 'new_page')['rows']);

		// Strony konkurencji.
		$konkurent = $this->competitor($context, 'konkurent.pl');
		$pages = array_column($this->gaps->pages($context, $konkurent->publicId)['rows'], null, 'url');
		self::assertSame(['3', '2', 'Strony WWW'], [$pages['https://konkurent.pl/strony-www/']['keywords'], $pages['https://konkurent.pl/strony-www/']['gap_keywords'], $pages['https://konkurent.pl/strony-www/']['title']]);
		self::assertSame(['2', '1'], [$pages['https://konkurent.pl/audyt-seo/']['keywords'], $pages['https://konkurent.pl/audyt-seo/']['gap_keywords']], 'Fraza poniżej minimalnego wolumenu (350) nie jest luką.');
		$page = $this->gaps->page($context, $konkurent->publicId, md5('https://konkurent.pl/strony-www/'));
		self::assertCount(3, $page['keywords']);
		self::assertSame(3, $this->gaps->counts($context)['pages']);
	}

	public function test_gap_keyword_goes_to_serp_tracking_with_the_same_market_keyword_and_no_request(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [self::ranked('konkurent.pl', 'sklep internetowy', 800, 1)]);
		$this->gapRun($context, ['baseline' => '0']);
		$gap = $this->gap($context, 'sklep internetowy');
		$requests = count($this->dataForSeoRequests());

		$this->serp->addKeywords($context, 'gap', [(string) $gap['public_id']]);

		$db = self::db();
		$tracked = $db->fetchRow("SELECT source, market_keyword_id FROM `{$db->table('serp_tracked_keywords')}` WHERE project_id = %d", [$context->projectId()]);
		self::assertSame(['gap', $gap['market_keyword_id']], [$tracked['source'], $tracked['market_keyword_id']]);
		self::assertCount($requests, $this->dataForSeoRequests(), 'Dodanie do monitorowania nie wysyła żądań.');
	}

	public function test_keyword_detail_links_page_level_opportunities_through_query_page_evidence(): void
	{
		// Regresja (STEP 16, D54): szanse SEO są zwykle per podstrona (`opportunities.keyword` = NULL), więc powiązanie wyłącznie
		// po `keyword IN (warianty)` pomijało je w szczegółach luki.
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [self::ranked('konkurent.pl', 'sklep internetowy', 800, 1)]);
		$this->gscKeyword($context, 'sklep internetowy', 120, 14.0, 'https://example.pl/sklep/', 2);
		$this->gscKeyword($context, 'Sklep Internetowy', 30, 16.0, 'https://example.pl/oferta/', 1);
		$this->gapRun($context, ['baseline' => '0']);
		$member = $this->opportunity($context, 'near_top', 'https://example.pl/sklep/', null, "https://example.pl/sklep/\nsklep internetowy\nsklep na wordpress", 70);
		$page = $this->opportunity($context, 'low_ctr', 'https://example.pl/oferta/', null, "https://example.pl/oferta/\noferta sklepu", 50);
		$keyword = $this->opportunity($context, 'weak_position', null, 'Sklep Internetowy', "Sklep Internetowy", 40);
		$this->opportunity($context, 'near_top', 'https://example.pl/inna/', null, "https://example.pl/inna/\nsklep internetowy wrocław", 90);
		$this->opportunity($context, 'decline', 'https://example.pl/sklep/', null, "https://example.pl/sklep/\nsklep internetowy", 95, 'inactive');

		$detail = $this->gaps->keyword($context, (string) $this->gap($context, 'sklep internetowy')['public_id']);
		$links = array_column($detail['evidence']['opportunities'], 'link', 'public_id');

		self::assertSame([$member => 'member', $page => 'page', $keyword => 'keyword'], $links, 'Członek grupy, ta sama podstrona i szansa frazy; bez innych fraz i szans nieaktywnych.');
		self::assertSame(['near_top', 'low_ctr', 'weak_position'], array_column($detail['evidence']['opportunities'], 'type'), 'Od najwyższego priorytetu.');
	}

	/**
	 * Szansa SEO z wykryciem 28 dni (bez analizy — bezpośredni zapis).
	 */
	private function opportunity(ProjectContext $context, string $type, ?string $page, ?string $keyword, string $searchText, int $priority, string $state = 'active'): string
	{
		$db = self::db();
		$now = gmdate('Y-m-d H:i:s');
		$publicId = \OsfSeo\Support\Ulid::generate();
		$id = $db->insert($db->table('opportunities'), array_filter([
			'public_id' => $publicId,
			'project_id' => $context->projectId(),
			'fingerprint' => md5($publicId, true),
			'type' => $type,
			'property' => 'sc-domain:example.pl',
			'page_url' => $page,
			'keyword' => $keyword,
			'state' => $state,
			'status' => 'new',
			'last_priority' => $priority,
			'first_detected_at' => $now,
			'last_detected_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		], static fn (mixed $value): bool => $value !== null));

		if ($page !== null) {
			$db->execute("UPDATE `{$db->table('opportunities')}` SET page_hash = UNHEX(%s) WHERE id = %d", [md5($page), $id]);
		}

		$db->insert($db->table('opportunity_detections'), [
			'opportunity_id' => $id,
			'period_days' => 28,
			'project_id' => $context->projectId(),
			'priority' => $priority,
			'confidence' => 2,
			'impressions' => 100,
			'clicks' => 1,
			'latest_date' => '2026-01-14',
			'search_text' => $searchText,
			'evidence' => '{}',
			'analyzed_at' => $now,
		]);

		return $publicId;
	}

	public function test_data_key_skips_unchanged_recalculation_and_refreshes_daily_or_after_change(): void
	{
		$context = $this->gapProject();
		$this->setRanked('konkurent.pl', [self::ranked('konkurent.pl', 'sklep internetowy', 800, 1)]);
		$this->gapRun($context, ['baseline' => '0']);

		self::assertTrue($this->refresher->refresh($context->projectId())['skipped'], 'Bez zmian danych — bez przeliczenia.');
		$this->gaps->saveSettings($context, ['competitor_max_rank' => '10']);
		self::assertFalse($this->refresher->refresh($context->projectId())['skipped']);
		self::assertTrue($this->refresher->refresh($context->projectId())['skipped']);
		$this->clock->advance(86400);
		self::assertFalse($this->refresher->refresh($context->projectId())['skipped'], 'Raz dziennie (metryki rynkowe).');

		// „Przelicz” w panelu: bez przeliczenia w żądaniu WWW — najbliższy krok tła przelicza projekt.
		$this->gaps->scheduleRecalculation($context);
		self::assertNull($this->gapSettings->get($context->projectId())->dataKey);
		add_filter('wp_doing_cron', '__return_true');
		self::assertArrayHasKey($context->projectId(), $this->gaps->runBackground(60.0)['refreshed']);
		self::assertTrue($this->refresher->refresh($context->projectId())['skipped']);
	}

	public function test_dataset_history_events_new_lost_back_url_and_rank_changes(): void
	{
		$context = $this->gapProject();
		$rows = [
			'jeden' => self::ranked('konkurent.pl', 'fraza jeden', 1000, 5, '/a/'),
			'dwa' => self::ranked('konkurent.pl', 'fraza dwa', 900, 8, '/b/'),
			'trzy' => self::ranked('konkurent.pl', 'fraza trzy', 800, 12, '/c/'),
			'cztery' => self::ranked('konkurent.pl', 'fraza cztery', 700, 3, '/d/'),
		];
		$this->setRanked('konkurent.pl', array_values($rows));
		$this->gapRun($context, ['baseline' => '0']);
		$dataset = $this->gapDomains->find($this->gaps->market($context), 'konkurent.pl');
		self::assertSame(0, self::tableCount('gap_domain_events'), 'Pierwszy import zbioru nie tworzy zdarzeń.');

		$this->clock->advance(31 * 86400);
		$this->setRanked('konkurent.pl', [
			self::ranked('konkurent.pl', 'fraza dwa', 900, 2, '/b/'),
			self::ranked('konkurent.pl', 'fraza trzy', 800, 12, '/c2/'),
			self::ranked('konkurent.pl', 'fraza cztery', 700, 3, '/d/'),
			self::ranked('konkurent.pl', 'fraza pięć', 600, 9, '/e/'),
		]);
		$second = $this->gapRun($context, ['baseline' => '0']);
		self::assertSame(['lost' => 1, 'new' => 1, 'up' => 1, 'url' => 1], self::sorted($this->gapDomains->eventCounts($dataset->id, $second->id)));
		self::assertSame('0', $this->datasetRow('konkurent.pl', 'fraza jeden')['present']);
		self::assertSame(['8', '2'], [$this->datasetRow('konkurent.pl', 'fraza dwa')['prev_rank'], $this->datasetRow('konkurent.pl', 'fraza dwa')['rank_group']]);
		self::assertSame('0', $this->gap($context, 'fraza jeden')['active'], 'Konkurent już nie rankuje — luka nieaktualna.');

		$this->clock->advance(31 * 86400);
		$this->setRanked('konkurent.pl', [...array_values($rows), self::ranked('konkurent.pl', 'fraza pięć', 600, 25, '/e/')]);
		$third = $this->gapRun($context, ['baseline' => '0']);
		self::assertSame(['back' => 1, 'down' => 2, 'url' => 1], self::sorted($this->gapDomains->eventCounts($dataset->id, $third->id)), 'Spadki: 2 → 8 i 9 → 25 (≥ 5 pozycji); powrót frazy; inny adres.');

		$events = $this->gaps->keyword($context, (string) $this->gap($context, 'fraza jeden')['public_id'])['events'];
		self::assertSame(['back', 'lost'], array_column($events, 'event'));
	}

	public function test_truncated_import_never_marks_lost_below_reliable_volume(): void
	{
		$context = $this->gapProject();
		$rows = array_map(static fn (int $i): array => self::ranked('konkurent.pl', sprintf('fraza %03d', $i), 10000 - 60 * $i, 5), range(0, 149));
		$this->setRanked('konkurent.pl', $rows);
		$this->gapRun($context, ['baseline' => '0']);

		$this->clock->advance(31 * 86400);
		unset($rows[20], $rows[70]);
		$this->setRanked('konkurent.pl', array_values($rows));
		$run = $this->gapRun($context, ['baseline' => '0', 'max_rows' => '100']);
		$dataset = $this->gapDomains->find($this->gaps->market($context), 'konkurent.pl');

		self::assertFalse($dataset->complete);
		self::assertSame(3941, $dataset->coveredMinVolume, 'Ostatnia pobrana fraza ma wolumen 3940 (dwie frazy wypadły, więc to setna od góry).');
		self::assertSame(5912, GapDomain::reliableVolume(3941));
		self::assertSame(['lost' => 1], $this->gapDomains->eventCounts($dataset->id, $run->id), 'Tylko fraza z wiarygodnego zakresu (z zapasem).');
		self::assertSame('0', $this->datasetRow('konkurent.pl', 'fraza 020')['present'], 'Wolumen 8800 ≥ 5912 — utracona.');
		self::assertSame('2', $this->datasetRow('konkurent.pl', 'fraza 070')['present'], 'Wolumen 5800 tuż nad granicą — niepotwierdzona, bez zdarzenia.');
		self::assertSame('1', $this->datasetRow('konkurent.pl', 'fraza 140')['present'], 'Poza zakresem przyciętego importu — bez zmian.');
	}

	public function test_records_of_another_project_are_not_found_and_client_is_read_only(): void
	{
		$first = $this->gapProject();
		$this->setRanked('konkurent.pl', [
			self::ranked('konkurent.pl', 'projektowanie stron internetowych', 1000, 3, '/strony-www/'),
			self::ranked('konkurent.pl', 'strony internetowe cennik', 400, 5, '/strony-www/'),
		]);
		$run = $this->gapRun($first, ['baseline' => '0']);
		$gapId = (string) $this->gap($first, 'projektowanie stron internetowych')['public_id'];
		$clusterId = (string) $this->gaps->clusters($first, null)['rows'][0]['public_id'];
		$competitor = $this->competitor($first, 'konkurent.pl');
		$second = $this->gapProject(['inny.pl' => 'Inny'], 'drugi-projekt.pl');

		foreach ([
			'keyword' => fn () => $this->gaps->keyword($second, $gapId),
			'cluster' => fn () => $this->gaps->cluster($second, $clusterId),
			'run' => fn () => $this->gaps->run($second, $run->publicId),
			'cancel' => fn () => $this->gaps->cancel($second, $run->publicId),
			'page' => fn () => $this->gaps->page($second, $competitor->publicId, md5('https://konkurent.pl/strony-www/')),
			'brand' => fn () => $this->gaps->saveBrandTerms($second, $competitor->publicId, 'x'),
		] as $label => $call) {
			try {
				$call();
				self::fail('Rekord innego projektu: ' . $label);
			} catch (GapNotFound) {
			}
		}

		self::assertSame(0, $this->gaps->setStatus($second, 'keyword', [$gapId], GapStatus::Dismissed));
		self::assertSame(0, $this->gaps->setStatus($second, 'cluster', [$clusterId], GapStatus::Dismissed));
		self::assertSame('new', $this->gap($first, 'projektowanie stron internetowych')['status']);
		self::assertSame(['rows' => [], 'total' => 0], $this->gaps->pages($second, $competitor->publicId));
		self::assertSame(0, $this->gaps->keywords($second, GapFilters::fromInput(['competitor' => $competitor->publicId]))['total']);
		self::assertNull($this->gaps->keywordId($second, 'projektowanie stron internetowych'));
		$this->serp->addKeywords($second, 'gap', [$gapId]);
		self::assertSame(0, self::tableCount('serp_tracked_keywords'), 'Luka innego projektu nie trafia do monitorowania.');

		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($first, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($first->publicId(), $client);
		$requests = count($this->dataForSeoRequests());

		self::assertSame(2, $this->gaps->keywords($clientContext, GapFilters::fromInput([]))['total']);
		self::assertSame($gapId, $this->gaps->keyword($clientContext, $gapId)['row']['public_id']);
		self::assertCount(1, $this->gaps->clusters($clientContext, null)['rows']);

		foreach ([
			'plan' => fn () => $this->gaps->plan($clientContext, $this->gaps->request($clientContext, [])),
			'start' => fn () => $this->gaps->start($clientContext, $this->gaps->request($clientContext, []), 'manual'),
			'execute' => fn () => $this->gaps->execute($clientContext, $run),
			'cancel' => fn () => $this->gaps->cancel($clientContext, $run->publicId),
			'recalculate' => fn () => $this->gaps->recalculate($clientContext),
			'setStatus' => fn () => $this->gaps->setStatus($clientContext, 'keyword', [$gapId], GapStatus::Dismissed),
			'saveSettings' => fn () => $this->gaps->saveSettings($clientContext, ['min_volume' => '1000']),
			'setSchedule' => fn () => $this->gaps->setSchedule($clientContext, true, true),
			'saveBrandTerms' => fn () => $this->gaps->saveBrandTerms($clientContext, $competitor->publicId, 'x'),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Klient nie może: ' . $operation);
			} catch (AccessDenied) {
			}
		}

		self::assertSame($requests, count($this->dataForSeoRequests()));
		self::assertSame('new', $this->gap($first, 'projektowanie stron internetowych')['status']);
		self::assertFalse($this->gaps->settings($first)->scheduleEnabled);

		$outsider = $this->createUser(Roles::CLIENT);
		$this->expectException(ProjectNotFound::class);
		$this->guard->authorize($first->publicId(), $outsider);
	}

	/**
	 * @param array<string, int> $values
	 * @return array<string, int>
	 */
	private static function sorted(array $values): array
	{
		ksort($values);

		return $values;
	}
}
