<?php

/**
 * Test wydajności raportów na syntetycznych danych: generuje projekt z dużą liczbą fraz i dni,
 * mierzy czasy KeywordReport/OverviewReport i analizy szans SEO (STEP 11) i wykonuje EXPLAIN dla ich zapytań.
 *
 * Działa na OSOBNEJ bazie testowej (zmienne OSF_SEO_TEST_DB_* jak testy integracyjne) — czyści tabele faktów.
 * Nigdy nie wskazuj bazy strony.
 *
 *   composer test:performance                         (domyślnie: 20 000 fraz, 480 dni, ~5 000 fraz dziennie)
 *   php tests/Performance/benchmark.php --keywords=50000 --days=480 --per-day=12000 --pages-days=90
 */

declare(strict_types=1);

use OsfSeo\Analytics\KeywordFilters;
use OsfSeo\Analytics\KeywordReport;
use OsfSeo\Analytics\OverviewReport;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migrator;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Support\DateRange;
use OsfSeo\Support\Logger;
use OsfSeo\Support\SystemClock;

$options = getopt('', ['keywords::', 'days::', 'per-day::', 'pages-days::', 'noise::', 'keep']);
$keywordCount = (int) ($options['keywords'] ?? 20000);
$days = (int) ($options['days'] ?? 480);
$perDay = (int) ($options['per-day'] ?? 5000);
$pageDays = (int) ($options['pages-days'] ?? 90);
$noiseRows = (int) ($options['noise'] ?? 1000000);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Integration/install.php'), $code);

if ($code !== 0) {
	fwrite(STDERR, "WordPress test installation failed. Check OSF_SEO_TEST_DB_* variables.\n");
	exit(1);
}

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

const OSF_SEO_BENCHMARK_TABLES = ['projects', 'keywords', 'pages', 'gsc_site_daily', 'gsc_query_daily', 'gsc_query_page_daily', 'opportunities', 'opportunity_detections', 'opportunity_analyses'];

foreach (OSF_SEO_BENCHMARK_TABLES as $table) {
	$db->execute("TRUNCATE TABLE `{$db->table($table)}`");
}

$now = gmdate('Y-m-d H:i:s');
$latest = '2026-09-27';
$first = DateRange::shift($latest, -($days - 1));
$projectIds = [];

foreach (['Benchmark', 'Szum'] as $name) {
	$db->insert($db->table('projects'), [
		'public_id' => OsfSeo\Support\Ulid::generate(),
		'name' => $name,
		'domain' => strtolower($name) . '.example',
		'gsc_property' => 'sc-domain:' . strtolower($name) . '.example',
		'gsc_permission' => 'siteOwner',
		'gsc_data_property' => 'sc-domain:' . strtolower($name) . '.example',
		'created_at' => $now,
		'updated_at' => $now,
	]);
	$projectIds[$name] = (int) $db->fetchValue('SELECT MAX(id) FROM `' . $db->table('projects') . '`');
}

$projectId = $projectIds['Benchmark'];
$noiseProject = $projectIds['Szum'];
$version = $db->fetchValue('SELECT VERSION()');
$out("# OSF SEO — benchmark raportów ({$version})");
$out();
$out(sprintf('Dane: %d fraz, %d dni (%s – %s), ~%d fraz dziennie, query_page: ostatnie %d dni, projekt „szum”: %d wierszy.', $keywordCount, $days, $first, $latest, $perDay, $pageDays, $noiseRows));

$generationStart = microtime(true);

// Słownik fraz i stron.
$keywords = new BulkInsert($db, $db->table('keywords'), ['project_id', 'keyword', 'keyword_hash', 'created_at'], ['%d', '%s', 'UNHEX(%s)', '%s']);

for ($i = 1; $i <= $keywordCount; $i++) {
	$text = 'fraza testowa ' . $i;
	$keywords->add([$projectId, $text, md5($text), $now]);
}

$keywords->flush();
$keywordBase = (int) $db->fetchValue('SELECT MIN(id) FROM `' . $db->table('keywords') . '` WHERE project_id = %d', [$projectId]);
$pagesInsert = new BulkInsert($db, $db->table('pages'), ['project_id', 'url', 'url_hash', 'path', 'created_at'], ['%d', '%s', 'UNHEX(%s)', '%s', '%s']);

for ($i = 1; $i <= 2000; $i++) {
	$url = 'https://benchmark.example/strona-' . $i . '/';
	$pagesInsert->add([$projectId, $url, md5($url), '/strona-' . $i . '/', $now]);
}

$pagesInsert->flush();
$pageBase = (int) $db->fetchValue('SELECT MIN(id) FROM `' . $db->table('pages') . '` WHERE project_id = %d', [$projectId]);

// Fakty: deterministyczny rozkład (popularne frazy codziennie, długi ogon rzadziej).
$site = new BulkInsert($db, $db->table('gsc_site_daily'), ['project_id', 'date', 'device', 'clicks', 'impressions', 'position_sum'], ['%d', '%s', '%d', '%d', '%d', '%f']);
$query = new BulkInsert($db, $db->table('gsc_query_daily'), ['project_id', 'date', 'keyword_id', 'clicks', 'impressions', 'position_sum'], ['%d', '%s', '%d', '%d', '%d', '%f']);
$queryPage = new BulkInsert($db, $db->table('gsc_query_page_daily'), ['project_id', 'date', 'keyword_id', 'page_id', 'clicks', 'impressions', 'position_sum'], ['%d', '%s', '%d', '%d', '%d', '%d', '%f']);
$rows = 0;
$pageRows = 0;

for ($d = 0; $d < $days; $d++) {
	$date = DateRange::shift($first, $d);
	$siteClicks = 0;
	$siteImpressions = 0;
	$sitePositionSum = 0.0;

	for ($n = 0; $n < $perDay; $n++) {
		// Frazy 1..perDay/2 codziennie, reszta rotuje po długim ogonie.
		$k = $n < intdiv($perDay, 2) ? $n + 1 : intdiv($perDay, 2) + 1 + (($n * 7919 + $d * 104729) % max(1, $keywordCount - intdiv($perDay, 2)));
		$impressions = 1 + intdiv(20000, $k + 10) + ($k * 31 + $d) % 17;
		$position = 1.0 + ($k % 97) + (($d + $k) % 10) / 10;
		$clicks = intdiv($impressions * max(0, 30 - (int) $position), 100);
		$query->add([$projectId, $date, $keywordBase + $k - 1, $clicks, $impressions, $position * $impressions]);
		$rows++;
		$siteClicks += $clicks;
		$siteImpressions += $impressions;
		$sitePositionSum += $position * $impressions;

		if ($d >= $days - $pageDays && $n < intdiv($perDay * 6, 10)) {
			$queryPage->add([$projectId, $date, $keywordBase + $k - 1, $pageBase + ($k % 2000), $clicks, $impressions, $position * $impressions]);
			$pageRows++;
		}
	}

	$site->add([$projectId, $date, 0, (int) ($siteClicks * 1.1), (int) ($siteImpressions * 1.2), $sitePositionSum * 1.2]);
}

// Drugi projekt — dane innego klienta w tych samych tabelach (sprawdza, że zapytania nie skanują cudzych wierszy).
for ($i = 0; $i < $noiseRows; $i++) {
	$query->add([$noiseProject, DateRange::shift($first, $i % $days), 900000000 + intdiv($i, $days), 1, 10, 50.0]);
}

$site->flush();
$query->flush();
$queryPage->flush();

// Słowniki innego projektu (plany złączeń ze słownikami przy wielu projektach).
$noiseKeywords = new BulkInsert($db, $db->table('keywords'), ['project_id', 'keyword', 'keyword_hash', 'created_at'], ['%d', '%s', 'UNHEX(%s)', '%s']);

for ($i = 1; $i <= 200000; $i++) {
	$noiseKeywords->add([$noiseProject, 'szum ' . $i, md5('szum ' . $i), $now]);
}

$noiseKeywords->flush();
$noisePages = new BulkInsert($db, $db->table('pages'), ['project_id', 'url', 'url_hash', 'path', 'created_at'], ['%d', '%s', 'UNHEX(%s)', '%s', '%s']);

for ($i = 1; $i <= 20000; $i++) {
	$url = 'https://szum.example/strona-' . $i . '/';
	$noisePages->add([$noiseProject, $url, md5($url), '/strona-' . $i . '/', $now]);
}

$noisePages->flush();

// Szanse innego projektu w tych samych tabelach (plany zapytań listy szans przy wielu projektach).
$noiseOpportunities = new BulkInsert($db, $db->table('opportunities'), ['public_id', 'project_id', 'fingerprint', 'type', 'property', 'page_url', 'page_hash', 'state', 'status', 'last_priority', 'first_detected_at', 'last_detected_at', 'created_at', 'updated_at'], ['%s', '%d', 'UNHEX(%s)', '%s', '%s', '%s', 'UNHEX(%s)', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);

for ($i = 0; $i < 30000; $i++) {
	$url = 'https://szum.example/strona-' . ($i % 3000) . '/';
	$noiseOpportunities->add([OsfSeo\Support\Ulid::generate(), $noiseProject, md5('noise' . $i), ['low_ctr', 'near_top', 'decline'][$i % 3], 'sc-domain:szum.example', $url, md5($url), 'active', 'new', $i % 100, $now, $now, $now, $now]);
}

$noiseOpportunities->flush();
$db->execute(
	'INSERT INTO `' . $db->table('opportunity_detections') . '` (opportunity_id, period_days, project_id, priority, confidence, impressions, clicks, latest_date, search_text, evidence, analyzed_at)
	SELECT id, p.days, project_id, last_priority, 2, 100, 5, %s, page_url, %s, %s FROM `' . $db->table('opportunities') . '` JOIN (SELECT 7 AS days UNION ALL SELECT 28 UNION ALL SELECT 90) p WHERE project_id = %d',
	[$latest, '{}', $now, $noiseProject],
);
$generation = microtime(true) - $generationStart;
$db->execute('ANALYZE TABLE `' . $db->table('gsc_query_daily') . '`, `' . $db->table('gsc_query_page_daily') . '`, `' . $db->table('keywords') . '`, `' . $db->table('pages') . '`, `' . $db->table('opportunities') . '`, `' . $db->table('opportunity_detections') . '`');
$out(sprintf('Wygenerowano %s wierszy query_daily (+%s szumu), %s query_page_daily, słowniki i 30 000 szans innego projektu (200 000 fraz, 20 000 adresów, 90 000 wykryć) w %.1f s.', number_format($rows), number_format($noiseRows), number_format($pageRows), $generation));
$out();

$context = osf_seo()->get(ProjectGuard::class)->authorizeSystem((string) $db->fetchValue('SELECT public_id FROM `' . $db->table('projects') . '` WHERE id = %d', [$projectId]));
$keywordsReport = new KeywordReport($db);
$overviewReport = new OverviewReport($db, $keywordsReport);
$cachedOverview = new OverviewReport($db, $keywordsReport, new OsfSeo\Analytics\ReportCache());

/** @var list<string> $captured */
$captured = [];
add_filter('query', static function (string $sql) use (&$captured): string {
	$captured[] = $sql;

	return $sql;
});

$analyzer = osf_seo()->get(OsfSeo\Opportunities\OpportunityAnalyzer::class);
$opportunities = osf_seo()->get(OsfSeo\Opportunities\OpportunityService::class);
$memory = [];
$analysis = static function (int $periodDays) use ($analyzer, $context, &$memory): Closure {
	return static function () use ($analyzer, $context, $periodDays, &$memory) {
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$result = $analyzer->analyze($context, $periodDays, 'cli', true);
		$memory[$periodDays] = max($memory[$periodDays] ?? 0, memory_get_peak_usage() - $before);

		return $result;
	};
};

$cases = [
	'Szanse: analiza 28 dni (agregaty + wykrywanie + zapis)' => $analysis(28),
	'Szanse: analiza 7 dni' => $analysis(7),
	'Szanse: analiza 90 dni' => $analysis(90),
	'Szanse: lista 28 dni, strona 1' => static fn () => $opportunities->list($context, OsfSeo\Opportunities\OpportunityFilters::fromInput(['status' => 'all'])),
	'Szanse: lista z wyszukiwaniem i filtrem typu' => static fn () => $opportunities->list($context, OsfSeo\Opportunities\OpportunityFilters::fromInput(['status' => 'all', 'type' => 'near_top', 'q' => 'testowa 1'])),
	'Szanse: widok wg podstron' => static fn () => $opportunities->list($context, OsfSeo\Opportunities\OpportunityFilters::fromInput(['status' => 'all', 'view' => 'pages'])),
	'Frazy: 28 dni, sortowanie po kliknięciach, strona 1' => static fn () => $keywordsReport->keywords($context, KeywordFilters::fromInput([])),
	'Frazy: 28 dni, strona 50' => static fn () => $keywordsReport->keywords($context, KeywordFilters::fromInput(['page' => 50])),
	'Frazy: 90 dni, sortowanie po zmianie pozycji' => static fn () => $keywordsReport->keywords($context, KeywordFilters::fromInput(['days' => 90, 'sort' => 'position_change'])),
	'Frazy: wyszukiwanie „testowa 12”' => static fn () => $keywordsReport->keywords($context, KeywordFilters::fromInput(['q' => 'testowa 12'])),
	'Frazy: wzrosty (próg wyświetleń)' => static fn () => $keywordsReport->keywords($context, KeywordFilters::fromInput(['movement' => 'gains', 'sort' => 'position_change'])),
	'Dashboard: przegląd 28 dni (KPI, TOP N, wzrosty/spadki, seria)' => static fn () => $overviewReport->overview($context, 28),
	'Dashboard: przegląd 90 dni' => static fn () => $overviewReport->overview($context, 90),
	'Dashboard: przegląd 90 dni z cache (transient, kolejne wejście)' => static fn () => $cachedOverview->overview($context, 90),
];

$out('## Czasy (mediana z 3 uruchomień)');
$out();
$out('| Przypadek | Czas | Wynik |');
$out('|---|---:|---|');
$queriesByCase = [];

foreach ($cases as $label => $case) {
	$captured = [];
	[$ms, $result] = $timer($case);
	$queriesByCase[$label] = array_values(array_unique($captured));
	$summary = match (true) {
		$result instanceof OsfSeo\Analytics\KeywordPage => sprintf('%d wierszy, łącznie %s fraz', count($result->rows), number_format($result->total)),
		$result instanceof OsfSeo\Opportunities\AnalysisResult => sprintf('%s: %d szans%s', $result->status, $result->opportunities, $result->reason !== null ? ' (' . $result->reason . ')' : ''),
		$result instanceof OsfSeo\Opportunities\OpportunityPage => sprintf('%d na stronie, łącznie %d', count($result->rows) + count($result->groups), $result->total),
		default => sprintf('TOP10 %d, wzrosty %d, spadki %d', $result->visibility->current[10], count($result->gains), count($result->losses)),
	};
	$out(sprintf('| %s | %.0f ms | %s |', $label, $ms, $summary));
}

$out();
$out(sprintf('Pamięć PHP analizy szans (przyrost szczytu ponad stan przed analizą): %s.', implode(', ', array_map(static fn (int $days, int $bytes): string => sprintf('%d dni %.0f MB', $days, $bytes / 1048576), array_keys($memory), $memory))));
$out(sprintf('Szanse w bazie: %d, wykrycia: %d.', (int) $db->fetchValue('SELECT COUNT(*) FROM `' . $db->table('opportunities') . '`'), (int) $db->fetchValue('SELECT COUNT(*) FROM `' . $db->table('opportunity_detections') . '`')));
$out();
$out('## EXPLAIN');

$explained = [];

foreach ($queriesByCase as $label => $queries) {
	foreach ($queries as $sql) {
		$key = preg_replace('/\d+/', 'N', $sql);

		if (! str_starts_with(ltrim($sql), 'SELECT') || isset($explained[$key])) {
			continue;
		}

		$explained[$key] = true;
		$out();
		$out('### ' . $label);
		$out('```sql');
		$out(trim((string) preg_replace('/\s+/', ' ', mb_substr($sql, 0, 400))) . (mb_strlen($sql) > 400 ? ' …' : ''));
		$out('```');
		$out('| id | select_type | table | type | key | key_len | rows | Extra |');
		$out('|---|---|---|---|---|---|---:|---|');

		foreach ($GLOBALS['wpdb']->get_results('EXPLAIN ' . $sql, ARRAY_A) as $plan) {
			$out(sprintf('| %s | %s | %s | %s | %s | %s | %s | %s |', $plan['id'] ?? '', $plan['select_type'] ?? '', $plan['table'] ?? '', $plan['type'] ?? '', $plan['key'] ?? '', $plan['key_len'] ?? '', $plan['rows'] ?? '', $plan['Extra'] ?? ''));
		}
	}
}

if (! isset($options['keep'])) {
	foreach (OSF_SEO_BENCHMARK_TABLES as $table) {
		$db->execute("TRUNCATE TABLE `{$db->table($table)}`");
	}
}
