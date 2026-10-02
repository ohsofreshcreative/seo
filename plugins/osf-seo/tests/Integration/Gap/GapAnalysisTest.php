<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Gap;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Auth\Roles;
use OsfSeo\Discovery\ExclusionList;
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
			'website design' => ['0', 'foreign_language'],
			'tanie strony' => ['0', 'low_volume'],
			'trudna fraza seo' => ['0', 'high_difficulty'],
			'strony internetowe' => ['1', null],
			'konkurent' => ['1', null],
		], $reasons, 'Nazwa z domeny bez intencji nawigacyjnej nie ukrywa frazy; wiersze odfiltrowane zostają z powodem.');
		self::assertEqualsCanonicalizing(['strony internetowe', 'konkurent'], array_column($this->gaps->keywords($context, GapFilters::fromInput([]))['rows'], 'keyword'));
		self::assertCount(7, $this->gaps->keywords($context, GapFilters::fromInput(['filtered' => '1']))['rows']);
		self::assertSame(['brand_competitor' => 2, 'brand_own' => 1, 'excluded' => 1, 'foreign_language' => 1, 'high_difficulty' => 1, 'low_volume' => 1], self::sorted($this->gaps->counts($context)['filter_reasons']));

		$this->gaps->saveSettings($context, ['include_terms' => 'strony']);
		$this->gaps->recalculate($context);
		self::assertSame(['0', 'not_included'], [$this->gap($context, 'konkurent')['listed'], $this->gap($context, 'konkurent')['filter_reason']], 'Słowa tematyczne: tylko frazy z nimi.');
		self::assertSame('1', $this->gap($context, 'strony internetowe')['listed']);
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
		$rows = array_map(static fn (int $i): array => self::ranked('konkurent.pl', sprintf('fraza %03d', $i), 1000 - $i, 5), range(0, 149));
		$this->setRanked('konkurent.pl', $rows);
		$this->gapRun($context, ['baseline' => '0']);

		$this->clock->advance(31 * 86400);
		unset($rows[20]);
		$this->setRanked('konkurent.pl', array_values($rows));
		$run = $this->gapRun($context, ['baseline' => '0', 'max_rows' => '100']);
		$dataset = $this->gapDomains->find($this->gaps->market($context), 'konkurent.pl');

		self::assertFalse($dataset->complete);
		self::assertSame(901, $dataset->coveredMinVolume, 'Ostatnia pobrana fraza ma wolumen 900 (jedna fraza wypadła, więc to setna od góry).');
		self::assertSame(['lost' => 1], $this->gapDomains->eventCounts($dataset->id, $run->id), 'Tylko fraza z wiarygodnego zakresu.');
		self::assertSame('0', $this->datasetRow('konkurent.pl', 'fraza 020')['present']);
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
