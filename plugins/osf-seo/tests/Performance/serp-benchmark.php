<?php

/**
 * Benchmark pozycji SERP (STEP 14) na syntetycznych danych — bez żadnego żądania do DataForSEO:
 *
 * - N projektów × M monitorowanych fraz z pełnym TOP100 w ostatnim pomiarze,
 * - pierwszy projekt z historią tygodniową (H pomiarów każdej frazy),
 * - projekt „duży” z kilkoma tysiącami monitorowanych fraz (lista, plan, liczniki, konkurenci organiczni),
 * - zapis pełnego TOP100 prawdziwą ścieżką (`SerpStore::ingest`: słowniki + wstawianie wsadowe + stan bieżący),
 * - czasy odczytów panelu i EXPLAIN ich zapytań, liczby wierszy i rozmiary tabel.
 *
 * Dane pomocnicze są generowane przez INSERT … SELECT (miliony wierszy w rozsądnym czasie). Działa na OSOBNEJ bazie
 * testowej (zmienne OSF_SEO_TEST_DB_* jak testy integracyjne) — czyści tabele SERP, projektów i fraz. Nigdy nie wskazuj bazy strony.
 *
 *   composer test:performance:serp
 *   php tests/Performance/serp-benchmark.php --projects=100 --keywords=500 --history=52 --large=2500 [--ingest=200] [--keep]
 */

declare(strict_types=1);

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migrator;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Serp\CompetitorService;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Serp\PositionsFilters;
use OsfSeo\Serp\SerpContext;
use OsfSeo\Serp\SerpContextRepository;
use OsfSeo\Serp\SerpDevice;
use OsfSeo\Serp\SerpDictionary;
use OsfSeo\Serp\SerpItem;
use OsfSeo\Serp\SerpPage;
use OsfSeo\Serp\SerpReports;
use OsfSeo\Serp\SerpSettingsRepository;
use OsfSeo\Serp\SerpSnapshotRepository;
use OsfSeo\Serp\SerpStore;
use OsfSeo\Serp\SerpSubmitter;
use OsfSeo\Serp\SerpTrackingService;
use OsfSeo\Serp\TrackedKeywordRepository;
use OsfSeo\Support\Logger;
use OsfSeo\Support\SystemClock;
use OsfSeo\Support\Ulid;

$options = getopt('', ['projects::', 'keywords::', 'history::', 'large::', 'domains::', 'urls::', 'snippets::', 'ingest::', 'competitors::', 'keep']);
$projectCount = max(2, (int) ($options['projects'] ?? 100));
$keywordsPerProject = max(10, (int) ($options['keywords'] ?? 500));
$historyWeeks = max(2, (int) ($options['history'] ?? 52));
$largeKeywords = max(100, (int) ($options['large'] ?? 2500));
$domainPool = max(1000, (int) ($options['domains'] ?? 50000));
$urlsPerDomain = max(2, min(100, (int) ($options['urls'] ?? 20)));
$snippetPool = max(1000, (int) ($options['snippets'] ?? 300000));
$ingestCount = max(10, (int) ($options['ingest'] ?? 200));
$competitorsPerProject = max(1, min(20, (int) ($options['competitors'] ?? 5)));

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
$t = static fn (string $name): string => $db->table($name);

const OSF_SEO_SERP_BENCHMARK_TABLES = [
	'projects', 'keywords', 'gsc_site_daily', 'gsc_query_daily', 'market_keywords', 'market_keyword_monthly', 'market_tasks',
	'serp_contexts', 'serp_settings', 'serp_competitors', 'serp_tracked_keywords', 'serp_runs', 'serp_snapshots', 'serp_results',
	'serp_domains', 'serp_urls', 'serp_snippets',
];

foreach (OSF_SEO_SERP_BENCHMARK_TABLES as $table) {
	$db->execute("TRUNCATE TABLE `{$db->table($table)}`");
}

$wpdb = $GLOBALS['wpdb'];
$version = $db->fetchValue('SELECT VERSION()');
$now = gmdate('Y-m-d H:i:s');
$latest = gmdate('Y-m-d 06:00:00', strtotime('-1 day'));

$out("# Wibble — benchmark pozycji SERP ({$version})");
$out();
$out(sprintf(
	'Dane: %d projektów × %d monitorowanych fraz z pełnym TOP100 (ostatni pomiar), projekt nr 1 z historią %d tygodni, projekt „duży” z %s frazami; słowniki: %s domen, %d adresów na domenę, %s opisów; %d konkurentów na projekt.',
	$projectCount,
	$keywordsPerProject,
	$historyWeeks,
	number_format($largeKeywords),
	number_format($domainPool),
	$urlsPerDomain,
	number_format($snippetPool),
	$competitorsPerProject,
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

// Sekwencja 1..1000 do generowania wierszy przez INSERT … SELECT.
$db->execute('DROP TEMPORARY TABLE IF EXISTS osf_bench_seq');
$db->execute('CREATE TEMPORARY TABLE osf_bench_seq (n SMALLINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$db->execute('INSERT INTO osf_bench_seq (n) VALUES ' . implode(',', array_map(static fn (int $n): string => '(' . $n . ')', range(1, 1000))));

// Projekty: N zwykłych + „duży”.
$projects = $phase('projekty', static function () use ($db, $t, $projectCount, $now): array {
	$ids = [];

	for ($i = 1; $i <= $projectCount + 1; $i++) {
		$name = $i <= $projectCount ? 'projekt-' . $i : 'duzy';
		$ids[$i] = $db->insert($t('projects'), [
			'public_id' => Ulid::generate(),
			'name' => 'Benchmark ' . $name,
			'domain' => $name . '.example',
			'country' => 'pl',
			'language' => 'pl',
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	return $ids;
});
$largeIndex = $projectCount + 1;

// Słownik domen: pula (popularność skośna — niskie ID częściej w wynikach), domeny projektów (z subdomeną blog.).
$phase(sprintf('słownik domen (%s)', number_format($domainPool + 2 * count($projects))), static function () use ($db, $t, $domainPool, $projects, $now): void {
	$db->execute(
		"INSERT INTO `{$t('serp_domains')}` (id, host_hash, host, host_rev, created_at)
		SELECT k, UNHEX(MD5(h)), h, CONCAT('example.', SUBSTRING_INDEX(h, '.', 1)), %s
		FROM (SELECT CONCAT('domena-', (a.n - 1) * 1000 + b.n, '.example') AS h, (a.n - 1) * 1000 + b.n AS k FROM osf_bench_seq a JOIN osf_bench_seq b WHERE (a.n - 1) * 1000 + b.n <= %d) x
		ORDER BY k",
		[$now, $domainPool],
	);
	$hosts = new BulkInsert($db, $t('serp_domains'), ['host_hash', 'host', 'host_rev', 'created_at'], ['UNHEX(%s)', '%s', '%s', '%s']);

	foreach (array_keys($projects) as $index) {
		foreach (['', 'blog.'] as $prefix) {
			$host = $prefix . ($index === array_key_last($projects) ? 'duzy' : 'projekt-' . $index) . '.example';
			$hosts->add([md5($host), $host, DomainFamily::reverse($host), $now]);
		}
	}

	$hosts->flush();
});
$domainCount = (int) $db->fetchValue("SELECT COUNT(*) FROM `{$t('serp_domains')}`");
$projectDomains = [];

foreach ($projects as $index => $projectId) {
	$host = ($index === $largeIndex ? 'duzy' : 'projekt-' . $index) . '.example';
	$projectDomains[$projectId] = (int) $db->fetchValue("SELECT id FROM `{$t('serp_domains')}` WHERE host_hash = UNHEX(%s)", [md5($host)]);
}

// Słownik adresów: U adresów na każdą domenę, jawne ID = (domain_id − 1) × U + n (AUTO_INCREMENT przy INSERT … SELECT
// może zostawiać luki, a generator wyników wylicza ID adresu z domeny).
$phase(sprintf('słownik adresów (%s)', number_format($domainCount * $urlsPerDomain)), static function () use ($db, $t, $urlsPerDomain, $now): void {
	$db->execute(
		"INSERT INTO `{$t('serp_urls')}` (id, url_hash, domain_id, url, created_at)
		SELECT (d - 1) * %d + n, UNHEX(MD5(u)), d, u, %s FROM (
			SELECT d.id AS d, q.n AS n, CONCAT('https://', d.host, '/strona-', q.n, '/') AS u
			FROM `{$t('serp_domains')}` d JOIN osf_bench_seq q ON q.n <= %d
		) x ORDER BY d, n",
		[$urlsPerDomain, $now, $urlsPerDomain],
	);
});
$urlBase = 1;

$phase(sprintf('słownik opisów (%s)', number_format($snippetPool)), static function () use ($db, $t, $snippetPool, $now): void {
	$db->execute(
		"INSERT INTO `{$t('serp_snippets')}` (id, snippet_hash, title, description, breadcrumb, website_name, created_at)
		SELECT k, UNHEX(MD5(CONCAT('opis-', k))), CONCAT('Tytuł wyniku ', k, ' — przykładowa strona'), CONCAT('Opis wyniku ', k, '. Syntetyczny tekst o długości zbliżonej do opisu w Google, bez prawdziwych danych.'), CONCAT('https://przyklad.example › ', k), 'Przykład', %s
		FROM (SELECT (a.n - 1) * 1000 + b.n AS k FROM osf_bench_seq a JOIN osf_bench_seq b WHERE (a.n - 1) * 1000 + b.n <= %d) x ORDER BY k",
		[$now, $snippetPool],
	);
});

// Kontekst pomiaru (PL / pl / desktop / TOP100) i frazy rynkowe.
$clock = new SystemClock();
$market = new Market('dataforseo', 'pl', 'pl', 2616, 'pl', 'Polska', 'polski');
$context = (new SerpContextRepository($db, $clock))->ensure(SerpContext::forMarket($market, SerpDevice::Desktop, 100));
$contextId = (int) $context->id;
$metrics = new MarketMetricsRepository($db, $clock);
$keywordTexts = [];

$phase(sprintf('frazy rynkowe i monitorowane (%s)', number_format($projectCount * $keywordsPerProject + $largeKeywords)), static function () use ($db, $t, $metrics, $market, $projects, $largeIndex, $keywordsPerProject, $largeKeywords, $now, &$keywordTexts): void {
	$tracked = new BulkInsert($db, $t('serp_tracked_keywords'), ['public_id', 'project_id', 'market_keyword_id', 'source', 'status', 'added_by', 'added_at', 'updated_at'], ['%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s']);

	foreach ($projects as $index => $projectId) {
		$count = $index === $largeIndex ? $largeKeywords : $keywordsPerProject;
		$texts = array_map(static fn (int $j): string => sprintf('fraza %d %d', $index, $j), range(1, $count));
		$keywordTexts[$projectId] = $texts;

		foreach (array_chunk($texts, 1000) as $chunk) {
			foreach ($metrics->ensure($market, $chunk) as $marketId) {
				$tracked->add([Ulid::generate(), $projectId, $marketId, 'manual', 'active', 1, $now, $now]);
			}
		}
	}

	$tracked->flush();
	// Wolumen i trudność dla ok. 70% fraz (reszta „—”, jak frazy dodane ręcznie bez wzbogacania).
	$db->execute("UPDATE `{$t('market_keywords')}` SET search_volume = IF(CRC32(id) % 10 < 7, 10 + CRC32(CONCAT('v', id)) % 20000, NULL), keyword_difficulty = IF(CRC32(id) % 10 < 7, CRC32(CONCAT('k', id)) % 100, NULL)");
});

// Przebieg (jeden na projekt), konkurenci, ustawienia harmonogramu (włączone, termin minął).
$runIds = [];
$competitorDomains = [];
$competitorRepository = new CompetitorRepository($db, $clock);

$phase('przebiegi, konkurenci, ustawienia', static function () use ($db, $t, $projects, $contextId, $now, $competitorsPerProject, $competitorRepository, &$runIds, &$competitorDomains): void {
	foreach ($projects as $index => $projectId) {
		$runIds[$projectId] = $db->insert($t('serp_runs'), [
			'public_id' => Ulid::generate(),
			'project_id' => $projectId,
			'context_id' => $contextId,
			'trigger_type' => 'schedule',
			'status' => 'completed',
			'created_at' => $now,
			'updated_at' => $now,
		]);

		for ($k = 1; $k <= $competitorsPerProject; $k++) {
			$domainId = (($index * 7 + $k * 13) % 300) + 1;
			$competitorRepository->create($projectId, 'Konkurent ' . $k, 'domena-' . $domainId . '.example', 1);
			$competitorDomains[$projectId][$k] = $domainId;
		}

		$db->insert($t('serp_settings'), [
			'project_id' => $projectId,
			'enabled' => 1,
			'frequency' => 'weekly',
			'device' => 'desktop',
			'depth' => 100,
			'next_run_at' => gmdate('Y-m-d H:i:s', strtotime('-1 hour')),
			'updated_at' => $now,
		]);
	}
});

// Pomocnicze mapy: domena i baza adresów projektu, konkurenci projektu.
$db->execute('DROP TEMPORARY TABLE IF EXISTS osf_bench_project');
$db->execute('CREATE TEMPORARY TABLE osf_bench_project (project_id INT UNSIGNED PRIMARY KEY, domain_id INT UNSIGNED NOT NULL, run_id INT UNSIGNED NOT NULL, weeks SMALLINT UNSIGNED NOT NULL)');
$db->execute('DROP TEMPORARY TABLE IF EXISTS osf_bench_competitor');
$db->execute('CREATE TEMPORARY TABLE osf_bench_competitor (project_id INT UNSIGNED NOT NULL, k TINYINT UNSIGNED NOT NULL, domain_id INT UNSIGNED NOT NULL, PRIMARY KEY (project_id, k))');

foreach ($projects as $index => $projectId) {
	$db->execute('INSERT INTO osf_bench_project VALUES (%d, %d, %d, %d)', [$projectId, $projectDomains[$projectId], $runIds[$projectId], $index === 1 ? $historyWeeks : 1]);

	foreach ($competitorDomains[$projectId] as $k => $domainId) {
		$db->execute('INSERT INTO osf_bench_competitor VALUES (%d, %d, %d)', [$projectId, $k, $domainId]);
	}
}

// Pomiary: najstarsze tygodnie najpierw (ID rosną z czasem). Pozycja projektu: bazowa ± dryf, ok. 12% poza TOP100.
$phase('pomiary (serp_snapshots)', static function () use ($db, $t, $contextId, $latest, $urlBase, $urlsPerDomain): void {
	$db->execute(
		"INSERT INTO `{$t('serp_snapshots')}` (public_id, project_id, tracked_keyword_id, market_keyword_id, context_id, run_id, provider, provider_task_id,
			status, trigger_type, requested_depth, estimated_cost, cost, attempts, created_at, submitted_at, checked_at, collected_at, se_domain,
			se_results_count, pages_count, items_count, organic_count, item_types, project_rank, project_rank_absolute, project_url_id, project_results, project_featured)
		SELECT CONCAT('0B', LPAD(x.tid * 1000 + x.w, 24, '0')), x.project_id, x.tid, x.mk, %d, x.run_id, 'dataforseo', NULL,
			'completed', 'schedule', 100, 0.00465, 0.00465, 1, x.at, x.at, x.at, x.at, 'google.pl',
			100000 + CRC32(x.tid) %% 900000, 10, 100, 100, 1 | (CRC32(CONCAT('i', x.tid, x.w)) %% 2) * 128,
			x.r, x.r + 1, IF(x.r IS NULL, NULL, %d + (x.domain_id - 1) * %d + CRC32(CONCAT(x.mk, ':', x.domain_id)) %% %d), IF(x.r IS NULL, 0, 1), 0
		FROM (
			SELECT t.id AS tid, t.project_id, t.market_keyword_id AS mk, p.run_id, p.domain_id, w.n AS w,
				DATE_SUB(%s, INTERVAL (w.n - 1) WEEK) AS at,
				IF(CRC32(CONCAT(t.id, ':', w.n)) %% 100 < 88, GREATEST(1, LEAST(100, CAST(1 + CRC32(CONCAT('b', t.id)) %% 70 AS SIGNED) + CAST(CRC32(CONCAT(t.id, ':', w.n)) %% 11 AS SIGNED) - 5)), NULL) AS r
			FROM `{$t('serp_tracked_keywords')}` t
			JOIN osf_bench_project p ON p.project_id = t.project_id
			JOIN osf_bench_seq w ON w.n <= p.weeks
		) x
		ORDER BY x.w DESC, x.tid",
		[$contextId, $urlBase, $urlsPerDomain, $urlsPerDomain, $latest],
	);
});

$snapshotCount = (int) $db->fetchValue("SELECT COUNT(*) FROM `{$t('serp_snapshots')}`");

// Pozycje konkurentów w pomiarach (bez kolizji pozycji: pierwszy wygrywa; projekt ma pierwszeństwo).
$db->execute('DROP TEMPORARY TABLE IF EXISTS osf_bench_placed');
$db->execute('CREATE TEMPORARY TABLE osf_bench_placed (snapshot_id INT UNSIGNED NOT NULL, n SMALLINT UNSIGNED NOT NULL, domain_id INT UNSIGNED NOT NULL, PRIMARY KEY (snapshot_id, n))');
$phase('rozmieszczenie projektu i konkurentów', static function () use ($db, $t): void {
	$db->execute("INSERT IGNORE INTO osf_bench_placed SELECT s.id, s.project_rank, p.domain_id FROM `{$t('serp_snapshots')}` s JOIN osf_bench_project p ON p.project_id = s.project_id WHERE s.project_rank IS NOT NULL");
	$db->execute(
		"INSERT IGNORE INTO osf_bench_placed SELECT s.id, 1 + CRC32(CONCAT(s.id, 'c', c.k)) % 130, c.domain_id
		FROM `{$t('serp_snapshots')}` s JOIN osf_bench_competitor c ON c.project_id = s.project_id
		WHERE 1 + CRC32(CONCAT(s.id, 'c', c.k)) % 130 <= 100",
	);
});

// Pełne TOP100 każdego pomiaru, partiami po zakresach ID pomiaru.
$phase(sprintf('wyniki TOP100 (%s wierszy)', number_format($snapshotCount * 100)), static function () use ($db, $t, $domainPool, $urlBase, $urlsPerDomain, $snippetPool): void {
	[$min, $max] = array_map('intval', array_values($db->fetchRow("SELECT MIN(id) AS a, MAX(id) AS b FROM `{$t('serp_snapshots')}`")));

	for ($from = $min; $from <= $max; $from += 5000) {
		$db->execute(
			"INSERT INTO `{$t('serp_results')}` (snapshot_id, item_index, result_type, rank_group, rank_absolute, page, domain_id, url_id, snippet_id, flags)
			SELECT x.sid, x.n, 1, x.n, x.n + 1, CEIL(x.n / 10), x.domain_id,
				%d + (x.domain_id - 1) * %d + CRC32(CONCAT(x.mk, ':', x.domain_id, ':', x.n)) %% %d,
				1 + CRC32(CONCAT(x.sid, 's', x.n)) %% %d, 0
			FROM (
				SELECT s.id AS sid, s.market_keyword_id AS mk, q.n,
					COALESCE(pl.domain_id, 1 + FLOOR(POW((CRC32(CONCAT(s.id, ':', q.n)) %% 1000000) / 1000000, 3) * %d)) AS domain_id
				FROM `{$t('serp_snapshots')}` s
				JOIN osf_bench_seq q ON q.n <= 100
				LEFT JOIN osf_bench_placed pl ON pl.snapshot_id = s.id AND pl.n = q.n
				WHERE s.id BETWEEN %d AND %d
			) x",
			[$urlBase, $urlsPerDomain, $urlsPerDomain, $snippetPool, $domainPool, $from, min($max, $from + 4999)],
		);
	}
});

// Stan bieżący fraz: ostatni pomiar i poprzedni porównywalny (projekt z historią).
$phase('stan bieżący fraz', static function () use ($db, $t, $latest): void {
	$db->execute(
		"UPDATE `{$t('serp_tracked_keywords')}` t
		JOIN `{$t('serp_snapshots')}` s ON s.tracked_keyword_id = t.id AND s.checked_at = %s
		LEFT JOIN `{$t('serp_snapshots')}` p ON p.tracked_keyword_id = t.id AND p.checked_at = DATE_SUB(%s, INTERVAL 1 WEEK)
		SET t.last_snapshot_id = s.id, t.last_context_id = s.context_id, t.last_checked_at = s.checked_at, t.last_found = s.project_rank IS NOT NULL,
			t.last_rank = s.project_rank, t.last_rank_absolute = s.project_rank_absolute, t.last_url_id = s.project_url_id, t.last_depth = 100, t.last_featured = 0,
			t.last_requested_at = s.created_at, t.prev_snapshot_id = p.id, t.prev_found = IF(p.id IS NULL, NULL, p.project_rank IS NOT NULL), t.prev_rank = p.project_rank,
			t.change_type = CASE
				WHEN p.id IS NULL THEN 'new'
				WHEN p.project_rank IS NULL AND s.project_rank IS NULL THEN 'out'
				WHEN p.project_rank IS NULL THEN 'entered'
				WHEN s.project_rank IS NULL THEN 'left'
				WHEN p.project_rank > s.project_rank THEN 'up'
				WHEN p.project_rank < s.project_rank THEN 'down'
				ELSE 'same' END,
			t.change_value = IF(p.project_rank IS NULL OR s.project_rank IS NULL, NULL, CAST(p.project_rank AS SIGNED) - CAST(s.project_rank AS SIGNED)),
			t.top10_change = CASE
				WHEN p.id IS NULL THEN NULL
				WHEN (p.project_rank IS NULL OR p.project_rank > 10) AND s.project_rank <= 10 THEN 'entered'
				WHEN p.project_rank <= 10 AND (s.project_rank IS NULL OR s.project_rank > 10) THEN 'left'
				ELSE NULL END",
		[$latest, $latest],
	);
});

// Dane GSC (28 dni) dla projektu z historią i dużego — średnia pozycja GSC obok Pozycji SERP.
$historyProject = $projects[1];
$largeProject = $projects[$largeIndex];
$phase('dane GSC (28 dni) dla 2 projektów', static function () use ($db, $t, $historyProject, $largeProject, $keywordTexts, $now): void {
	foreach ([$historyProject, $largeProject] as $projectId) {
		$rows = new BulkInsert($db, $t('keywords'), ['project_id', 'keyword', 'keyword_hash', 'market_key', 'created_at'], ['%d', '%s', 'UNHEX(%s)', 'UNHEX(%s)', '%s']);

		foreach ($keywordTexts[$projectId] as $text) {
			$rows->add([$projectId, $text, md5($text), bin2hex(MarketKeyword::key(MarketKeyword::normalize($text))), $now]);
		}

		$rows->flush();
		$db->execute(
			"INSERT INTO `{$t('gsc_query_daily')}` (project_id, date, keyword_id, clicks, impressions, position_sum)
			SELECT k.project_id, DATE_SUB(CURDATE(), INTERVAL q.n DAY), k.id, CRC32(CONCAT(k.id, q.n)) %% 5, 5 + CRC32(CONCAT('i', k.id, q.n)) %% 50,
				(5 + CRC32(CONCAT('i', k.id, q.n)) %% 50) * (1 + CRC32(CONCAT('p', k.id)) %% 60)
			FROM `{$t('keywords')}` k JOIN osf_bench_seq q ON q.n <= 28 WHERE k.project_id = %d",
			[$projectId],
		);
		$db->execute(
			"INSERT INTO `{$t('gsc_site_daily')}` (project_id, date, device, clicks, impressions, position_sum)
			SELECT %d, DATE_SUB(CURDATE(), INTERVAL q.n DAY), 0, 100, 10000, 150000 FROM osf_bench_seq q WHERE q.n <= 28",
			[$projectId],
		);
	}
});

$db->execute('ANALYZE TABLE ' . implode(', ', array_map(static fn (string $name): string => '`' . $t($name) . '`', ['serp_tracked_keywords', 'serp_snapshots', 'serp_results', 'serp_domains', 'serp_urls', 'serp_snippets', 'market_keywords', 'keywords', 'gsc_query_daily'])));

// Rozmiary tabel.
$out();
$out('## Wiersze i rozmiar tabel');
$out();
$out('| Tabela | Wiersze | Dane | Indeksy |');
$out('|---|---:|---:|---:|');

foreach (['serp_results', 'serp_snapshots', 'serp_tracked_keywords', 'serp_urls', 'serp_domains', 'serp_snippets', 'serp_competitors', 'serp_runs', 'market_keywords'] as $name) {
	$size = $db->fetchRow('SELECT data_length, index_length FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s', [$t($name)]);
	$out(sprintf(
		'| %s | %s | %.1f MB | %.1f MB |',
		$name,
		number_format((int) $db->fetchValue("SELECT COUNT(*) FROM `{$t($name)}`")),
		(int) ($size['data_length'] ?? 0) / 1048576,
		(int) ($size['index_length'] ?? 0) / 1048576,
	));
}

// Zapis pełnego TOP100 prawdziwą ścieżką: SerpStore::ingest (słowniki → transakcja: przejęcie, wstawienie wsadowe, stan bieżący).
$out();
$out('## Zapis pełnego TOP100 (SerpStore::ingest)');
$out();

$snapshots = new SerpSnapshotRepository($db, $clock);
$trackedRepository = new TrackedKeywordRepository($db, $clock);
$store = new SerpStore($db, new SerpDictionary($db, $clock), $snapshots, $trackedRepository, $clock);
$ingestRun = $db->insert($t('serp_runs'), ['public_id' => Ulid::generate(), 'project_id' => $largeProject, 'context_id' => $contextId, 'trigger_type' => 'manual', 'status' => 'submitted', 'created_at' => $now, 'updated_at' => $now]);
$trackedForIngest = $db->fetchAll("SELECT id, market_keyword_id FROM `{$t('serp_tracked_keywords')}` WHERE project_id = %d ORDER BY id LIMIT %d", [$largeProject, $ingestCount]);
$page = static function (int $seed, bool $cold): SerpPage {
	$items = [];

	for ($rank = 1; $rank <= 100; $rank++) {
		$domain = $cold ? sprintf('nowa-%d-%d.example', $seed, $rank % 40) : 'domena-' . (1 + ($seed * 31 + $rank * 17) % 2000) . '.example';
		$host = $rank === 7 ? 'duzy.example' : $domain;
		$items[] = new SerpItem(SerpItem::TYPE_ORGANIC, $rank, $rank + 1, (int) ceil($rank / 10), $host, sprintf('https://%s/%s-%d/', $host, $cold ? 'nowy-adres-' . $seed : 'strona', $cold ? $rank : 1 + $rank % 20), 'Tytuł ' . $seed . ' ' . $rank, 'Opis wyniku ' . $seed . ' / ' . $rank, null, null);
	}

	return new SerpPage($items, gmdate('Y-m-d H:i:s'), 'google.pl', 1000000, 10, 100, 1);
};
$ingestTimes = ['warm' => [], 'cold' => []];
$queriesPerIngest = [];
$queryCount = 0;
add_filter('query', static function (string $sql) use (&$queryCount): string {
	$queryCount++;

	return $sql;
});

foreach ($trackedForIngest as $index => $row) {
	$snapshotId = $db->insert($t('serp_snapshots'), [
		'public_id' => Ulid::generate(),
		'project_id' => $largeProject,
		'tracked_keyword_id' => (int) $row['id'],
		'market_keyword_id' => (int) $row['market_keyword_id'],
		'context_id' => $contextId,
		'run_id' => $ingestRun,
		'provider' => 'dataforseo',
		'provider_task_id' => sprintf('%08x-0000-1000-8000-%012x', $index, $index),
		'status' => 'submitted',
		'trigger_type' => 'manual',
		'requested_depth' => 100,
		'estimated_cost' => 0.00465,
		'created_at' => $now,
		'submitted_at' => $now,
	]);
	$cold = $index % 2 === 1;
	$serpPage = $page($index, $cold);
	$queryCount = 0;
	$start = microtime(true);
	$store->ingest($snapshots->find($snapshotId), $serpPage, 'duzy.example');
	$ingestTimes[$cold ? 'cold' : 'warm'][] = (microtime(true) - $start) * 1000;
	$queriesPerIngest[] = $queryCount;
}

foreach (['warm' => 'domeny i adresy już w słownikach', 'cold' => 'nowe domeny i adresy (wstawiane do słowników)'] as $key => $label) {
	$times = $ingestTimes[$key];
	sort($times);
	$out(sprintf(
		'- %s: %d pomiarów × 100 wyników — mediana %.1f ms, p95 %.1f ms, maks. %.1f ms na pomiar',
		$label,
		count($times),
		$times[intdiv(count($times), 2)],
		$times[(int) floor(count($times) * 0.95)],
		end($times),
	));
}

$out(sprintf('- zapytania SQL na zapis jednego pomiaru: %d–%d (bez N+1 — niezależnie od liczby wyników)', min($queriesPerIngest), max($queriesPerIngest)));

// Odczyty panelu.
/** @var list<string> $captured */
$captured = [];
add_filter('query', static function (string $sql) use (&$captured): string {
	$captured[] = $sql;

	return $sql;
});

$guard = osf_seo()->get(OsfSeo\Auth\ProjectGuard::class);
$publicId = static fn (int $projectId): string => (string) $db->fetchValue("SELECT public_id FROM `{$t('projects')}` WHERE id = %d", [$projectId]);
$historyContext = $guard->authorizeSystem($publicId($historyProject));
$largeContext = $guard->authorizeSystem($publicId($largeProject));
$service = osf_seo()->get(SerpTrackingService::class);
$competitors = osf_seo()->get(CompetitorService::class);
$uncached = new CompetitorService($competitorRepository, osf_seo()->get(SerpReports::class), osf_seo()->get(SerpTrackingService::class), new Logger(Logger::ERROR, static function (): void {
}));
$reports = osf_seo()->get(SerpReports::class);
$settings = osf_seo()->get(SerpSettingsRepository::class);
$historyKeyword = (string) $db->fetchValue("SELECT public_id FROM `{$t('serp_tracked_keywords')}` WHERE project_id = %d AND last_found = 1 ORDER BY id LIMIT 1", [$historyProject]);
$historySnapshot = (int) $db->fetchValue("SELECT last_snapshot_id FROM `{$t('serp_tracked_keywords')}` WHERE public_id = %s", [$historyKeyword]);
$firstCompetitor = $competitorRepository->active($historyProject)[0]->publicId;
$keywordSample = array_slice($keywordTexts[$largeProject], 0, 50);

$cases = [
	sprintf('Pozycje: lista domyślna (%d fraz, %d konkurentów, średnia GSC)', $keywordsPerProject, $competitorsPerProject) => static fn () => $service->positions($historyContext, PositionsFilters::fromInput([])),
	sprintf('Pozycje: lista %s fraz, strona 1 (wg Pozycji SERP)', number_format($largeKeywords)) => static fn () => $service->positions($largeContext, PositionsFilters::fromInput([])),
	sprintf('Pozycje: %s fraz, ostatnia strona', number_format($largeKeywords)) => static fn () => $service->positions($largeContext, PositionsFilters::fromInput(['page' => (string) (int) ceil($largeKeywords / 50)])),
	sprintf('Pozycje: %s fraz, sortowanie po wolumenie', number_format($largeKeywords)) => static fn () => $service->positions($largeContext, PositionsFilters::fromInput(['sort' => 'volume'])),
	sprintf('Pozycje: %s fraz, filtr TOP10 + wyszukiwanie „fraza 101”', number_format($largeKeywords)) => static fn () => $service->positions($largeContext, PositionsFilters::fromInput(['band' => 'top10', 'q' => 'fraza 101'])),
	sprintf('Pozycje: liczniki (%s fraz)', number_format($largeKeywords)) => static fn () => $service->summary($largeContext),
	sprintf('Szczegóły frazy: historia %d pomiarów + konkurenci + pełne TOP100', $historyWeeks) => static fn () => $service->keyword($historyContext, $historyKeyword),
	'Pełny SERP: TOP100 jednego pomiaru (wyniki + słowniki)' => static fn () => $reports->results($historySnapshot),
	sprintf('Konkurenci: lista %d konkurentów (%d fraz)', $competitorsPerProject, $keywordsPerProject) => static fn () => $competitors->list($historyContext),
	sprintf('Konkurent: szczegóły (%d fraz, bieżący i poprzedni pomiar)', $keywordsPerProject) => static fn () => $competitors->detail($historyContext, $firstCompetitor),
	sprintf('Konkurenci organiczni: %d fraz, strona 1 (bez pamięci podręcznej)', $keywordsPerProject) => static fn () => $uncached->organic($historyContext),
	sprintf('Konkurenci organiczni: %s fraz, strona 1 (bez pamięci podręcznej)', number_format($largeKeywords)) => static fn () => $uncached->organic($largeContext),
	sprintf('Konkurenci organiczni: %s fraz, wg średniej pozycji (bez pamięci podręcznej)', number_format($largeKeywords)) => static fn () => $uncached->organic($largeContext, 1, 'avg_rank'),
	sprintf('Konkurenci organiczni: %s fraz, kolejne wejście (pamięć podręczna, klucz = stan pomiarów)', number_format($largeKeywords)) => static fn () => $competitors->organic($largeContext),
	sprintf('Plan pomiaru bez API (%s fraz)', number_format($largeKeywords)) => static fn () => $service->plan($largeContext),
	sprintf('Harmonogram: projekty z terminem (%d włączonych)', count($projects)) => static fn () => $settings->due(25),
	'Frazy: kolumna Pozycja SERP dla 50 fraz' => static fn () => $service->ranksForKeywords($largeContext, $keywordSample),
	'Rodzina domen konkurenta (prefiks odwróconego hosta)' => static fn () => $reports->familyDomainIds('domena-7.example'),
];

$out();
$out('## Czasy odczytów (mediana z 3 uruchomień)');
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
		is_array($result) && isset($result['rows'], $result['total']) && isset($result['competitors']) => sprintf('%d wierszy, łącznie %s; %d zapytań', count($result['rows']), number_format($result['total']), count($queriesByCase[$label])),
		is_array($result) && isset($result['rows'], $result['total']) => sprintf('%d domen na stronie, łącznie %s', count($result['rows']), number_format($result['total'])),
		is_array($result) && isset($result['history']) => sprintf('%d pomiarów historii, %d wyników TOP', count($result['history']), count($result['results'])),
		is_array($result) && isset($result['stats']) => sprintf('%d fraz, %d adresów', count($result['rows']), count($result['urls'])),
		is_array($result) && isset($result['tracked']) => sprintf('%d fraz, TOP10 %d', $result['tracked'], $result['top10']),
		$result instanceof OsfSeo\Serp\SerpPlan => sprintf('%d zadań w %d zleceniach, maks. %.4f USD', $result->tasks(), $result->posts(), $result->estimatedCost()),
		is_array($result) && array_is_list($result) && isset($result[0]['competitor']) => sprintf('%d konkurentów', count($result)),
		is_array($result) => sprintf('%d pozycji', count($result)),
		default => '—',
	};
	$out(sprintf('| %s | %.1f ms | %s |', $label, $ms, $summary));

	if ($ms > $slowest['ms']) {
		$slowest = ['label' => $label, 'ms' => $ms];
	}
}

// Rezerwacja pełnego pomiaru dużego projektu (zajęcie fraz, przebieg, pomiary i wiersze kosztów) — jednorazowo, potem anulowanie.
$submitter = osf_seo()->get(SerpSubmitter::class);
// Limity kosztów podniesione tylko w tym procesie, żeby zmierzyć pełną rezerwację (żadne żądanie nie jest wysyłane).
putenv('OSF_SEO_DATAFORSEO_DAILY_COST_LIMIT=10000');
putenv('OSF_SEO_DATAFORSEO_MONTHLY_COST_LIMIT=10000');
$captured = [];
$plan = $service->plan($largeContext);
$start = microtime(true);
$queued = $submitter->queue($largeProject, $plan, 'manual', 'manual:benchmark', null);
$queueMs = (microtime(true) - $start) * 1000;
$queriesByCase[sprintf('Rezerwacja pomiaru %s fraz', number_format($largeKeywords))] = array_values(array_unique($captured));
$out(sprintf('| Rezerwacja pomiaru %s fraz (zajęcie fraz, %d paczek, wiersze kosztów; bez API) | %.1f ms | %s, %d zadań |', number_format($largeKeywords), $plan->posts(), $queueMs, $queued['status'], $queued['tasks']));

if ($queued['run_id'] !== null) {
	$submitter->cancelRest((int) $queued['run_id']);
}

$out();
$out(sprintf('Najwolniejszy odczyt: %s — %.1f ms.', $slowest['label'], $slowest['ms']));

$out();
$out('## EXPLAIN');
$explained = [];

foreach ($queriesByCase as $label => $queries) {
	foreach ($queries as $sql) {
		$key = preg_replace('/\d+/', 'N', $sql);

		if (! preg_match('/^\s*SELECT/i', $sql) || isset($explained[$key]) || str_contains($sql, 'information_schema') || str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
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
	foreach (OSF_SEO_SERP_BENCHMARK_TABLES as $table) {
		$db->execute("TRUNCATE TABLE `{$db->table($table)}`");
	}
}
