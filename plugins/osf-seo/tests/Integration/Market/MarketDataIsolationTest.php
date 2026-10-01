<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Market;

use OsfSeo\Analytics\KeywordFilters;
use OsfSeo\Analytics\KeywordReport;
use OsfSeo\Analytics\KeywordRow;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\DataForSeo\DataForSeoProvider;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Market\MarketSyncResult;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Izolacja projektów i rynków, reset property, raport fraz z danymi rynkowymi i bez nich.
 */
final class MarketDataIsolationTest extends MarketTestCase
{
	public function test_sync_selects_only_keywords_of_its_project(): void
	{
		$a = $this->projectWithKeywords('example.pl', [['buty damskie', 500, 10]]);
		$b = $this->projectWithKeywords('sklep.pl', [['kurtki zimowe', 900, 30]]);
		$this->mockVolumePost(DataForSeoFakes::taskId());
		$this->mockDifficulty(['buty damskie' => 20]);

		$this->market->sync($a, MarketSyncService::TRIGGER_CLI);

		foreach ($this->dataForSeoRequests() as $request) {
			self::assertSame(['buty damskie'], self::requestKeywords($request));
		}

		self::assertSame(2, $this->tasks->usage('2026-01-01 00:00:00', $a->projectId())['tasks']);
		self::assertSame(0, $this->tasks->usage('2026-01-01 00:00:00', $b->projectId())['tasks']);
		self::assertSame(1, $this->market->status($a)['pending_tasks']);
		self::assertSame(0, $this->market->status($b)['pending_tasks']);
		self::assertNull($this->states->get($b->projectId(), 'dataforseo')['enabled_at'], 'Włączenie automatyki dotyczy tylko synchronizowanego projektu.');
		self::assertNull(self::row($this->report->keywords($b, new KeywordFilters(days: 7)), 'kurtki zimowe')->market);
	}

	public function test_metrics_are_market_specific_and_never_mixed(): void
	{
		$pl = $this->projectWithKeywords('example.pl', [['buty damskie', 500, 10]]);
		$de = $this->projectWithKeywords('example.de', [['buty damskie', 300, 3]], 'de', 'de');
		$this->storeMetrics($pl, ['buty damskie' => [2400, 35]]);

		self::assertSame(2400, self::row($this->report->keywords($pl, new KeywordFilters(days: 7)), 'buty damskie')->market?->searchVolume);
		self::assertNull(self::row($this->report->keywords($de, new KeywordFilters(days: 7)), 'buty damskie')->market, 'Dane rynku PL nie trafiają do projektu DE.');

		$this->mockVolumePost(DataForSeoFakes::taskId());
		$this->mockDifficulty(['buty damskie' => 12]);
		$this->market->sync($de, MarketSyncService::TRIGGER_CLI);

		$body = json_decode($this->dataForSeoRequests()[0]['body'], true)[0];
		self::assertSame([2276, 'de'], [$body['location_code'], $body['language_code']]);
		self::assertSame('35', $this->marketRow('buty damskie')['keyword_difficulty'], 'Rynek PL bez zmian.');
		self::assertSame('12', $this->marketRow('buty damskie', 2276, 'de')['keyword_difficulty']);
		self::assertSame(2, self::tableCount('market_keywords'));
	}

	public function test_unsupported_market_is_not_guessed(): void
	{
		$fr = $this->projectWithKeywords('example.fr', [['chaussures', 500, 10]], 'fr', 'fr');

		self::assertSame('unsupported_market', $this->market->plan($fr)->skipReason);
		self::assertSame(MarketSyncResult::SKIPPED, $this->market->sync($fr, MarketSyncService::TRIGGER_CLI)->status);
		self::assertSame([], $this->dataForSeoRequests());
		self::assertNull($this->report->keywords($fr, new KeywordFilters(days: 7))->market);
	}

	public function test_property_reset_keeps_reusable_market_metrics(): void
	{
		$context = $this->projectWithKeywords('example.pl', [['buty damskie', 500, 10], ['żółte buty', 200, 4]]);
		$this->storeMetrics($context, ['buty damskie' => [2400, 35], 'żółte buty' => [320, 12]]);
		$marketRows = self::tableCount('market_keywords');
		$monthlyRows = self::tableCount('market_keyword_monthly');

		// Reset danych GSC przy zmianie property (D18): słownik fraz i fakty projektu są usuwane.
		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);
		$context = $this->properties->select($context, 'https://www.example.pl/', true);

		self::assertSame(0, self::rowCount('keywords', $context->projectId()));
		self::assertSame($marketRows, self::tableCount('market_keywords'), 'Dane rynkowe nie należą do danych GSC projektu.');
		self::assertSame($monthlyRows, self::tableCount('market_keyword_monthly'));

		// Nowy import: nowe identyfikatory fraz, klucz rynkowy w tle — metryki wracają bez żadnego płatnego żądania.
		$this->seedKeywords($context, [['Buty Damskie', 450, 9]]);
		(new MarketKeyBackfill(self::db()))->fillProject($context->projectId());

		self::assertSame(2400, self::row($this->report->keywords($context, new KeywordFilters(days: 7)), 'Buty Damskie')->market?->searchVolume);
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_keywords_report_works_without_market_data_and_without_provider(): void
	{
		$context = $this->projectWithKeywords();
		(new MarketKeyBackfill(self::db()))->fillProject($context->projectId());

		$page = $this->report->keywords($context, new KeywordFilters(days: 7));
		self::assertSame('Polska / polski', $page->market?->label());
		self::assertCount(7, $page->rows);

		foreach ($page->rows as $row) {
			self::assertNull($row->market, 'Brak danych rynkowych = null (UI: „—”), nie 0.');
		}

		$plain = (new KeywordReport(self::db()))->keywords($context, new KeywordFilters(days: 7));
		self::assertNull($plain->market);
		self::assertSame(array_map(static fn (KeywordRow $r): string => $r->keyword, $page->rows), array_map(static fn (KeywordRow $r): string => $r->keyword, $plain->rows), 'Dane rynkowe nie zmieniają listy ani kolejności fraz GSC.');
		self::assertSame(0, (new KeywordReport(self::db()))->keywords($context, new KeywordFilters(days: 7, minVolume: 1))->total, 'Filtr rynkowy bez danych — nic go nie spełnia.');
	}

	public function test_keywords_report_joins_market_metrics_with_sorting_and_filters(): void
	{
		$context = $this->projectWithKeywords();
		$this->storeMetrics($context, [
			'buty damskie' => [2400, 35],
			'żółte buty' => [null, 70],
			'kurs c++' => [590, null],
			'sklep internetowy' => [0, 5],
		]);

		$page = $this->report->keywords($context, new KeywordFilters(days: 7));
		$upper = self::row($page, 'Buty Damskie');
		$lower = self::row($page, 'buty damskie');
		self::assertSame([2400, 35], [$upper->market?->searchVolume, $upper->market?->keywordDifficulty]);
		self::assertSame($upper->market?->id, $lower->market?->id, 'Warianty wielkości liter = jedna fraza rynkowa.');
		self::assertSame(0, self::row($page, 'sklep internetowy')->market?->searchVolume, 'Prawdziwe 0 od dostawcy pozostaje 0.');
		self::assertNull(self::row($page, 'żółte buty')->market?->searchVolume, 'Brak danych u dostawcy = null.');
		self::assertNotNull(self::row($page, 'żółte buty')->market?->volumeFetchedAt);
		self::assertNull(self::row($page, 'rzadka fraza')->market);
		self::assertSame(['2025-12-01', '2026-01-01'], array_column($upper->market?->monthly ?? [], 'month'), 'Historia dołączona dla bieżącej strony.');
		// Metryki GSC bez zmian.
		self::assertSame([500, 40], [$upper->impressions, $upper->clicks]);

		$byVolume = $this->report->keywords($context, KeywordFilters::fromInput(['days' => 7, 'sort' => 'volume']));
		self::assertSame(['Buty Damskie', 'buty damskie', 'kurs c++', 'sklep internetowy'], array_slice(array_map(static fn (KeywordRow $r): string => $r->keyword, $byVolume->rows), 0, 4));
		self::assertNull($byVolume->rows[count($byVolume->rows) - 1]->market?->searchVolume, 'Brak wartości zawsze na końcu.');

		$byDifficulty = $this->report->keywords($context, KeywordFilters::fromInput(['days' => 7, 'sort' => 'difficulty']));
		self::assertSame('asc', $byDifficulty->filters->direction);
		self::assertSame('sklep internetowy', $byDifficulty->rows[0]->keyword);

		$filtered = $this->report->keywords($context, KeywordFilters::fromInput(['days' => 7, 'min_volume' => '500', 'max_kd' => '40']));
		self::assertSame(['Buty Damskie', 'buty damskie'], array_map(static fn (KeywordRow $r): string => $r->keyword, $filtered->rows));
		self::assertSame(2, $filtered->total);
		self::assertSame(['min_volume' => 500, 'max_kd' => 40], array_intersect_key($filtered->filters->toQuery(), ['min_volume' => 1, 'max_kd' => 1]));
		self::assertSame(['enriched' => 5, 'with_volume' => 4, 'with_difficulty' => 4], $this->market->status($context)['project_metrics'], 'Liczone po frazach projektu (warianty wielkości liter osobno).');
	}

	/**
	 * @param array<string, array{0: ?int, 1: ?int}> $metrics fraza → [wolumen, trudność]
	 */
	private function storeMetrics(ProjectContext $context, array $metrics): void
	{
		$market = $this->market->market($context);
		$volumes = [];
		$difficulty = [];

		foreach ($metrics as $keyword => [$volume, $kd]) {
			$items = DataForSeoProvider::parseVolume([DataForSeoFakes::volumeItem($keyword, $volume, $volume === null ? null : 1.5, $volume === null ? null : 'LOW', $volume === null ? null : 10, $volume === null ? null : [
				['year' => 2026, 'month' => 1, 'search_volume' => $volume],
				['year' => 2025, 'month' => 12, 'search_volume' => $volume],
			])]);
			$volumes[$keyword] = $items[0];
			$difficulty[$keyword] = $kd;
		}

		$this->marketMetrics->storeVolume($market, array_keys($metrics), $volumes, 1, 30);
		$this->marketMetrics->storeDifficulty($market, array_keys($metrics), $difficulty, 2, 30);
		(new MarketKeyBackfill(self::db()))->fillProject($context->projectId());
	}

	private static function row(\OsfSeo\Analytics\KeywordPage $page, string $keyword): KeywordRow
	{
		foreach ($page->rows as $row) {
			if ($row->keyword === $keyword) {
				return $row;
			}
		}

		self::fail('Brak frazy ' . $keyword);
	}
}
