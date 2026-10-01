<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Analytics;

use OsfSeo\Analytics\KeywordReport;
use OsfSeo\Analytics\OverviewReport;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Gsc\Dictionary;
use OsfSeo\Tests\Integration\Gsc\GscTestCase;

/**
 * Dane wyliczone ręcznie (okres 7 dni): bieżący 2026-01-08..2026-01-14, poprzedni 2026-01-01..2026-01-07.
 *
 * | fraza   | poprzedni okres (wiersze: data, kliknięcia, wyświetlenia, pozycja) | bieżący okres                          | pozycja poprz → obecna | zmiana |
 * |---------|-------------------------------------------------------------------|----------------------------------------|------------------------|--------|
 * | alfa    | 01-03: 10 / 100 / 15                                              | 01-10: 20 / 100 / 7                    | 15 → 7                 | +8     |
 * | beta    | 01-02: 5 / 50 / 4                                                 | 01-09: 1 / 50 / 9                      | 4 → 9                  | −5     |
 * | gamma   | —                                                                 | 01-12: 3 / 30 / 2,5                    | — → 2,5 (nowa)         | —      |
 * | delta   | 01-04: 2 / 40 / 3                                                 | —                                      | utracona               | —      |
 * | epsilon | 01-05: 0 / 2 / 50                                                 | 01-11: 0 / 3 / 20                      | 50 → 20 (< progu)      | +30    |
 * | zeta    | 01-06: 0 / 10 / 60                                                | 01-08: 1 / 1 / 1 + 01-13: 0 / 99 / 50  | 60 → 49,51 (ważona)    | +10,49 |
 * | eta     | —                                                                 | 01-14: 0 / 10 / 3                      | 3                      | —      |
 * | theta   | —                                                                 | 01-14: 0 / 10 / 10                     | 10                     | —      |
 * | iota    | —                                                                 | 01-14: 0 / 10 / 100                    | 100                    | —      |
 * | kappa   | —                                                                 | 01-14: 0 / 10 / 100,5                  | 100,5                  | —      |
 * | lambda  | —                                                                 | 01-08: 0 / 1 / 2 + 01-09: 0 / 39 / 30  | 29,3 (AVG dałoby 16)   | —      |
 */
abstract class AnalyticsTestCase extends GscTestCase
{
	protected KeywordReport $keywords;

	protected OverviewReport $overview;

	/** @var array<string, int> fraza → id */
	protected array $ids = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->keywords = new KeywordReport(self::db());
		$this->overview = new OverviewReport(self::db(), $this->keywords);
	}

	protected function seedFixture(ProjectContext $context): void
	{
		$rows = [
			'alfa' => [['2026-01-03', 10, 100, 15.0], ['2026-01-10', 20, 100, 7.0]],
			'beta' => [['2026-01-02', 5, 50, 4.0], ['2026-01-09', 1, 50, 9.0]],
			'gamma' => [['2026-01-12', 3, 30, 2.5]],
			'delta' => [['2026-01-04', 2, 40, 3.0]],
			'epsilon' => [['2026-01-05', 0, 2, 50.0], ['2026-01-11', 0, 3, 20.0]],
			'zeta' => [['2026-01-06', 0, 10, 60.0], ['2026-01-08', 1, 1, 1.0], ['2026-01-13', 0, 99, 50.0]],
			'eta' => [['2026-01-14', 0, 10, 3.0]],
			'theta' => [['2026-01-14', 0, 10, 10.0]],
			'iota' => [['2026-01-14', 0, 10, 100.0]],
			'kappa' => [['2026-01-14', 0, 10, 100.5]],
			'lambda' => [['2026-01-08', 0, 1, 2.0], ['2026-01-09', 0, 39, 30.0]],
		];
		$db = self::db();
		$this->ids = (new Dictionary($db, $this->clock))->keywordIds($context->projectId(), array_keys($rows));

		foreach ($rows as $keyword => $facts) {
			foreach ($facts as [$date, $clicks, $impressions, $position]) {
				$db->insert($db->table('gsc_query_daily'), [
					'project_id' => $context->projectId(),
					'date' => $date,
					'keyword_id' => $this->ids[$keyword],
					'clicks' => $clicks,
					'impressions' => $impressions,
					'position_sum' => $position * $impressions,
				]);
			}
		}

		// Sumy witryny: każdy dzień 2026-01-01..2026-01-14 poza 2026-01-11 (brak danych → 0 w serii).
		for ($day = 1; $day <= 14; $day++) {
			if ($day === 11) {
				continue;
			}

			$db->insert($db->table('gsc_site_daily'), [
				'project_id' => $context->projectId(),
				'date' => sprintf('2026-01-%02d', $day),
				'device' => 0,
				'clicks' => $day <= 7 ? 10 : 20,
				'impressions' => 1000,
				'position_sum' => ($day <= 7 ? 12.0 : 8.0) * 1000,
			]);
		}

		// Strony fraz: dla „alfa” w bieżącym okresie wygrywa /buty/ (więcej kliknięć); w poprzednim — /stara/ (ignorowana).
		$pages = (new Dictionary($db, $this->clock))->pageIds($context->projectId(), ['https://example.pl/buty/', 'https://example.pl/obuwie/', 'https://example.pl/stara/']);
		$pageRows = [
			['2026-01-10', 'alfa', 'https://example.pl/buty/', 15, 60],
			['2026-01-10', 'alfa', 'https://example.pl/obuwie/', 5, 80],
			['2026-01-03', 'alfa', 'https://example.pl/stara/', 50, 500],
			['2026-01-09', 'beta', 'https://example.pl/obuwie/', 1, 50],
		];

		foreach ($pageRows as [$date, $keyword, $url, $clicks, $impressions]) {
			$db->insert($db->table('gsc_query_page_daily'), [
				'project_id' => $context->projectId(),
				'date' => $date,
				'keyword_id' => $this->ids[$keyword],
				'page_id' => $pages[$url],
				'clicks' => $clicks,
				'impressions' => $impressions,
				'position_sum' => 5.0 * $impressions,
			]);
		}
	}
}
