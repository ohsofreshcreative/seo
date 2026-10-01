<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Opportunities;

use OsfSeo\Analytics\KeywordReport;
use OsfSeo\Analytics\OverviewReport;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Gsc\Dictionary;
use OsfSeo\Opportunities\AnalysisResult;
use OsfSeo\Opportunities\Opportunity;
use OsfSeo\Opportunities\OpportunityAnalyzer;
use OsfSeo\Opportunities\OpportunityConfig;
use OsfSeo\Opportunities\OpportunityDataSource;
use OsfSeo\Opportunities\OpportunityFilters;
use OsfSeo\Opportunities\OpportunityRepository;
use OsfSeo\Opportunities\OpportunityService;
use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Tests\Integration\Gsc\GscTestCase;

/**
 * Dane GSC zapisywane bezpośrednio do tabel faktów. Okres 28 dni kończący się 2026-03-28:
 * bieżący 2026-03-01..2026-03-28, poprzedni 2026-02-01..2026-02-28 (pełne pokrycie od 2026-02-01).
 */
abstract class OpportunitiesTestCase extends GscTestCase
{
	protected const LATEST = '2026-03-28';

	protected const COVERAGE_START = '2026-02-01';

	protected OpportunityRepository $repository;

	protected OpportunityDataSource $data;

	protected OpportunityAnalyzer $analyzer;

	protected OpportunityService $opportunities;

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables('opportunities', 'opportunity_detections', 'opportunity_analyses');
		$db = self::db();
		$logger = $this->captureLogger();
		$keywords = new KeywordReport($db);
		$this->repository = new OpportunityRepository($db, $this->projects, $this->clock);
		$this->data = new OpportunityDataSource($db, $keywords, new OverviewReport($db, $keywords));
		$this->analyzer = new OpportunityAnalyzer($db, $this->data, $this->repository, $this->projects, new OpportunityConfig(), $logger);
		$this->opportunities = new OpportunityService($this->repository, $this->analyzer, $this->data, $logger);
		$this->properties->onDetach(fn (ProjectContext $context) => $this->repository->archiveProject($context->projectId()));
	}

	protected function tearDown(): void
	{
		$db = self::db();
		$db->execute("DELETE FROM `{$db->optionsTable()}` WHERE option_name LIKE %s", ['%osf_seo_opp%']);
		parent::tearDown();
	}

	/**
	 * Zestaw szans (28 dni) — po jednej grupie każdego typu:
	 * - /buty/: niski CTR („buty damskie”: 20 / 2000 na pozycji 2) i blisko TOP 3 („buty zimowe”: dwa dni 500 @ 4,0 + 1000 @ 5,2 → 4,8),
	 * - /sandaly/: spadek („sandały”: 50 → 20 kliknięć),
	 * - kanibalizacja „kozaki”: /kozaki/ 300 vs /buty/#kozaki 200 (fragment = /buty/),
	 * - „trampki”: /trampki/ 300 + /trampki/#opinie 200 — ten sam adres po usunięciu fragmentu (bez kanibalizacji).
	 */
	protected function seedScenario(ProjectContext $context): void
	{
		$this->seed($context, [
			['buty damskie', 'https://example.pl/buty/', '2026-02-10', 22, 2000, 2.0],
			['buty damskie', 'https://example.pl/buty/', '2026-03-10', 20, 2000, 2.0],
			['buty zimowe', 'https://example.pl/buty/', '2026-02-10', 28, 1400, 5.0],
			['buty zimowe', 'https://example.pl/buty/', '2026-03-05', 10, 500, 4.0],
			['buty zimowe', 'https://example.pl/buty/', '2026-03-12', 20, 1000, 5.2],
			['sandały', 'https://example.pl/sandaly/', '2026-02-11', 50, 1000, 3.0],
			['sandały', 'https://example.pl/sandaly/', '2026-03-11', 20, 900, 3.2],
			['kozaki', 'https://example.pl/kozaki/', '2026-02-12', 20, 400, 5.0],
			['kozaki', 'https://example.pl/kozaki/', '2026-03-12', 10, 300, 6.0],
			['kozaki', 'https://example.pl/buty/#kozaki', '2026-03-13', 8, 200, 9.0],
			['trampki', 'https://example.pl/trampki/', '2026-03-14', 40, 300, 1.5],
			['trampki', 'https://example.pl/trampki/#opinie', '2026-03-14', 25, 200, 1.6],
		]);
	}

	/**
	 * Zapis faktów: [fraza, adres, data, kliknięcia, wyświetlenia, pozycja] (ponowny zapis tego samego dnia zastępuje
	 * wiersze). `query_daily` = suma po adresach dnia (pozycja ważona), `query_page_daily` = wiersz na adres; sumy witryny i fraza-kotwica wyznaczają pokrycie
	 * zakresu 2026-02-01..2026-03-28 (kotwica ma 1 wyświetlenie — poniżej wszystkich progów).
	 *
	 * @param list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: float}> $rows
	 */
	protected function seed(ProjectContext $context, array $rows, string $start = self::COVERAGE_START, string $end = self::LATEST): void
	{
		$db = self::db();
		$projectId = $context->projectId();
		$rows[] = ['kotwica', 'https://example.pl/', $start, 0, 1, 50.0];
		$rows[] = ['kotwica', 'https://example.pl/', $end, 0, 1, 50.0];
		$dictionary = new Dictionary($db, $this->clock);
		$keywordIds = $dictionary->keywordIds($projectId, array_values(array_unique(array_column($rows, 0))));
		$pageIds = $dictionary->pageIds($projectId, array_values(array_unique(array_column($rows, 1))));
		$daily = [];

		foreach ($rows as [$keyword, $url, $date, $clicks, $impressions, $position]) {
			$db->execute(
				"INSERT INTO `{$db->table('gsc_query_page_daily')}` (project_id, date, keyword_id, page_id, clicks, impressions, position_sum) VALUES (%d, %s, %d, %d, %d, %d, %f)
				ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), position_sum = VALUES(position_sum)",
				[$projectId, $date, $keywordIds[$keyword], $pageIds[$url], $clicks, $impressions, $position * $impressions],
			);
			$key = $keyword . "\n" . $date;
			$daily[$key] ??= [$keywordIds[$keyword], $date, 0, 0, 0.0];
			$daily[$key][2] += $clicks;
			$daily[$key][3] += $impressions;
			$daily[$key][4] += $position * $impressions;
		}

		foreach ($daily as [$keywordId, $date, $clicks, $impressions, $positionSum]) {
			$db->execute(
				"INSERT INTO `{$db->table('gsc_query_daily')}` (project_id, date, keyword_id, clicks, impressions, position_sum) VALUES (%d, %s, %d, %d, %d, %f)
				ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), position_sum = VALUES(position_sum)",
				[$projectId, $date, $keywordId, $clicks, $impressions, $positionSum],
			);
		}

		for ($date = $start; $date <= $end; $date = \OsfSeo\Support\DateRange::shift($date, 1)) {
			$db->execute(
				"INSERT IGNORE INTO `{$db->table('gsc_site_daily')}` (project_id, date, device, clicks, impressions, position_sum) VALUES (%d, %s, 0, 100, 10000, 80000)",
				[$projectId, $date],
			);
		}
	}

	/** Usuwa fakty jednej frazy w zakresie dat (np. zniknięcie sygnału). */
	protected function deleteKeywordFacts(ProjectContext $context, string $keyword, string $from, string $to): void
	{
		$db = self::db();
		$id = (int) $db->fetchValue("SELECT id FROM `{$db->table('keywords')}` WHERE project_id = %d AND keyword_hash = UNHEX(%s)", [$context->projectId(), Dictionary::hash($keyword)]);

		foreach (['gsc_query_daily', 'gsc_query_page_daily'] as $table) {
			$db->execute("DELETE FROM `{$db->table($table)}` WHERE project_id = %d AND keyword_id = %d AND date BETWEEN %s AND %s", [$context->projectId(), $id, $from, $to]);
		}
	}

	/**
	 * @return list<AnalysisResult>
	 */
	protected function analyze(ProjectContext $context, bool $force = false, ?array $periods = null): array
	{
		return $this->analyzer->analyzeAll($context, OpportunityAnalyzer::TRIGGER_CLI, $force, $periods);
	}

	/**
	 * @return list<Opportunity>
	 */
	protected function listed(ProjectContext $context, array $filters = []): array
	{
		return $this->opportunities->list($context, OpportunityFilters::fromInput($filters + ['status' => 'all']))->rows;
	}

	protected function findByType(ProjectContext $context, OpportunityType $type, array $filters = []): ?Opportunity
	{
		foreach ($this->listed($context, $filters) as $opportunity) {
			if ($opportunity->type === $type) {
				return $opportunity;
			}
		}

		return null;
	}

	protected static function rows(string $table, ?int $projectId = null): int
	{
		$db = self::db();

		return $projectId === null
			? (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}`")
			: (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}` WHERE project_id = %d", [$projectId]);
	}

	protected static function canonical(): int
	{
		return OpportunityConfig::CANONICAL_DAYS;
	}
}
