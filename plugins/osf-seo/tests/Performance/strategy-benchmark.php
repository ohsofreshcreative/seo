<?php

/**
 * Benchmark Strategii (STEP 16, faza E) na syntetycznych danych — bez żadnego żądania do API:
 *
 * - projekty z N frazami GSC (domyślnie 100, 1 000 i 5 000; okno 90 dni, frazy × podstrony), kandydatami Nowych fraz i Luk SEO
 *   (połowa wspólna z GSC), szansami SEO, wpisami ręcznymi i monitorowanymi frazami SERP z historią pomiarów (TOP20),
 * - pełne przeliczenie (`StrategyRefresher::refresh`, wymuszone): czasy faz (klucz danych, zbieranie, fakty i dowody, zapis, tematy),
 *   łączny czas, liczba zapytań SQL i zapytań zapisujących, szczytowa pamięć, liczby wierszy kandydatów, tematów i zdarzeń,
 * - drugi przebieg bez zmian danych (klucz bez zmian → nic do zrobienia), wymuszony przebieg bez zmian danych (zapis tylko stanu)
 *   i przebieg po małej zmianie danych (import jednego dnia),
 * - koszt wykrywania zmian (klucz danych projektu) i krok w tle (`StrategyScheduler::runBackground`) dla wszystkich projektów naraz.
 *
 * Działa na OSOBNEJ bazie testowej (zmienne OSF_SEO_TEST_DB_* jak testy integracyjne) — czyści tabele projektów, GSC, modułów
 * i Strategii. Nigdy nie wskazuj bazy strony.
 *
 *   composer test:performance:strategy
 *   php tests/Performance/strategy-benchmark.php --sizes=100,1000,5000 --extra=10 --days=90 [--keep]
 */

declare(strict_types=1);

use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migrator;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Opportunities\UrlKey;
use OsfSeo\Serp\SerpContext;
use OsfSeo\Serp\SerpContextRepository;
use OsfSeo\Serp\SerpDevice;
use OsfSeo\Strategy\StrategyRefresher;
use OsfSeo\Strategy\StrategyRefreshQueue;
use OsfSeo\Strategy\StrategyScheduler;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Support\Logger;
use OsfSeo\Support\SystemClock;
use OsfSeo\Support\Ulid;

$options = getopt('', ['sizes::', 'extra::', 'extra-size::', 'days::', 'serp::', 'keep']);
$sizes = array_values(array_filter(array_map('intval', explode(',', (string) ($options['sizes'] ?? '100,1000,5000'))), static fn (int $n): bool => $n > 0));
$extraProjects = max(0, (int) ($options['extra'] ?? 10));
$extraSize = max(10, (int) ($options['extra-size'] ?? 300));
$days = max(30, min(120, (int) ($options['days'] ?? 90)));
$serpMax = max(0, (int) ($options['serp'] ?? 100));

require dirname(__DIR__, 2) . '/vendor/autoload.php';

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Integration/install.php'), $code);

if ($code !== 0) {
	fwrite(STDERR, "WordPress test installation failed. Check OSF_SEO_TEST_DB_* variables.\n");
	exit(1);
}

require dirname(__DIR__) . '/Integration/wp-tests-config.php';
define('WP_CLI', true); // kontekst systemowy projektu (ProjectGuard::authorizeSystem) i krok w tle
require ABSPATH . 'wp-settings.php';
require dirname(__DIR__, 2) . '/osf-seo.php';

// Żadne żądanie HTTP nie wychodzi z benchmarku (Strategia nie wysyła żądań; atrapa liczy próby).
$httpAttempts = 0;
add_filter('pre_http_request', static function () use (&$httpAttempts): WP_Error {
	$httpAttempts++;

	return new WP_Error('osf_seo_benchmark', 'HTTP disabled in the strategy benchmark.');
}, 1);

$db = Connection::fromGlobals();
(new Migrator($db, new Logger(Logger::ERROR, static function (): void {
})))->migrate();

$out = static function (string $line = ''): void {
	fwrite(STDOUT, $line . "\n");
};
$t = static fn (string $name): string => $db->table($name);

const OSF_SEO_STRATEGY_BENCHMARK_TABLES = [
	'projects', 'keywords', 'pages', 'gsc_query_daily', 'gsc_query_page_daily', 'gsc_site_daily', 'sync_state', 'sync_runs',
	'market_keywords', 'market_keyword_monthly', 'market_tasks', 'opportunities', 'opportunity_detections', 'opportunity_analyses',
	'discovery_settings', 'discovery_candidates', 'gap_settings', 'gap_keywords', 'gap_clusters', 'serp_competitors', 'serp_settings',
	'serp_contexts', 'serp_tracked_keywords', 'serp_runs', 'serp_snapshots', 'serp_results', 'serp_domains', 'serp_urls', 'serp_snapshot_profiles',
	'strategy_settings', 'strategy_keywords', 'strategy_topics', 'strategy_topic_events',
];

foreach (OSF_SEO_STRATEGY_BENCHMARK_TABLES as $table) {
	$db->execute("TRUNCATE TABLE `{$db->table($table)}`");
}

/** @var wpdb $wpdb */
$wpdb = $GLOBALS['wpdb'];
$wpdb->save_queries = false;
$writes = 0;
add_filter('query', static function (string $query) use (&$writes): string {
	if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query) === 1) {
		$writes++;
	}

	return $query;
});

$version = $db->fetchValue('SELECT VERSION()');
$clock = new SystemClock();
$now = gmdate('Y-m-d H:i:s');
$latest = gmdate('Y-m-d', strtotime('-3 days'));
$market = new Market('dataforseo', 'pl', 'pl', 2616, 'pl', 'Polska', 'polski');
$metrics = new MarketMetricsRepository($db, $clock);
$serpContext = (new SerpContextRepository($db, $clock))->ensure(SerpContext::forMarket($market, SerpDevice::Desktop, 100));

$out("# Whack-a-mole — benchmark Strategii ({$version}, PHP " . PHP_VERSION . ')');
$out();
$out(sprintf(
	'Dane: projekty z %s frazami GSC (okno %d dni, ok. 1/3 dni z wyświetleniami, frazy × podstrony), Nowe frazy i Luki SEO po 10%% (połowa wspólna z GSC), szanse SEO 5%%, 5 wpisów ręcznych, do %d monitorowanych fraz SERP × 4 pomiary × TOP20; dodatkowo %d projektów po %d fraz.',
	implode(' / ', array_map('number_format', $sizes)),
	$days,
	$serpMax,
	$extraProjects,
	$extraSize,
));
$out();

$db->execute('DROP TEMPORARY TABLE IF EXISTS osf_bench_seq');
$db->execute('CREATE TEMPORARY TABLE osf_bench_seq (n SMALLINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$db->execute('INSERT INTO osf_bench_seq (n) VALUES ' . implode(',', array_map(static fn (int $n): string => '(' . $n . ')', range(1, 1000))));

// Słownik domen SERP: pula 200 domen (konkurencja w wynikach) + domeny projektów.
$db->execute(
	"INSERT INTO `{$t('serp_domains')}` (id, host_hash, host, host_rev, created_at)
	SELECT n, UNHEX(MD5(CONCAT('domena-', n, '.example'))), CONCAT('domena-', n, '.example'), CONCAT('example.domena-', n), %s FROM osf_bench_seq WHERE n <= 200",
	[$now],
);
$db->execute(
	"INSERT INTO `{$t('serp_urls')}` (id, url_hash, domain_id, url, created_at)
	SELECT (d.id - 1) * 20 + q.n, UNHEX(MD5(CONCAT('https://', d.host, '/strona-', q.n, '/'))), d.id, CONCAT('https://', d.host, '/strona-', q.n, '/'), %s
	FROM `{$t('serp_domains')}` d JOIN osf_bench_seq q ON q.n <= 20",
	[$now],
);

/**
 * Projekt z danymi wszystkich źródeł Strategii (deterministycznie).
 *
 * @return array{id: int, public_id: string, keywords: int}
 */
$createProject = static function (string $slug, int $size) use ($db, $t, $metrics, $market, $now, $latest, $days, $serpMax, $serpContext): array {
	$domain = $slug . '.example';
	$publicId = Ulid::generate();
	$projectId = $db->insert($t('projects'), [
		'public_id' => $publicId,
		'name' => 'Benchmark ' . $slug,
		'domain' => $domain,
		'country' => 'pl',
		'language' => 'pl',
		'gsc_property' => 'sc-domain:' . $domain,
		'gsc_permission' => 'siteOwner',
		'gsc_data_property' => 'sc-domain:' . $domain,
		'last_synced_at' => $now,
		'created_at' => $now,
		'updated_at' => $now,
	]);
	$first = gmdate('Y-m-d', strtotime($latest . ' -' . ($days - 1) . ' days'));

	// Frazy rynkowe: GSC (N) + nowe frazy spoza GSC (10%); metryki dla ok. 80% (reszta „—”).
	// Prefiks fraz bez nazwy projektu i domeny (inaczej filtr marki odrzuciłby frazy jako markowe).
	$code = 'k' . base_convert((string) crc32($slug), 10, 36);
	$gscTexts = array_map(static fn (int $i): string => sprintf('%s usługa %d wariant %d', $code, intdiv($i, 4), $i % 4), range(1, $size));
	$newTexts = array_map(static fn (int $i): string => sprintf('%s nowa fraza %d', $code, $i), range(1, max(1, intdiv($size, 10))));
	$ids = [];

	foreach (array_chunk([...$gscTexts, ...$newTexts], 1000) as $chunk) {
		$ids += $metrics->ensure($market, $chunk);
	}

	$db->execute(
		"UPDATE `{$t('market_keywords')}` SET search_volume = IF(CRC32(id) %% 10 < 8, 10 + CRC32(CONCAT('v', id)) %% 5000, NULL),
			keyword_difficulty = IF(CRC32(id) %% 10 < 7, CRC32(CONCAT('k', id)) %% 100, NULL), cpc = (CRC32(CONCAT('c', id)) %% 900) / 100,
			search_intent = ELT(1 + CRC32(CONCAT('i', id)) %% 4, 'informational', 'commercial', 'transactional', 'navigational')
		WHERE keyword LIKE %s",
		[$db->escapeLike($code . ' ') . '%'],
	);

	// Słownik GSC z kluczem rynkowym, podstrony (8 fraz na stronę tematu).
	$db->execute(
		"INSERT INTO `{$t('keywords')}` (project_id, keyword, keyword_hash, market_key, first_seen, last_seen, created_at)
		SELECT %d, keyword, UNHEX(MD5(keyword)), keyword_key, %s, %s, %s FROM `{$t('market_keywords')}` WHERE keyword LIKE %s ORDER BY id",
		[$projectId, $first, $latest, $now, $db->escapeLike($code . ' usługa ') . '%'],
	);
	$pages = new BulkInsert($db, $t('pages'), ['project_id', 'url', 'url_hash', 'path', 'first_seen', 'last_seen', 'created_at'], ['%d', '%s', 'UNHEX(%s)', '%s', '%s', '%s', '%s']);

	for ($p = 0; $p <= intdiv($size, 8); $p++) {
		$url = 'https://' . $domain . '/temat-' . $p . '/';
		$pages->add([$projectId, $url, md5($url), '/temat-' . $p . '/', $first, $latest, $now]);
	}

	$pages->flush();
	$pageBase = (int) $db->fetchValue("SELECT MIN(id) FROM `{$t('pages')}` WHERE project_id = %d", [$projectId]);
	$keywordBase = (int) $db->fetchValue("SELECT MIN(id) FROM `{$t('keywords')}` WHERE project_id = %d", [$projectId]);

	// Fakty GSC: każda fraza ok. co trzeci dzień okna, pozycja 1–45 (kwalifikuje się do Strategii), frazy × podstrony.
	foreach (['gsc_query_daily' => false, 'gsc_query_page_daily' => true] as $table => $withPage) {
		$db->execute(
			"INSERT INTO `{$t($table)}` (project_id, date, keyword_id" . ($withPage ? ', page_id' : '') . ", clicks, impressions, position_sum)
			SELECT %d, DATE_SUB(%s, INTERVAL (q.n - 1) DAY), k.id" . ($withPage ? ', %d + (k.id - %d) DIV 8' : '') . ",
				(CRC32(CONCAT(k.id, ':', q.n)) %% 3), 2 + (CRC32(CONCAT('m', k.id, ':', q.n)) %% 25),
				(2 + (CRC32(CONCAT('m', k.id, ':', q.n)) %% 25)) * (1 + (k.id %% 45) + (q.n %% 3) / 10)
			FROM `{$t('keywords')}` k JOIN osf_bench_seq q ON q.n <= %d
			WHERE k.project_id = %d AND CRC32(CONCAT('d', k.id, ':', q.n)) %% 3 = 0",
			$withPage ? [$projectId, $latest, $pageBase, $keywordBase, $days, $projectId] : [$projectId, $latest, $days, $projectId],
		);
	}

	foreach (['query', 'query_page'] as $dataset) {
		$db->insert($t('sync_state'), ['project_id' => $projectId, 'dataset' => $dataset, 'newest_date' => $latest, 'oldest_date' => gmdate('Y-m-d', strtotime($latest . ' -480 days')), 'last_success_at' => $now, 'updated_at' => $now]);
	}

	// Nowe frazy i luki fraz: połowa wspólna z GSC, połowa spoza GSC; szanse SEO na poziomie frazy (5%).
	$tenth = max(1, intdiv($size, 10));
	$moduleKeywords = [...array_slice($gscTexts, 0, intdiv($tenth, 2)), ...array_slice($newTexts, 0, $tenth - intdiv($tenth, 2))];
	$discovery = new BulkInsert($db, $t('discovery_candidates'), ['public_id', 'project_id', 'market_keyword_id', 'status', 'seeds_count', 'best_relation', 'visibility', 'priority', 'excluded', 'discovered_at', 'last_seen_at', 'created_at', 'updated_at'], ['%s', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s']);
	$gaps = new BulkInsert($db, $t('gap_keywords'), ['public_id', 'project_id', 'market_keyword_id', 'status', 'active', 'listed', 'gap_type', 'visibility', 'priority', 'competitors_count', 'first_seen_at', 'created_at', 'updated_at'], ['%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);

	foreach ($moduleKeywords as $i => $text) {
		$marketId = (int) $ids[$text];
		$discovery->add([Ulid::generate(), $projectId, $marketId, 'new', 1, 1, 'unknown', 50 + $i % 50, 0, $now, $now, $now, $now]);
		$gaps->add([Ulid::generate(), $projectId, $marketId, 'new', 1, 1, ['missing', 'weak', 'unknown'][$i % 3], 'none', 40 + $i % 60, 1 + $i % 4, $now, $now, $now]);
	}

	$discovery->flush();
	$gaps->flush();
	$db->insert($t('gap_settings'), ['project_id' => $projectId, 'updated_at' => $now]);
	$opportunities = max(1, intdiv($size, 20));

	for ($i = 0; $i < $opportunities; $i++) {
		$keyword = $gscTexts[$i * 3 % $size];
		$page = 'https://' . $domain . '/temat-' . intdiv(($i * 3) % $size, 8) . '/';
		$id = $db->insert($t('opportunities'), [
			'public_id' => Ulid::generate(), 'project_id' => $projectId, 'fingerprint' => md5($slug . $i, true), 'type' => ['low_ctr', 'near_top', 'decline'][$i % 3],
			'property' => 'sc-domain:' . $domain, 'page_url' => $page, 'keyword' => $keyword, 'state' => 'active', 'status' => 'new', 'last_priority' => 40 + $i % 60,
			'first_detected_at' => $now, 'last_detected_at' => $now, 'created_at' => $now, 'updated_at' => $now,
		]);
		$db->execute("UPDATE `{$t('opportunities')}` SET page_hash = UNHEX(%s) WHERE id = %d", [bin2hex(UrlKey::hash($page)), $id]);
		$db->insert($t('opportunity_detections'), [
			'opportunity_id' => $id, 'period_days' => 28, 'project_id' => $projectId, 'priority' => 40 + $i % 60, 'confidence' => 2, 'impressions' => 200, 'clicks' => 3,
			'latest_date' => $latest, 'search_text' => $keyword, 'evidence' => '{}', 'analyzed_at' => $now,
		]);
	}

	// Monitorowane frazy SERP z historią: 4 pomiary tygodniowe × TOP20 (projekt na pozycji 1–30 albo poza TOP20).
	$tracked = min($serpMax, $tenth);
	$db->insert($t('serp_settings'), ['project_id' => $projectId, 'enabled' => 0, 'device' => 'desktop', 'depth' => 100, 'updated_at' => $now]);
	$projectDomainId = $db->insert($t('serp_domains'), ['host_hash' => md5($domain, true), 'host' => $domain, 'host_rev' => implode('.', array_reverse(explode('.', $domain))), 'created_at' => $now]);
	$projectUrlId = $db->insert($t('serp_urls'), ['url_hash' => md5('https://' . $domain . '/temat-0/', true), 'domain_id' => $projectDomainId, 'url' => 'https://' . $domain . '/temat-0/', 'created_at' => $now]);

	foreach (array_slice($gscTexts, 0, $tracked) as $i => $text) {
		$trackedId = $db->insert($t('serp_tracked_keywords'), ['public_id' => Ulid::generate(), 'project_id' => $projectId, 'market_keyword_id' => (int) $ids[$text], 'source' => 'manual', 'status' => 'active', 'added_by' => 1, 'added_at' => $now, 'updated_at' => $now]);
		$snapshotId = 0;

		for ($w = 3; $w >= 0; $w--) {
			$checked = gmdate('Y-m-d H:i:s', strtotime($now . ' -' . ($w * 7) . ' days'));
			$rank = ($i + $w) % 5 === 0 ? null : 1 + ($i * 7 + $w) % 30;
			$snapshotId = $db->insert($t('serp_snapshots'), [
				'public_id' => Ulid::generate(), 'project_id' => $projectId, 'tracked_keyword_id' => $trackedId, 'market_keyword_id' => (int) $ids[$text],
				'context_id' => $serpContext->id, 'provider' => 'dataforseo', 'status' => 'completed', 'trigger_type' => 'schedule', 'requested_depth' => 100,
				'created_at' => $checked, 'checked_at' => $checked, 'collected_at' => $checked, 'items_count' => 20, 'organic_count' => 20, 'item_types' => 1,
				'project_rank' => $rank, 'project_rank_absolute' => $rank, 'project_url_id' => $rank === null ? null : $projectUrlId, 'project_results' => $rank === null ? 0 : 1,
			]);
			$db->execute(
				"INSERT INTO `{$t('serp_results')}` (snapshot_id, item_index, result_type, rank_group, rank_absolute, page, domain_id, url_id, flags)
				SELECT %d, q.n, 1, q.n, q.n, CEIL(q.n / 10), IF(q.n = %d, %d, 1 + CRC32(CONCAT(%d, ':', q.n)) %% 200),
					IF(q.n = %d, %d, (1 + CRC32(CONCAT(%d, ':', q.n)) %% 200 - 1) * 20 + 1 + (q.n %% 20)), 0
				FROM osf_bench_seq q WHERE q.n <= 20",
				[$snapshotId, $rank ?? 0, $projectDomainId, $snapshotId, $rank ?? 0, $projectUrlId, $snapshotId],
			);
		}

		$db->execute(
			"UPDATE `{$t('serp_tracked_keywords')}` t JOIN `{$t('serp_snapshots')}` s ON s.id = %d
			SET t.last_snapshot_id = s.id, t.last_context_id = s.context_id, t.last_checked_at = s.checked_at, t.last_found = s.project_rank IS NOT NULL,
				t.last_rank = s.project_rank, t.last_rank_absolute = s.project_rank_absolute, t.last_url_id = s.project_url_id, t.last_depth = 100, t.last_featured = 0
			WHERE t.id = %d",
			[$snapshotId, $trackedId],
		);
	}

	// Wpisy ręczne (bez wzbogacania, jak z panelu).
	osf_seo()->get(StrategyService::class)->addKeywords(
		osf_seo()->get(ProjectGuard::class)->authorizeSystem($publicId),
		implode(', ', array_map(static fn (int $i): string => sprintf('%s fraza klienta %d', $code, $i), range(1, 5))),
	);

	return ['id' => $projectId, 'public_id' => $publicId, 'keywords' => $size];
};

$out('## Generowanie danych');
$out();
$projects = [];
foreach ($sizes as $size) {
	$start = microtime(true);
	$projects[$size] = $createProject('strategia-' . $size, $size);
	$out(sprintf('- projekt %s fraz GSC: %.1f s', number_format($size), microtime(true) - $start));
}

$extra = [];
$start = microtime(true);

for ($i = 1; $i <= $extraProjects; $i++) {
	$extra[] = $createProject('dodatkowy-' . $i, $extraSize);
}

if ($extra !== []) {
	$out(sprintf('- %d dodatkowych projektów po %d fraz: %.1f s', $extraProjects, $extraSize, microtime(true) - $start));
}

$db->execute('ANALYZE TABLE ' . implode(', ', array_map(static fn (string $name): string => '`' . $t($name) . '`', ['keywords', 'pages', 'gsc_query_daily', 'gsc_query_page_daily', 'market_keywords', 'serp_snapshots', 'serp_results', 'serp_tracked_keywords', 'discovery_candidates', 'gap_keywords', 'opportunities'])));
$out(sprintf('- wiersze: gsc_query_daily %s, gsc_query_page_daily %s, serp_results %s, market_keywords %s', ...array_map(
	static fn (string $table): string => number_format((int) $db->fetchValue("SELECT COUNT(*) FROM `{$t($table)}`")),
	['gsc_query_daily', 'gsc_query_page_daily', 'serp_results', 'market_keywords'],
)));
$out();

$plugin = osf_seo();
$refresher = $plugin->get(StrategyRefresher::class);
$guard = $plugin->get(ProjectGuard::class);

/**
 * Pomiar wywołania: czas, zapytania SQL, zapytania zapisujące, szczytowa pamięć (od zera).
 *
 * @return array{0: mixed, 1: array{ms: float, queries: int, writes: int, peak_mb: float}}
 */
$measure = static function (callable $callback) use ($wpdb, &$writes): array {
	gc_collect_cycles();
	memory_reset_peak_usage();
	$base = memory_get_usage();
	$queries = $wpdb->num_queries;
	$writesBefore = $writes;
	$start = microtime(true);
	$result = $callback();

	return [$result, [
		'ms' => (microtime(true) - $start) * 1000,
		'queries' => $wpdb->num_queries - $queries,
		'writes' => $writes - $writesBefore,
		'peak_mb' => (memory_get_peak_usage() - $base) / 1048576,
	]];
};

$out('## Przeliczenie projektu (StrategyRefresher, bez API)');
$out();
$out('| Fraz GSC | Kandydaci (ponad limit) | Tematy / zdarzenia | Klucz | Zbieranie | Fakty i dowody | Zapis | Tematy | Razem | Zapytania SQL | Zapisy SQL | Pamięć (szczyt) |');
$out('|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|');
$followUps = [];

foreach ($projects as $size => $project) {
	[$report, $m] = $measure(static fn (): array => $refresher->refresh($project['id'], true));
	$timings = $report['stats']['timings'];
	$out(sprintf(
		'| %s | %s (%s) | %s / %s | %d ms | %d ms | %d ms | %d ms | %d ms | **%d ms** | %s | %s | %.1f MB |',
		number_format($size),
		number_format((int) $report['stats']['selected']),
		number_format((int) $report['stats']['overflow']),
		number_format((int) $report['topics']['topics']),
		number_format((int) $report['topics']['events']),
		$timings['key'],
		$timings['collect'],
		$timings['evidence'],
		$timings['save'],
		$timings['topics'],
		$m['ms'],
		number_format($m['queries']),
		number_format($m['writes']),
		$m['peak_mb'],
	));

	// Bez zmian danych (klucz bez zmian), wymuszone bez zmian danych, po małej zmianie (import jednego dnia).
	[$unchanged, $mu] = $measure(static fn (): array => $refresher->refresh($project['id']));
	[$forced, $mf] = $measure(static fn (): array => $refresher->refresh($project['id'], true));
	$keywordBase = (int) $db->fetchValue("SELECT MIN(id) FROM `{$t('keywords')}` WHERE project_id = %d", [$project['id']]);
	$db->execute(
		"INSERT INTO `{$t('gsc_query_daily')}` (project_id, date, keyword_id, clicks, impressions, position_sum)
		SELECT %d, DATE_ADD(%s, INTERVAL 1 DAY), id, 1, 40, 40 * (1 + id %% 20) FROM `{$t('keywords')}` WHERE project_id = %d AND id < %d + %d",
		[$project['id'], $latest, $project['id'], $keywordBase, max(5, intdiv($size, 100))],
	);
	$db->execute("UPDATE `{$t('sync_state')}` SET newest_date = DATE_ADD(%s, INTERVAL 1 DAY) WHERE project_id = %d", [$latest, $project['id']]);
	$db->execute("UPDATE `{$t('projects')}` SET last_synced_at = %s WHERE id = %d", [gmdate('Y-m-d H:i:s', time() + 60), $project['id']]);
	[$changed, $mc] = $measure(static fn (): array => $refresher->refresh($project['id']));
	[$key, $mk] = $measure(static fn (): ?string => $refresher->currentKey($project['id']));
	$followUps[$size] = [$unchanged, $mu, $forced, $mf, $changed, $mc, $mk];
}

$out();
$out('## Kolejne przebiegi');
$out();
$out('| Fraz GSC | Bez zmian danych (klucz) | Wymuszony bez zmian: kandydaci zapisani / tematy zmienione / zdarzenia | Po imporcie dnia: kandydaci nowi+zmienieni / tematy zmienione / zdarzenia | Wykrywanie zmian (klucz danych) |');
$out('|---:|---|---|---|---|');

foreach ($followUps as $size => [$unchanged, $mu, $forced, $mf, $changed, $mc, $mk]) {
	$out(sprintf(
		'| %s | %s, %d ms, %d zapytań, %d zapisów | %d / %d / %d — %d ms, %s zapytań, %d zapisów | %d / %d / %d — %d ms, %s zapytań, %s zapisów | %d ms, %d zapytań |',
		number_format($size),
		(string) $unchanged['skipped'],
		$mu['ms'],
		$mu['queries'],
		$mu['writes'],
		$forced['inserted'] + $forced['updated'],
		$forced['topics']['inserted'] + $forced['topics']['updated'],
		$forced['topics']['events'],
		$mf['ms'],
		number_format($mf['queries']),
		$mf['writes'],
		$changed['inserted'] + $changed['updated'],
		$changed['topics']['inserted'] + $changed['topics']['updated'],
		$changed['topics']['events'],
		$mc['ms'],
		number_format($mc['queries']),
		number_format($mc['writes']),
		$mk['ms'],
		$mk['queries'],
	));
}

// Krok w tle dla wszystkich projektów naraz: zlecenia ręczne (wymuszone) → jeden tick; potem tick bez zmian (samo wykrywanie).
$scheduler = $plugin->get(StrategyScheduler::class);
$queue = $plugin->get(StrategyRefreshQueue::class);
$all = [...array_values($projects), ...$extra];

foreach ($all as $project) {
	$queue->request($project['id'], 1);
}

[$tick, $mt] = $measure(static fn (): array => $scheduler->runBackground(600.0, true));
$remaining = count($queue->dueWithProjects(1000));
[$idle, $mi] = $measure(static fn (): array => $scheduler->runBackground(20.0, true));
$out();
$out(sprintf('## Krok w tle (%d projektów)', count($all)));
$out();
$out(sprintf(
	'- zlecenia ręczne wszystkich projektów, jeden przebieg (limit %d zadań na tick): %d przeliczonych, %d na kolejny tick — %.0f ms, %s zapytań, %s zapisów, pamięć %.1f MB',
	StrategyScheduler::MAX_JOBS_PER_RUN,
	count(array_filter($tick['jobs'], static fn (string $outcome): bool => $outcome === 'refreshed')),
	$remaining,
	$mt['ms'],
	number_format($mt['queries']),
	number_format($mt['writes']),
	$mt['peak_mb'],
));
$out(sprintf(
	'- kolejny tick: wykrywanie zmian %s i pozostałe zadania %s — %.0f ms, %s zapytań, %d zapisów',
	json_encode($idle['detected']),
	json_encode(array_count_values($idle['jobs'])),
	$mi['ms'],
	number_format($mi['queries']),
	$mi['writes'],
));
[$quiet, $mq] = $measure(static fn (): array => $scheduler->runBackground(20.0, true));
$out(sprintf('- tick bez zmian danych: %s — %.0f ms, %s zapytań, %d zapisów', json_encode($quiet['detected']), $mq['ms'], number_format($mq['queries']), $mq['writes']));
$out();
$out(sprintf('Żądania HTTP: %d (Strategia nie wysyła żądań do API).', $httpAttempts));

if (! isset($options['keep'])) {
	foreach (OSF_SEO_STRATEGY_BENCHMARK_TABLES as $table) {
		$db->execute("TRUNCATE TABLE `{$db->table($table)}`");
	}
}
