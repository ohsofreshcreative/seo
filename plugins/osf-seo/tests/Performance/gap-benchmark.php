<?php

/**
 * Benchmark Luk SEO (STEP 15) na syntetycznych danych — bez żadnego żądania do DataForSEO:
 *
 * - D wspólnych zbiorów domen konkurentów po R fraz (TOP30) i punkty odniesienia projektów (TOP100),
 * - P projektów po C konkurentów (zbiory współdzielone między projektami), pierwszy projekt z danymi GSC
 *   (okno 90 dni, strony) i monitorowanymi frazami SERP,
 * - pełne przeliczenie luk projektu (`GapRefresher`: widoczność, typ, priorytet, filtry, grupy, luka treści, strony),
 * - import 10 000 fraz nowej domeny prawdziwą ścieżką (`GapService::start` + `execute`: 10 stron po 1 000 fraz) z atrapą
 *   HTTP (filtr `pre_http_request`, synthetic credentials) i ponowny import ze zmianami (zdarzenia new/lost/up/down/url),
 * - czasy odczytów panelu, EXPLAIN ich zapytań, liczby wierszy i rozmiary tabel.
 *
 * Działa na OSOBNEJ bazie testowej (zmienne OSF_SEO_TEST_DB_* jak testy integracyjne) — czyści tabele Luk SEO, projektów,
 * fraz i słowników SERP. Nigdy nie wskazuj bazy strony.
 *
 *   composer test:performance:gap
 *   php tests/Performance/gap-benchmark.php --projects=20 --competitors=5 --domains=40 --rows=10000 --pool=60000 [--keep]
 */

declare(strict_types=1);

use OsfSeo\Database\Connection;
use OsfSeo\Database\Migrator;
use OsfSeo\Gap\GapFilters;
use OsfSeo\Gap\GapRefresher;
use OsfSeo\Gap\GapService;
use OsfSeo\Gap\GapStartResult;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Ulid;

$options = getopt('', ['projects::', 'competitors::', 'domains::', 'rows::', 'pool::', 'baseline::', 'gsc::', 'urls::', 'keep']);
$projectCount = max(2, (int) ($options['projects'] ?? 20));
$competitorsPerProject = max(1, min(20, (int) ($options['competitors'] ?? 5)));
$domainCount = max($competitorsPerProject + 1, (int) ($options['domains'] ?? 40));
$rowsPerDomain = max(1000, min(10000, (int) ($options['rows'] ?? 10000)));
$keywordPool = max($rowsPerDomain * 2, (int) ($options['pool'] ?? 60000));
$baselineRows = max(100, min(10000, (int) ($options['baseline'] ?? 2000)));
$gscKeywords = max(100, (int) ($options['gsc'] ?? 5000));
$urlsPerDomain = max(10, min(1000, (int) ($options['urls'] ?? 300)));

require dirname(__DIR__, 2) . '/vendor/autoload.php';

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Integration/install.php'), $code);

if ($code !== 0) {
	fwrite(STDERR, "WordPress test installation failed. Check OSF_SEO_TEST_DB_* variables.\n");
	exit(1);
}

// Syntetyczne dane logowania tylko w tym procesie; każde żądanie obsługuje atrapa poniżej (nic nie wychodzi do sieci).
putenv('OSF_SEO_DATAFORSEO_LOGIN=benchmark-' . bin2hex(random_bytes(4)) . '@example.test');
putenv('OSF_SEO_DATAFORSEO_PASSWORD=' . bin2hex(random_bytes(12)));
// Limity kosztów podniesione tylko w tym procesie, żeby zmierzyć pełny import (koszt jest syntetyczny).
putenv('OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT=10000');
putenv('OSF_SEO_DATAFORSEO_MONTHLY_COST_LIMIT=10000');

require dirname(__DIR__) . '/Integration/wp-tests-config.php';
define('WP_CLI', true); // kontekst systemowy projektu (ProjectGuard::authorizeSystem)
require ABSPATH . 'wp-settings.php';
require dirname(__DIR__, 2) . '/osf-seo.php';

$db = Connection::fromGlobals();
(new Migrator($db, new Logger(Logger::ERROR, static function (): void {
})))->migrate();

$out = static function (string $line = ''): void {
	fwrite(STDOUT, $line . "\n");
};
$timer = static function (callable $callback, int $runs = 3): array {
	$times = [];
	$result = null;

	for ($i = 0; $i < $runs; $i++) {
		$start = microtime(true);
		$result = $callback();
		$times[] = (microtime(true) - $start) * 1000;
	}

	sort($times);

	return [$times[intdiv(count($times), 2)], $result];
};
$t = static fn (string $name): string => $db->table($name);

const OSF_SEO_GAP_BENCHMARK_TABLES = [
	'projects', 'keywords', 'pages', 'gsc_query_daily', 'gsc_query_page_daily', 'market_keywords', 'market_keyword_monthly', 'market_tasks',
	'serp_competitors', 'serp_tracked_keywords', 'serp_domains', 'serp_urls', 'serp_snippets', 'serp_results', 'serp_snapshots',
	'gap_domains', 'gap_domain_keywords', 'gap_domain_pages', 'gap_domain_events', 'gap_runs', 'gap_run_targets', 'gap_settings',
	'gap_keywords', 'gap_clusters', 'gap_competitor_pages', 'discovery_settings', 'discovery_candidates', 'opportunities',
];

foreach (OSF_SEO_GAP_BENCHMARK_TABLES as $table) {
	$db->execute("TRUNCATE TABLE `{$db->table($table)}`");
}

$wpdb = $GLOBALS['wpdb'];
$version = $db->fetchValue('SELECT VERSION()');
$now = gmdate('Y-m-d H:i:s');
$today = gmdate('Y-m-d');
$stale = gmdate('Y-m-d H:i:s', strtotime('+30 days'));

$out("# Whack-a-mole — benchmark Luk SEO ({$version})");
$out();
$out(sprintf(
	'Dane: %d zbiorów domen konkurentów × %s fraz (TOP30) z puli %s fraz rynkowych, %d projektów × %d konkurentów (zbiory współdzielone), punkt odniesienia %s fraz na projekt (TOP100), projekt nr 1: GSC %s fraz × 30 dni w oknie 90 dni + strony, 500 monitorowanych fraz SERP; %d adresów na domenę.',
	$domainCount,
	number_format($rowsPerDomain),
	number_format($keywordPool),
	$projectCount,
	$competitorsPerProject,
	number_format($baselineRows),
	number_format($gscKeywords),
	$urlsPerDomain,
));
$out();

$phase = static function (string $label, callable $callback) use ($out): mixed {
	$start = microtime(true);
	$result = $callback();
	$out(sprintf('- %s: %.1f s', $label, microtime(true) - $start));

	return $result;
};

$out('## Generowanie danych');
$out();

$db->execute('DROP TEMPORARY TABLE IF EXISTS osf_bench_seq');
$db->execute('CREATE TEMPORARY TABLE osf_bench_seq (n SMALLINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$db->execute('INSERT INTO osf_bench_seq (n) VALUES ' . implode(',', array_map(static fn (int $n): string => '(' . $n . ')', range(1, 1000))));
$numbers = static fn (int $limit): string => "(SELECT (a.n - 1) * 1000 + b.n AS k FROM osf_bench_seq a JOIN osf_bench_seq b WHERE (a.n - 1) * 1000 + b.n <= {$limit})";

// Frazy rynkowe: wolumen skośny (dużo małych), KD, CPC, intencja, temat (core) co 25 fraz, ok. 2% w innym języku.
$phase(sprintf('frazy rynkowe (%s)', number_format($keywordPool)), static function () use ($db, $t, $keywordPool, $numbers, $now): void {
	$db->execute(
		"INSERT INTO `{$t('market_keywords')}` (id, provider, location_code, language_code, keyword_key, keyword, search_volume, cpc, competition_level, competition_index,
			keyword_difficulty, search_intent, core_key, other_language, volume_fetched_at, volume_stale_after, difficulty_fetched_at, difficulty_stale_after, intent_fetched_at, created_at, updated_at)
		SELECT x.k, 'dataforseo', 2616, 'pl', UNHEX(MD5(x.kw)), x.kw,
			10 + FLOOR(POW((CRC32(CONCAT('v', x.k)) %% 1000) / 1000, 4) * 40000), (CRC32(CONCAT('c', x.k)) %% 1500) / 100, 'MEDIUM', 40,
			CRC32(CONCAT('d', x.k)) %% 100, ELT(1 + CRC32(CONCAT('i', x.k)) %% 4, 'informational', 'commercial', 'transactional', 'navigational'),
			UNHEX(MD5(CONCAT('temat ', x.k DIV 25))), IF(CRC32(CONCAT('l', x.k)) %% 50 = 0, 1, 0), %s, DATE_ADD(%s, INTERVAL 30 DAY), %s, DATE_ADD(%s, INTERVAL 30 DAY), %s, %s, %s
		FROM (SELECT k, CONCAT('fraza luki ', k DIV 25, ' wariant ', k %% 25) AS kw FROM {$numbers($keywordPool)} n) x ORDER BY x.k",
		[$now, $now, $now, $now, $now, $now, $now],
	);
});

// Domeny: D konkurentów + P projektów (słownik SERP i zbiory Luk SEO).
$domains = [];

for ($d = 1; $d <= $domainCount; $d++) {
	$domains[$d] = 'konkurent-' . $d . '.example';
}

for ($p = 1; $p <= $projectCount; $p++) {
	$domains[$domainCount + $p] = 'projekt-' . $p . '.example';
}

$phase(sprintf('domeny, adresy i tytuły (%d domen × %d adresów)', count($domains), $urlsPerDomain), static function () use ($db, $t, $domains, $domainCount, $urlsPerDomain, $now, $stale, $rowsPerDomain, $baselineRows): void {
	foreach ($domains as $d => $host) {
		$db->execute("INSERT INTO `{$t('serp_domains')}` (id, host_hash, host, host_rev, created_at) VALUES (%d, UNHEX(%s), %s, %s, %s)", [$d, md5($host), $host, implode('.', array_reverse(explode('.', $host))), $now]);
		$isProject = $d > $domainCount;
		$db->insert($t('gap_domains'), [
			'id' => $d,
			'provider' => 'dataforseo',
			'location_code' => 2616,
			'language_code' => 'pl',
			'domain' => $host,
			'domain_key' => md5($host, true),
			'status' => 'ready',
			'coverage_max_rank' => $isProject ? 100 : 30,
			'coverage_min_volume' => 10,
			'coverage_max_rows' => 10000,
			'complete' => 1,
			'covered_min_volume' => 10,
			'total_count' => $isProject ? $baselineRows : $rowsPerDomain,
			'rows_present' => $isProject ? $baselineRows : $rowsPerDomain,
			'labs_updated_at' => $now,
			'imported_at' => $now,
			'stale_after' => $stale,
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	// Adres 1 = strona główna, pozostałe = podstrony tematów; jawne ID = (domena − 1) × U + n.
	$db->execute(
		"INSERT INTO `{$t('serp_urls')}` (id, url_hash, domain_id, url, created_at)
		SELECT x.id, UNHEX(MD5(x.url)), x.domain_id, x.url, %s FROM (
			SELECT (d.id - 1) * %d + q.n AS id, d.id AS domain_id, IF(q.n = 1, CONCAT('https://', d.host, '/'), CONCAT('https://', d.host, '/temat-', q.n, '/')) AS url
			FROM `{$t('serp_domains')}` d JOIN osf_bench_seq q ON q.n <= %d
		) x ORDER BY x.id",
		[$now, $urlsPerDomain, $urlsPerDomain],
	);
	$db->execute(
		"INSERT INTO `{$t('gap_domain_pages')}` (domain_id, url_id, title, last_seen)
		SELECT u.domain_id, u.id, CONCAT('Strona ', u.id, ' — usługi i oferta'), CURDATE() FROM `{$t('serp_urls')}` u",
	);
});

// Zbiory: konkurent d ma R fraz z puli (krok względnie pierwszy z pulą → bez powtórzeń), adres wg tematu frazy.
$phase(sprintf('zbiory konkurentów (%s wierszy) i punkty odniesienia (%s)', number_format($domainCount * $rowsPerDomain), number_format($projectCount * $baselineRows)), static function () use ($db, $t, $domains, $domainCount, $rowsPerDomain, $baselineRows, $keywordPool, $urlsPerDomain, $numbers, $today): void {
	$strides = [7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59, 61, 67, 71, 73, 79, 83, 89, 97];
	$strides = array_values(array_filter($strides, static function (int $prime) use ($keywordPool): bool {
		return $keywordPool % $prime !== 0;
	}));

	foreach (array_keys($domains) as $d) {
		$isProject = $d > $domainCount;
		$rows = $isProject ? $baselineRows : $rowsPerDomain;
		$db->execute(
			"INSERT INTO `{$t('gap_domain_keywords')}` (domain_id, market_keyword_id, rank_group, rank_absolute, url_id, etv, serp_on, first_seen, last_seen, seen_run_id, present)
			SELECT %d, x.mk, x.r, x.r + 1,
				IF(x.r > 40 OR CRC32(CONCAT('h', %d, x.mk)) %% 20 = 0, (%d - 1) * %d + 1, (%d - 1) * %d + 2 + (x.mk DIV 25) %% (%d - 1)),
				ROUND(x.r * 0.7, 2), %s, %s, %s, 0, 1
			FROM (SELECT 1 + (%d + n.k * %d) %% %d AS mk, 1 + CRC32(CONCAT(%d, ':', n.k)) %% %d AS r FROM {$numbers($rows)} n) x",
			[$d, $d, $d, $urlsPerDomain, $d, $urlsPerDomain, $urlsPerDomain, $today, $today, $today, $d * 1009, $strides[$d % count($strides)], $keywordPool, $d, $isProject ? 100 : 30],
		);
	}
});

// Projekty i konkurenci (konkurenci z puli domen — te same zbiory w wielu projektach), ustawienia Luk SEO.
$clock = new OsfSeo\Support\SystemClock();
$competitorRepository = new CompetitorRepository($db, $clock);
$projects = $phase(sprintf('projekty (%d) i konkurenci (%d na projekt)', $projectCount, $competitorsPerProject), static function () use ($db, $t, $projectCount, $competitorsPerProject, $domainCount, $competitorRepository, $now): array {
	$ids = [];

	for ($p = 1; $p <= $projectCount; $p++) {
		$ids[$p] = $db->insert($t('projects'), [
			'public_id' => Ulid::generate(),
			'name' => 'Benchmark ' . $p,
			'domain' => 'projekt-' . $p . '.example',
			'country' => 'pl',
			'language' => 'pl',
			'created_at' => $now,
			'updated_at' => $now,
		]);

		for ($k = 0; $k < $competitorsPerProject; $k++) {
			$d = 1 + (($p * 3 + $k * 7) % $domainCount);
			$competitorRepository->create($ids[$p], 'Konkurent ' . $d, 'konkurent-' . $d . '.example', 1);
		}

		$db->insert($t('gap_settings'), ['project_id' => $ids[$p], 'updated_at' => $now]);
	}

	return $ids;
});
$mainProject = $projects[1];

// GSC projektu nr 1: frazy z jego zbiorów (wolumen ≥ 10), 30 dni w oknie 90 dni, strona dla każdej frazy.
$phase(sprintf('GSC projektu nr 1 (%s fraz × 30 dni + strony)', number_format($gscKeywords)), static function () use ($db, $t, $mainProject, $gscKeywords, $domainCount, $now): void {
	$domainIds = array_map('intval', array_column($db->fetchAll("SELECT d.id FROM `{$t('gap_domains')}` d JOIN `{$t('serp_competitors')}` c ON c.domain = d.domain WHERE c.project_id = %d", [$mainProject]), 'id'));
	$domainIds[] = $domainCount + 1;
	$db->execute(
		"INSERT INTO `{$t('keywords')}` (project_id, keyword, keyword_hash, market_key, first_seen, last_seen, created_at)
		SELECT %d, m.keyword, UNHEX(MD5(m.keyword)), m.keyword_key, DATE_SUB(CURDATE(), INTERVAL 89 DAY), CURDATE(), %s
		FROM (SELECT DISTINCT market_keyword_id FROM `{$t('gap_domain_keywords')}` WHERE domain_id IN (" . implode(',', $domainIds) . ") ORDER BY market_keyword_id LIMIT %d) g
		JOIN `{$t('market_keywords')}` m ON m.id = g.market_keyword_id",
		[$mainProject, $now, $gscKeywords],
	);
	$db->execute(
		"INSERT INTO `{$t('pages')}` (project_id, url, url_hash, path, first_seen, last_seen, created_at)
		SELECT %d, CONCAT('https://projekt-1.example/temat-', q.n, '/'), UNHEX(MD5(CONCAT('https://projekt-1.example/temat-', q.n, '/'))), CONCAT('/temat-', q.n, '/'), CURDATE(), CURDATE(), %s
		FROM osf_bench_seq q WHERE q.n <= 200",
		[$mainProject, $now],
	);
	$db->execute(
		"INSERT INTO `{$t('gsc_query_daily')}` (project_id, date, keyword_id, clicks, impressions, position_sum)
		SELECT k.project_id, DATE_SUB(CURDATE(), INTERVAL q.n * 3 DAY), k.id, CRC32(CONCAT(k.id, q.n)) %% 3, 1 + CRC32(CONCAT('i', k.id, q.n)) %% 12,
			(1 + CRC32(CONCAT('i', k.id, q.n)) %% 12) * (1 + CRC32(CONCAT('p', k.id)) %% 60)
		FROM `{$t('keywords')}` k JOIN osf_bench_seq q ON q.n <= 30 WHERE k.project_id = %d",
		[$mainProject],
	);
	$db->execute(
		"INSERT INTO `{$t('gsc_query_page_daily')}` (project_id, date, keyword_id, page_id, clicks, impressions, position_sum)
		SELECT q.project_id, q.date, q.keyword_id, p.id, q.clicks, q.impressions, q.position_sum
		FROM `{$t('gsc_query_daily')}` q JOIN `{$t('pages')}` p ON p.project_id = q.project_id AND p.path = CONCAT('/temat-', 1 + (q.keyword_id DIV 25) %% 200, '/')
		WHERE q.project_id = %d",
		[$mainProject],
	);
	// 500 monitorowanych fraz z bieżącym pomiarem (TOP100, ok. 70% znalezionych).
	$db->execute(
		"INSERT INTO `{$t('serp_tracked_keywords')}` (public_id, project_id, market_keyword_id, source, status, added_by, added_at, updated_at,
			last_snapshot_id, last_checked_at, last_found, last_rank, last_depth, last_url_id)
		SELECT CONCAT('0C', LPAD(g.market_keyword_id, 24, '0')), %d, g.market_keyword_id, 'manual', 'active', 1, %s, %s,
			g.market_keyword_id, %s, IF(g.market_keyword_id %% 10 < 7, 1, 0), IF(g.market_keyword_id %% 10 < 7, 1 + g.market_keyword_id %% 60, NULL), 100,
			IF(g.market_keyword_id %% 10 < 7, (%d - 1) * 300 + 2, NULL)
		FROM (SELECT DISTINCT market_keyword_id FROM `{$t('gap_domain_keywords')}` WHERE domain_id = %d ORDER BY market_keyword_id LIMIT 500) g",
		[$mainProject, $now, $now, $now, $domainCount + 1, $domainCount + 1],
	);
});

$db->execute('ANALYZE TABLE ' . implode(', ', array_map(static fn (string $name): string => '`' . $t($name) . '`', ['market_keywords', 'gap_domain_keywords', 'gap_domains', 'gap_domain_pages', 'serp_urls', 'keywords', 'gsc_query_daily', 'gsc_query_page_daily', 'serp_tracked_keywords'])));

$queryCount = 0;
add_filter('query', static function (string $sql) use (&$queryCount): string {
	$queryCount++;

	return $sql;
});

// Przeliczenie luk: projekt nr 1 (pełne), ponownie bez zmian (klucz danych), pozostałe projekty (zbiory współdzielone).
$refresher = osf_seo()->get(GapRefresher::class);
$out();
$out('## Przeliczenie luk (GapRefresher, bez API)');
$out();
$queryCount = 0;
memory_reset_peak_usage();
$start = microtime(true);
$report = $refresher->refresh($mainProject, true);
$out(sprintf(
	'- projekt nr 1 (pełne): %.2f s, %d zapytań SQL, szczyt pamięci %.1f MB — %s fraz konkurentów, %s luk na liście, %s grup, %s stron konkurencji',
	microtime(true) - $start,
	$queryCount,
	memory_get_peak_usage() / 1048576,
	number_format($report['keywords']),
	number_format($report['listed']),
	number_format($report['clusters']),
	number_format($report['pages']),
));
$queryCount = 0;
$start = microtime(true);
$skipped = $refresher->refresh($mainProject);
$out(sprintf('- projekt nr 1 bez zmian danych: %.1f ms, %d zapytań (pominięte: %s)', (microtime(true) - $start) * 1000, $queryCount, $skipped['skipped'] ? 'tak' : 'nie'));
$queryCount = 0;
$start = microtime(true);
$again = $refresher->refresh($mainProject, true);
$out(sprintf('- projekt nr 1 ponownie (wiersze istnieją — aktualizacja, stabilne grupy): %.2f s, %d zapytań', microtime(true) - $start, $queryCount));
$start = microtime(true);
$total = 0;

foreach (array_slice($projects, 1) as $projectId) {
	$total += $refresher->refresh($projectId, true)['listed'];
}

$out(sprintf('- pozostałe %d projektów: %.1f s łącznie (%.2f s na projekt), %s luk', $projectCount - 1, microtime(true) - $start, (microtime(true) - $start) / max(1, $projectCount - 1), number_format($total)));

// Import nowej domeny prawdziwą ścieżką: plan → kolejka → execute (10 stron po 1 000 fraz) z atrapą HTTP.
$importDomain = 'nowy-konkurent.example';
$importRows = 10000;
$importVariant = 0;
$requests = 0;
add_filter('pre_http_request', static function ($pre, array $args, string $url) use (&$importVariant, &$requests, $importRows, $keywordPool) {
	if (! str_contains($url, 'api.dataforseo.com')) {
		return $pre;
	}

	if (! str_ends_with($url, '/dataforseo_labs/google/ranked_keywords/live')) {
		return new WP_Error('http_request_failed', 'Benchmark: unexpected DataForSEO endpoint.');
	}

	$requests++;
	$body = json_decode((string) $args['body'], true)[0];
	$items = [];
	$offset = (int) $body['offset'];
	$limit = min((int) $body['limit'], $importRows - $offset);

	for ($i = $offset; $i < $offset + $limit; $i++) {
		// Drugi import: część fraz znika (koniec listy), część zmienia pozycję i adres.
		$mk = 1 + (31337 + $i * 7) % $keywordPool;
		$keyword = sprintf('fraza luki %d wariant %d', intdiv($mk, 25), $mk % 25);
		$volume = max(10, 40000 - $i * 4);
		$rank = 1 + ($i + ($importVariant > 0 && $i % 7 === 0 ? 9 : 0)) % 30;
		$path = $importVariant > 0 && $i % 11 === 0 ? '/nowy-temat-' : '/temat-';
		$items[] = [
			'se_type' => 'google',
			'keyword_data' => [
				'keyword' => $keyword,
				'location_code' => 2616,
				'language_code' => 'pl',
				'keyword_info' => ['search_volume' => $volume, 'cpc' => 1.5, 'competition' => 0.4, 'competition_level' => 'MEDIUM', 'last_updated_time' => '2026-09-20 10:00:00 +00:00'],
				'keyword_properties' => ['keyword_difficulty' => $i % 100, 'core_keyword' => null, 'is_another_language' => false],
				'search_intent_info' => ['main_intent' => 'commercial'],
			],
			'ranked_serp_element' => [
				'serp_item' => ['type' => 'organic', 'rank_group' => $rank, 'rank_absolute' => $rank + 1, 'domain' => $body['target'], 'url' => 'https://' . $body['target'] . $path . ($mk % 50) . '/', 'title' => 'Tytuł ' . ($mk % 50), 'etv' => 1.5],
				'last_updated_time' => '2026-09-25 08:00:00 +00:00',
			],
		];
	}

	$cost = round(0.012 + count($items) * 0.00012, 6);
	$json = ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => $cost, 'tasks_count' => 1, 'tasks_error' => 0, 'tasks' => [[
		'id' => sprintf('%08x-0000-1000-8000-%012x', $requests, $offset),
		'status_code' => 20000,
		'status_message' => 'Ok.',
		'cost' => $cost,
		'result_count' => 1,
		'data' => $body,
		'result' => [['target' => $body['target'], 'location_code' => 2616, 'language_code' => 'pl', 'total_count' => $importVariant > 0 ? $importRows - 500 : $importRows, 'items_count' => count($items), 'offset' => $offset, 'items' => $importVariant > 0 && $offset >= $importRows - 1000 ? array_slice($items, 0, 500) : $items]],
	]]];

	return ['headers' => ['content-type' => 'application/json'], 'body' => wp_json_encode($json), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

$guard = osf_seo()->get(OsfSeo\Auth\ProjectGuard::class);
$publicId = static fn (int $projectId): string => (string) $db->fetchValue("SELECT public_id FROM `{$t('projects')}` WHERE id = %d", [$projectId]);
$importProject = $projects[2];
$importContext = $guard->authorizeSystem($publicId($importProject));
$newCompetitor = $competitorRepository->create($importProject, 'Nowy konkurent', $importDomain, 1);
$gaps = osf_seo()->get(GapService::class);

$out();
$out('## Import 10 000 fraz domeny (GapService::start + execute, atrapa HTTP)');
$out();

foreach (['pierwszy import (nowe frazy domeny, wspólne frazy rynkowe)', 'ponowny import ze zmianami (zdarzenia new / lost / up / down / url)'] as $index => $label) {
	$importVariant = $index;
	$requests = 0;
	$queryCount = 0;
	$request = $gaps->request($importContext, ['baseline' => '0', 'force' => '1', 'competitors' => [$newCompetitor->publicId]]);
	$result = $gaps->start($importContext, $request, GapService::TRIGGER_CLI);

	if ($result->status !== GapStartResult::QUEUED || $result->run === null) {
		$out('- ' . $label . ': nie zakolejkowano (' . $result->status . ')');

		continue;
	}

	memory_reset_peak_usage();
	$start = microtime(true);
	$gaps->execute($importContext, $result->run, 600.0);
	$elapsed = microtime(true) - $start;
	$run = osf_seo()->get(OsfSeo\Gap\GapRunRepository::class)->findById($result->run->id);
	$events = $db->fetchAll("SELECT event, COUNT(*) AS n FROM `{$t('gap_domain_events')}` WHERE run_id = %d GROUP BY event", [$result->run->id]);
	$out(sprintf(
		'- %s: %.2f s łącznie z przeliczeniem luk projektu, %d żądań (stron), %d zapytań SQL, szczyt pamięci %.1f MB, stan %s, zdarzenia: %s',
		$label,
		$elapsed,
		$requests,
		$queryCount,
		memory_get_peak_usage() / 1048576,
		$run?->status ?? '?',
		$events === [] ? '—' : implode(', ', array_map(static fn (array $row): string => $row['event'] . ' ' . $row['n'], $events)),
	));
}

$db->execute('ANALYZE TABLE ' . implode(', ', array_map(static fn (string $name): string => '`' . $t($name) . '`', ['gap_keywords', 'gap_clusters', 'gap_competitor_pages', 'gap_domain_keywords', 'gap_domain_events'])));

$out();
$out('## Wiersze i rozmiar tabel');
$out();
$out('| Tabela | Wiersze | Dane | Indeksy |');
$out('|---|---:|---:|---:|');

foreach (['gap_domain_keywords', 'gap_keywords', 'gap_clusters', 'gap_competitor_pages', 'gap_domain_events', 'gap_domain_pages', 'gap_domains', 'gap_run_targets', 'market_keywords', 'serp_urls', 'gsc_query_daily', 'gsc_query_page_daily'] as $name) {
	$size = $db->fetchRow('SELECT data_length, index_length FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s', [$t($name)]);
	$out(sprintf(
		'| %s | %s | %.1f MB | %.1f MB |',
		$name,
		number_format((int) $db->fetchValue("SELECT COUNT(*) FROM `{$t($name)}`")),
		(int) ($size['data_length'] ?? 0) / 1048576,
		(int) ($size['index_length'] ?? 0) / 1048576,
	));
}

// Odczyty panelu (projekt nr 1).
/** @var list<string> $captured */
$captured = [];
add_filter('query', static function (string $sql) use (&$captured): string {
	$captured[] = $sql;

	return $sql;
});

$context = $guard->authorizeSystem($publicId($mainProject));
$listTotal = $gaps->keywords($context, GapFilters::fromInput([]))['total'];
$firstGap = (string) $db->fetchValue("SELECT public_id FROM `{$t('gap_keywords')}` WHERE project_id = %d AND listed = 1 ORDER BY priority DESC, id LIMIT 1", [$mainProject]);
$firstCluster = (string) $db->fetchValue("SELECT public_id FROM `{$t('gap_clusters')}` WHERE project_id = %d AND active = 1 ORDER BY keywords_count DESC, id LIMIT 1", [$mainProject]);
$competitor = $competitorRepository->active($mainProject)[0];
$pageRow = $db->fetchRow("SELECT LOWER(HEX(url_key)) AS url_key FROM `{$t('gap_competitor_pages')}` WHERE project_id = %d AND competitor_id = %d ORDER BY keywords DESC LIMIT 1", [$mainProject, $competitor->id]);

$cases = [
	sprintf('Luki fraz: lista domyślna, strona 1 (%s luk)', number_format($listTotal)) => static fn () => $gaps->keywords($context, GapFilters::fromInput([])),
	'Luki fraz: ostatnia strona' => static fn () => $gaps->keywords($context, GapFilters::fromInput(['page' => (string) max(1, (int) ceil($listTotal / 50))])),
	'Luki fraz: wszystkie typy, sortowanie po wolumenie' => static fn () => $gaps->keywords($context, GapFilters::fromInput(['type' => 'all', 'sort' => 'volume'])),
	'Luki fraz: filtr konkurenta (EXISTS w zbiorze domeny)' => static fn () => $gaps->keywords($context, GapFilters::fromInput(['competitor' => $competitor->publicId])),
	'Luki fraz: wyszukiwanie „wariant 7” + min. wolumen 100 + maks. KD 40' => static fn () => $gaps->keywords($context, GapFilters::fromInput(['q' => 'wariant 7', 'min_volume' => '100', 'max_kd' => '40'])),
	'Luki fraz: wyszukiwanie z polskimi znakami („łódź”)' => static fn () => $gaps->keywords($context, GapFilters::fromInput(['q' => 'łódź'])),
	'Luki fraz: odfiltrowane' => static fn () => $gaps->keywords($context, GapFilters::fromInput(['filtered' => '1', 'type' => 'all'])),
	'Przegląd: liczniki i powody filtrów' => static fn () => $gaps->counts($context),
	'Przegląd: zbiory domen' => static fn () => $gaps->datasets($context),
	'Szczegóły luki (konkurenci, dowody projektu, historia)' => static fn () => $gaps->keyword($context, $firstGap),
	'Luki treści: lista grup' => static fn () => $gaps->clusters($context, null),
	'Luki treści: grupa (frazy + adresy konkurentów)' => static fn () => $gaps->cluster($context, $firstCluster),
	'Strony konkurencji: wszystkie' => static fn () => $gaps->pages($context, null),
	'Strony konkurencji: jeden konkurent' => static fn () => $gaps->pages($context, $competitor->publicId),
	'Strona konkurenta: frazy strony' => static fn () => $pageRow === null ? [] : $gaps->page($context, $competitor->publicId, (string) $pageRow['url_key']),
	'Plan importu bez API (5 domen + punkt odniesienia)' => static fn () => $gaps->plan($context, $gaps->request($context, ['force' => '1'])),
];

$out();
$out('## Czasy odczytów (mediana z 3 uruchomień, projekt nr 1)');
$out();
$out('| Przypadek | Czas | Wynik |');
$out('|---|---:|---|');
$queriesByCase = [];
$slowest = ['label' => '', 'ms' => 0.0];

foreach ($cases as $label => $case) {
	$captured = [];
	[$ms, $result] = $timer($case);
	$queriesByCase[$label] = array_values(array_unique($captured));
	$summary = match (true) {
		is_array($result) && isset($result['rows'], $result['total']) => sprintf('%d wierszy, łącznie %s; %d zapytań', count($result['rows']), number_format((int) $result['total']), count($queriesByCase[$label])),
		is_array($result) && isset($result['row'], $result['competitors']) => sprintf('%d konkurentów, %d zdarzeń; %d zapytań', count($result['competitors']), count($result['events']), count($queriesByCase[$label])),
		is_array($result) && isset($result['cluster']) => sprintf('%d fraz, %d adresów; %d zapytań', count($result['keywords']), count($result['urls']), count($queriesByCase[$label])),
		is_array($result) && isset($result['page']) => sprintf('%d fraz strony; %d zapytań', count($result['keywords']), count($queriesByCase[$label])),
		is_array($result) && isset($result['types']) => sprintf('%s; %d zapytań', json_encode($result['types']), count($queriesByCase[$label])),
		$result instanceof OsfSeo\Gap\GapPlan => sprintf('%d żądań, maks. %.4f USD; %d zapytań', $result->requests(), $result->estimatedCost(), count($queriesByCase[$label])),
		is_array($result) => sprintf('%d pozycji; %d zapytań', count($result), count($queriesByCase[$label])),
		default => '—',
	};
	$out(sprintf('| %s | %.1f ms | %s |', $label, $ms, $summary));

	if ($ms > $slowest['ms']) {
		$slowest = ['label' => $label, 'ms' => $ms];
	}
}

$out();
$out(sprintf('Najwolniejszy odczyt: %s — %.1f ms.', $slowest['label'], $slowest['ms']));

// EXPLAIN odczytów i najcięższych zapytań przeliczenia (zebranych przy ponownym przeliczeniu projektu nr 1).
$captured = [];
$refresher->refresh($mainProject, true);
$queriesByCase['Przeliczenie luk projektu nr 1 (zapytania odczytu)'] = array_values(array_unique($captured));

$out();
$out('## EXPLAIN');
$explained = [];

foreach ($queriesByCase as $label => $queries) {
	foreach ($queries as $sql) {
		$key = preg_replace('/\d+/', 'N', $sql);

		if (! preg_match('/^\s*SELECT/i', $sql) || isset($explained[$key]) || str_contains($sql, 'information_schema') || str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK') || str_contains($sql, '_options')) {
			continue;
		}

		$explained[$key] = true;
		$out();
		$out('### ' . $label);
		$out('```sql');
		$out(trim((string) preg_replace('/\s+/', ' ', mb_substr($sql, 0, 500))) . (mb_strlen($sql) > 500 ? ' …' : ''));
		$out('```');
		$out('| id | select_type | table | type | key | key_len | rows | Extra |');
		$out('|---|---|---|---|---|---|---:|---|');

		foreach ($wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A) as $planRow) {
			$out(sprintf('| %s | %s | %s | %s | %s | %s | %s | %s |', $planRow['id'] ?? '', $planRow['select_type'] ?? '', $planRow['table'] ?? '', $planRow['type'] ?? '', $planRow['key'] ?? '', $planRow['key_len'] ?? '', $planRow['rows'] ?? '', $planRow['Extra'] ?? ''));
		}
	}
}

if (! isset($options['keep'])) {
	foreach (OSF_SEO_GAP_BENCHMARK_TABLES as $table) {
		$db->execute("TRUNCATE TABLE `{$db->table($table)}`");
	}
}
