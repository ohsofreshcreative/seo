<?php

/**
 * Benchmark analiz rekomendacji AI (STEP 17, faza C) — bez żadnego płatnego wywołania i bez sieci (wyłącznie dostawca testowy):
 *
 * - A: mały temat, jedna strona projektu (bez konkurencji) — optymalizacja strony,
 * - B: średni temat, strona projektu i 3 strony konkurencji — luki treści,
 * - C: duży temat (40 fraz, długie strony, 30 innych tematów) — kontekst redukowany do 32 KB, wszystkie trzy typy,
 *   A–C bez bazy: gotowość, budowa kontekstu, instrukcje i wejście modelu, walidacja odpowiedzi testowej i odpowiedzi maksymalnej
 *   (mediana z N przebiegów), rozmiar kontekstu, wejścia i wyniku, szczytowa pamięć,
 * - D: duży projekt z wieloma zapisanymi analizami (OSOBNA baza testowa, jak testy integracyjne — czyści tabele projektów, GSC,
 *   Strategii, stron i AI): gotowość, plan, generowanie (dostawca testowy, zapis historii), odczyt wyniku z aktualnością, lista
 *   historii z aktualnością — czas, zapytania SQL (i zapisujące), szczytowa pamięć; EXPLAIN zapytań historii. Nigdy nie wskazuj bazy strony.
 *
 *   composer test:performance:ai
 *   php tests/Performance/ai-analysis-benchmark.php [--runs=5] [--history=5000] [--other-projects=10] [--no-db]
 */

declare(strict_types=1);

use OsfSeo\Ai\AiAnalysisService;
use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Analysis\ReadinessEvaluator;
use OsfSeo\Ai\Budget\AiPricing;
use OsfSeo\Ai\Context\TopicContextAssembler;
use OsfSeo\Ai\Contract\RecommendationContract;
use OsfSeo\Ai\Contract\RecommendationValidator;
use OsfSeo\Ai\Prompt\AnalysisPrompts;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Run\AiRunRepository;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migrator;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\PageIntelligence\Extract\HtmlExtractor;
use OsfSeo\PageIntelligence\PageIntelligenceRepository;
use OsfSeo\PageIntelligence\PageTarget;
use OsfSeo\Strategy\StrategyRefresher;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\Topics\TopicFilters;
use OsfSeo\Support\Logger;
use OsfSeo\Support\SystemClock;
use OsfSeo\Support\Ulid;
use OsfSeo\Tests\Support\AiFakes;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['runs::', 'history::', 'other-projects::', 'no-db']);
$runs = max(1, (int) ($options['runs'] ?? 5));
$history = max(100, (int) ($options['history'] ?? 5000));
$otherProjects = max(0, (int) ($options['other-projects'] ?? 10));

$out = static function (string $line = ''): void {
	fwrite(STDOUT, $line . "\n");
};
$median = static function (callable $callback) use ($runs): array {
	$times = [];
	$value = null;

	for ($i = 0; $i < $runs; $i++) {
		$start = hrtime(true);
		$value = $callback();
		$times[] = (hrtime(true) - $start) / 1e6;
	}

	sort($times);

	return [$value, $times[intdiv(count($times), 2)]];
};

/**
 * Odpowiedź maksymalna dla typu: wszystkie listy z najwyższą dozwoloną liczbą elementów i długimi (ale poprawnymi) tekstami.
 *
 * @param array<string, mixed> $sample
 * @return array<string, mixed>
 */
$maximal = static function (array $sample, string $type): array {
	$text = static fn (string $prefix, int $i, int $words): string => $prefix . ' ' . $i . ': ' . implode(' ', array_map(static fn (int $w): string => 'element' . ($w % 9) . chr(97 + ($i + $w) % 26), range(1, $words)));
	$refs = $sample['recommendations'][0]['evidence_refs'];
	$sample['findings'] = array_map(static fn (int $i): array => ['id' => 'F' . $i, 'kind' => 'observation', 'title' => $text('Ustalenie', $i, 8), 'explanation' => $text('Wyjaśnienie', $i, 80), 'evidence_refs' => $refs, 'basis' => $refs === [] ? 'hypothesis' : 'inference', 'confidence' => 'low'], range(1, RecommendationContract::MAX_ITEMS));
	$sample['recommendations'] = array_map(static fn (int $i): array => array_replace($sample['recommendations'][0], ['id' => 'R' . $i, 'title' => $text('Rekomendacja', $i, 8), 'description' => $text('Opis', $i, 80), 'rationale' => $text('Uzasadnienie', $i, 60), 'finding_ids' => ['F' . $i]]), range(1, RecommendationContract::MAX_ITEMS));
	$sample['missing_information'] = array_map(static fn (int $i): array => ['item' => $text('Brak', $i, 10), 'why_it_matters' => $text('Znaczenie', $i, 20)], range(1, RecommendationContract::MAX_ITEMS));
	$sample['manual_checks'] = array_map(static fn (int $i): array => ['check' => $text('Kontrola', $i, 10), 'reason' => $text('Powód', $i, 20), 'evidence_refs' => $refs], range(1, RecommendationContract::MAX_ITEMS));
	$sample['warnings'] = array_map(static fn (int $i): string => $text('Ostrzeżenie', $i, 20), range(1, RecommendationContract::MAX_ITEMS));

	if ($type === AnalysisType::CONTENT_GAP) {
		$sample['content_topics'] = array_map(static fn (int $i): array => ['label' => $text('Temat', $i, 5), 'status' => 'potentially_missing', 'evidence_refs' => $refs, 'basis' => 'hypothesis', 'confidence' => 'low', 'note' => $text('Uwaga', $i, 20)], range(1, RecommendationContract::SECTION_ITEMS['content_topics']));
	}

	if ($type === AnalysisType::NEW_PAGE_BRIEF) {
		$sample['content_outline']['sections'] = array_map(static fn (int $i): array => ['level' => 2 + $i % 2, 'heading' => $text('Sekcja', $i, 5), 'scope' => $text('Zakres', $i, 40), 'evidence_refs' => $refs, 'basis' => $refs === [] ? 'hypothesis' : 'inference'], range(1, RecommendationContract::SECTION_ITEMS['outline_sections']));
		$sample['user_questions'] = array_map(static fn (int $i): string => $text('Pytanie', $i, 10), range(1, RecommendationContract::MAX_ITEMS));
		$sample['client_data_needed'] = array_map(static fn (int $i): string => $text('Dane', $i, 10), range(1, RecommendationContract::MAX_ITEMS));
	}

	return $sample;
};

$out('# Whack-a-mole — benchmark analiz rekomendacji AI (PHP ' . PHP_VERSION . ')');
$out();
$out(sprintf('Bez płatnych wywołań i bez sieci (dostawca testowy). Mediana z %d przebiegów. Budżet kontekstu %d B.', $runs, TopicContextAssembler::MAX_BYTES));
$out();

// Scenariusze A–C (bez bazy).
$keywords = AiFakes::source()['context']['keywords'];
$manyKeywords = [];

for ($i = 0; $i < 40; $i++) {
	$keyword = $keywords[$i === 0 ? 0 : 1];
	$keyword['id'] = sprintf('01M4BRGWN1%016d', $i);
	$keyword['keyword'] = $i === 0 ? 'pozycjonowanie stron' : 'pozycjonowanie stron wariant ' . $i . ' z długim dopiskiem frazy';
	$keyword['role'] = $i === 0 ? 'leader' : 'member';
	$manyKeywords[] = $keyword;
}

$longCompetitors = AiFakes::pages(null, 3);

foreach ($longCompetitors['competitors'] as $index => $page) {
	$longCompetitors['competitors'][$index] = AiFakes::pageEvidence($page['url'], AiFakes::pageHtml('Konkurent ' . $index . ' — bardzo długa strona', 'Konkurent ' . $index, 60), 'fresh', [], $page['snapshot']['id'], '2026-01-11 09:00:00') + ['serp' => $page['serp']];
}

$longCompetitors['project'] = AiFakes::pageEvidence(html: AiFakes::pageHtml('Długa strona projektu', 'Pozycjonowanie stron — pełna oferta', 60));
$site = array_map(static fn (int $i): array => ['label' => 'temat witryny ' . $i . ' z dłuższą etykietą', 'action' => ['optimize', 'monitor', 'create'][$i % 3], 'target_url' => $i % 3 === 2 ? null : 'https://example.pl/oferta/temat-' . $i . '/'], range(1, 30));
$scenarios = [
	'A — mały temat, 1 strona' => [[AnalysisType::PAGE_OPTIMIZATION], [], ['pages' => AiFakes::pages(AiFakes::pageEvidence(html: AiFakes::pageHtml('Strona', 'Pozycjonowanie', 1)), 0)], [AiFakes::SITE_TOPICS[0]]],
	'B — średni temat, 3 konkurentów' => [[AnalysisType::CONTENT_GAP, AnalysisType::PAGE_OPTIMIZATION], [], ['pages' => AiFakes::pages(null, 3)], AiFakes::SITE_TOPICS],
	'C — duży temat (limit 32 KB)' => [AnalysisType::RECOMMENDATIONS, ['keywords' => $manyKeywords], ['pages' => $longCompetitors], $site],
];
$create = ['decision' => ['action' => 'create', 'action_label' => 'Kandydat na nową stronę', 'reason' => 'no_page', 'basis' => [], 'checks' => []], 'target' => ['state' => 'none', 'url' => null]];
$rows = [];

foreach ($scenarios as $label => [$types, $context, $source, $topics]) {
	foreach ($types as $type) {
		$ctx = $type === AnalysisType::NEW_PAGE_BRIEF ? $context + $create : $context;
		$raw = AiFakes::source($ctx, $source);
		memory_reset_peak_usage();
		$base = memory_get_usage();
		[$readiness, $readinessMs] = $median(static fn () => (new ReadinessEvaluator())->evaluate($raw, $type));
		$full = AiFakes::analysisSource($type, $ctx, $source, false, $topics);
		[$aiContext, $contextMs] = $median(static fn () => (new TopicContextAssembler())->assemble($full));
		[$input, $promptMs] = $median(static fn (): string => AnalysisPrompts::input($type, $aiContext, null));
		$tokens = AiPricing::estimateInputTokens(AnalysisPrompts::instructions($type), $input, (string) json_encode(RecommendationContract::schema()));
		$sample = AiFakes::recommendation($aiContext);
		$sampleJson = (string) json_encode($sample, JSON_UNESCAPED_UNICODE);
		[$sampleResult, $validateMs] = $median(static fn () => (new RecommendationValidator())->validate($sampleJson, $aiContext));
		$maxJson = (string) json_encode($maximal($sample, $type), JSON_UNESCAPED_UNICODE);
		[$maxResult, $validateMaxMs] = $median(static fn () => (new RecommendationValidator())->validate($maxJson, $aiContext));
		$rows[] = [
			'scenario' => $label,
			'type' => $type,
			'readiness' => $readiness->state,
			'readiness_ms' => $readinessMs,
			'context_ms' => $contextMs,
			'context_b' => $aiContext->bytes(),
			'reductions' => implode(', ', $aiContext->body['limits']['reductions']) ?: '—',
			'refs' => count($aiContext->refs()),
			'input_b' => strlen($input),
			'tokens' => $tokens,
			'prompt_ms' => $promptMs,
			'validate_ms' => $validateMs,
			'validate_max_ms' => $validateMaxMs,
			'result_b' => strlen((string) json_encode($sampleResult->result, JSON_UNESCAPED_UNICODE)),
			'result_max_b' => strlen((string) json_encode($maxResult->result ?? [], JSON_UNESCAPED_UNICODE)),
			'valid' => ($sampleResult->valid() ? 'tak' : 'NIE') . ' / ' . ($maxResult->valid() ? 'tak' : 'NIE: ' . implode(',', array_unique(array_column($maxResult->errors, 'code')))),
			'peak_mb' => (memory_get_peak_usage() - $base) / 1048576,
		];
	}
}

$out('## A–C: kontekst, instrukcje i walidacja (bez bazy)');
$out();
$out('| Scenariusz | Typ | Gotowość | Gotowość ms | Kontekst ms | Kontekst B | Redukcje | Odwołania | Wejście B | Tokeny (szac.) | Wejście ms | Walidacja ms (test / maks.) | Wynik B (test / maks.) | Poprawna (test / maks.) | Pamięć MB |');
$out('|---|---|---|---:|---:|---:|---|---:|---:|---:|---:|---:|---:|---|---:|');

foreach ($rows as $row) {
	$out(sprintf(
		'| %s | %s | %s | %.2f | %.2f | %s | %s | %d | %s | %s | %.2f | %.2f / %.2f | %s / %s | %s | %.1f |',
		$row['scenario'], $row['type'], $row['readiness'], $row['readiness_ms'], $row['context_ms'], number_format($row['context_b']), $row['reductions'], $row['refs'],
		number_format($row['input_b']), number_format($row['tokens']), $row['prompt_ms'], $row['validate_ms'], $row['validate_max_ms'], number_format($row['result_b']),
		number_format($row['result_max_b']), $row['valid'], $row['peak_mb'],
	));
}

$out();

if (isset($options['no-db'])) {
	$out('Scenariusz D pominięty (--no-db).');

	exit(0);
}

// Scenariusz D (osobna baza testowa).
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Integration/install.php'), $code);

if ($code !== 0) {
	fwrite(STDERR, "WordPress test installation failed. Check OSF_SEO_TEST_DB_* variables.\n");
	exit(1);
}

require dirname(__DIR__) . '/Integration/wp-tests-config.php';
define('WP_CLI', true); // kontekst systemowy projektu (ProjectGuard::authorizeSystem)
require ABSPATH . 'wp-settings.php';
require dirname(__DIR__, 2) . '/osf-seo.php';

$httpAttempts = 0;
add_filter('pre_http_request', static function () use (&$httpAttempts): WP_Error {
	$httpAttempts++;

	return new WP_Error('osf_seo_benchmark', 'HTTP disabled in the AI benchmark.');
}, 1);

$db = Connection::fromGlobals();
(new Migrator($db, new Logger(Logger::ERROR, static function (): void {
})))->migrate();
$t = static fn (string $name): string => $db->table($name);

foreach (['projects', 'keywords', 'pages', 'gsc_query_daily', 'gsc_query_page_daily', 'gsc_site_daily', 'sync_state', 'sync_runs', 'market_keywords', 'strategy_settings', 'strategy_keywords', 'strategy_topics', 'strategy_topic_events', 'page_targets', 'page_snapshots', 'page_fetches', 'page_serp_links', 'ai_runs', 'ai_run_payloads'] as $table) {
	$db->execute("TRUNCATE TABLE `{$t($table)}`");
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

$clock = new SystemClock();
$now = gmdate('Y-m-d H:i:s');
$latest = gmdate('Y-m-d', strtotime('-3 days'));
$market = new Market('dataforseo', 'pl', 'pl', 2616, 'pl', 'Polska', 'polski');
$metrics = new MarketMetricsRepository($db, $clock);

/** Projekt z frazami GSC (okno 28 dni) — tematy Strategii po przeliczeniu. */
$createProject = static function (string $slug, int $size) use ($db, $t, $metrics, $market, $now, $latest): array {
	$domain = $slug . '.example';
	$publicId = Ulid::generate();
	$projectId = $db->insert($t('projects'), [
		'public_id' => $publicId, 'name' => 'Benchmark ' . $slug, 'domain' => $domain, 'country' => 'pl', 'language' => 'pl',
		'gsc_property' => 'sc-domain:' . $domain, 'gsc_permission' => 'siteOwner', 'gsc_data_property' => 'sc-domain:' . $domain,
		'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
	]);
	$code = 'k' . base_convert((string) crc32($slug), 10, 36);
	$texts = array_map(static fn (int $i): string => sprintf('%s usługa %d wariant %d', $code, intdiv($i, 4), $i % 4), range(1, $size));
	$metrics->ensure($market, $texts);
	$db->execute("UPDATE `{$t('market_keywords')}` SET search_volume = 10 + CRC32(id) %% 3000, keyword_difficulty = CRC32(CONCAT('k', id)) %% 100, search_intent = 'commercial' WHERE keyword LIKE %s", [$db->escapeLike($code . ' ') . '%']);
	$db->execute(
		"INSERT INTO `{$t('keywords')}` (project_id, keyword, keyword_hash, market_key, first_seen, last_seen, created_at)
		SELECT %d, keyword, UNHEX(MD5(keyword)), keyword_key, %s, %s, %s FROM `{$t('market_keywords')}` WHERE keyword LIKE %s ORDER BY id",
		[$projectId, $latest, $latest, $now, $db->escapeLike($code . ' ') . '%'],
	);
	$pages = new BulkInsert($db, $t('pages'), ['project_id', 'url', 'url_hash', 'path', 'first_seen', 'last_seen', 'created_at'], ['%d', '%s', 'UNHEX(%s)', '%s', '%s', '%s', '%s']);

	for ($p = 0; $p <= intdiv($size, 8); $p++) {
		$url = 'https://' . $domain . '/temat-' . $p . '/';
		$pages->add([$projectId, $url, md5($url), '/temat-' . $p . '/', $latest, $latest, $now]);
	}

	$pages->flush();
	$pageBase = (int) $db->fetchValue("SELECT MIN(id) FROM `{$t('pages')}` WHERE project_id = %d", [$projectId]);
	$keywordBase = (int) $db->fetchValue("SELECT MIN(id) FROM `{$t('keywords')}` WHERE project_id = %d", [$projectId]);

	for ($day = 0; $day < 28; $day += 3) {
		$date = gmdate('Y-m-d', strtotime($latest . ' -' . $day . ' days'));

		foreach (['gsc_query_daily' => false, 'gsc_query_page_daily' => true] as $table => $withPage) {
			$db->execute(
				"INSERT INTO `{$t($table)}` (project_id, date, keyword_id" . ($withPage ? ', page_id' : '') . ", clicks, impressions, position_sum)
				SELECT %d, %s, k.id" . ($withPage ? ', %d + (k.id - %d) DIV 8' : '') . ", k.id %% 3, 5 + k.id %% 40, (5 + k.id %% 40) * (1 + k.id %% 45) FROM `{$t('keywords')}` k WHERE k.project_id = %d",
				$withPage ? [$projectId, $date, $pageBase, $keywordBase, $projectId] : [$projectId, $date, $projectId],
			);
		}
	}

	foreach (['query', 'query_page'] as $dataset) {
		$db->insert($t('sync_state'), ['project_id' => $projectId, 'dataset' => $dataset, 'newest_date' => $latest, 'oldest_date' => gmdate('Y-m-d', strtotime($latest . ' -480 days')), 'last_success_at' => $now, 'updated_at' => $now]);
	}

	return ['id' => $projectId, 'public_id' => $publicId, 'domain' => $domain];
};

$plugin = osf_seo();
$refresher = $plugin->get(StrategyRefresher::class);
$guard = $plugin->get(ProjectGuard::class);
$strategy = $plugin->get(StrategyService::class);
$ai = $plugin->get(AiAnalysisService::class);
$aiRuns = new AiRunRepository($db, $clock);

$out('## D — duży projekt z wieloma zapisanymi analizami (osobna baza: ' . $db->fetchValue('SELECT VERSION()') . ')');
$out();
$start = microtime(true);
$main = $createProject('historia', 400);
$refresher->refresh($main['id'], true);
$context = $guard->authorizeSystem($main['public_id']);
$topics = $strategy->topics($context, new TopicFilters(status: 'all', includeMonitor: true, perPage: 200))['rows'];
$withTarget = array_values(array_filter($topics, static fn ($topic): bool => $topic->targetUrl !== null));
$topic = $withTarget[0] ?? throw new RuntimeException('No topic with a target page.');

// Snapshot strony docelowej zapisany bezpośrednio (benchmark bez HTTP; ekstrakcja prawdziwym ekstraktorem).
$pagesRepo = new PageIntelligenceRepository($db, $clock);
$target = $pagesRepo->ensureTarget($main['id'], (string) $topic->targetUrl, (string) parse_url((string) $topic->targetUrl, PHP_URL_HOST), PageTarget::KIND_PROJECT, PageTarget::SOURCE_TOPIC, $topic->id, null);
$html = AiFakes::pageHtml('Strona tematu', 'Temat benchmarku', 8);
$extraction = (new HtmlExtractor())->extract($html, (string) $topic->targetUrl, 'utf-8', []);
$snapshot = $pagesRepo->createSnapshot($target, [
	'extractor_version' => HtmlExtractor::VERSION, 'fetched_at' => $now, 'last_seen_at' => $now, 'http_status' => 200, 'content_type' => 'text/html',
	'charset' => 'utf-8', 'final_url' => (string) $topic->targetUrl, 'bytes' => strlen($html), 'fetch_ms' => 100, 'body_hash' => hash('sha256', $html),
	'content_hash' => $extraction->contentHash(), 'title' => $extraction->title(), 'word_count' => (int) $extraction->content['word_count'],
	'h1_count' => 1, 'headings_count' => (int) $extraction->headings['total'], 'links_internal' => 2, 'links_external' => 1,
	'content_quality' => (string) $extraction->quality['level'], 'indexability' => (string) $extraction->technical['indexability'],
	'canonical_status' => (string) $extraction->technical['canonical_status'], 'data' => (string) json_encode($extraction->toArray(), JSON_UNESCAPED_UNICODE),
]);
$pagesRepo->recordAttempt($target, PageTarget::STATUS_OK, null, 200, (string) $topic->targetUrl, $snapshot->id);

// Historia: zapisane analizy projektu (wszystkie tematy i typy) i innych projektów — z danymi wejścia i wyniku.
$sampleContext = $ai->analysisContext($context, $topic->publicId, AnalysisType::PAGE_OPTIMIZATION);
$input = (string) json_encode(['focus' => null, 'context' => $sampleContext->toArray()], JSON_UNESCAPED_UNICODE);
$result = (string) json_encode(AiFakes::recommendation($sampleContext), JSON_UNESCAPED_UNICODE);
$seed = static function (int $projectId, array $topicIds, int $count) use ($db, $t, $input, $result): void {
	$runRows = new BulkInsert($db, $t('ai_runs'), ['public_id', 'project_id', 'topic_id', 'task', 'provider', 'model', 'paid', 'prompt_version', 'context_version', 'contract_version', 'context_fingerprint', 'evidence_fingerprint', 'plan_fingerprint', 'status', 'trigger_type', 'created_at', 'finished_at', 'readiness', 'cost_basis', 'validation_errors'], ['%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', 'UNHEX(%s)', 'UNHEX(%s)', 'UNHEX(%s)', '%s', '%s', '%s', '%s', '%s', '%s', '%d'], maxRows: 200);

	for ($i = 0; $i < $count; $i++) {
		$type = AnalysisType::RECOMMENDATIONS[$i % 3];
		$created = gmdate('Y-m-d H:i:s', time() - ($count - $i) * 600);
		$runRows->add([Ulid::generate(), $projectId, $topicIds[$i % count($topicIds)], $type, 'fake', FakeProvider::MODEL, 0, AnalysisPrompts::version($type), 3, 2, hash('sha256', 'c' . $projectId . ':' . $i), hash('sha256', 'e' . $projectId . ':' . $i), hash('sha256', 'p' . $projectId . ':' . $i), 'succeeded', 'cli', $created, $created, 'ready', 'free', 0]);
	}

	$runRows->flush();
	$db->execute(
		"INSERT INTO `{$t('ai_run_payloads')}` (run_id, project_id, input, output_raw, result, validation, input_hash)
		SELECT r.id, r.project_id, %s, %s, %s, '[]', UNHEX(SHA2(r.public_id, 256)) FROM `{$t('ai_runs')}` r
		LEFT JOIN `{$t('ai_run_payloads')}` p ON p.run_id = r.id WHERE r.project_id = %d AND p.run_id IS NULL",
		[$input, $result, $result, $projectId],
	);
};
$seed($main['id'], array_map(static fn ($row): int => $row->id, $topics), $history);

for ($i = 1; $i <= $otherProjects; $i++) {
	$other = $createProject('inny-' . $i, 40);
	$seed($other['id'], [$i], intdiv($history, 10));
}

$db->execute("ANALYZE TABLE `{$t('ai_runs')}`, `{$t('ai_run_payloads')}`");
$out(sprintf(
	'- dane: projekt z %d tematami Strategii, %s zapisanych analiz projektu (%s KB wejścia i %s KB wyniku na analizę), %d innych projektów po %s analiz; ai_runs %s wierszy, przygotowanie %.1f s',
	count($topics),
	number_format($history),
	number_format(strlen($input) / 1024, 1),
	number_format(strlen($result) / 1024, 1),
	$otherProjects,
	number_format(intdiv($history, 10)),
	number_format((int) $db->fetchValue("SELECT COUNT(*) FROM `{$t('ai_runs')}`")),
	microtime(true) - $start,
));
$out();

$measure = static function (callable $callback) use ($wpdb, &$writes): array {
	$queries = $wpdb->num_queries;
	$writesBefore = $writes;
	memory_reset_peak_usage();
	$base = memory_get_usage();
	$start = hrtime(true);
	$value = $callback();

	return [$value, ['ms' => (hrtime(true) - $start) / 1e6, 'queries' => $wpdb->num_queries - $queries, 'writes' => $writes - $writesBefore, 'peak_mb' => (memory_get_peak_usage() - $base) / 1048576]];
};
$steps = [];
[, $steps['Gotowość (ai:readiness)']] = $measure(static fn () => $ai->readiness($context, $topic->publicId, AnalysisType::PAGE_OPTIMIZATION));
[$plan, $steps['Plan (ai:plan --type)']] = $measure(static fn () => $ai->planAnalysis($context, $topic->publicId, AnalysisType::PAGE_OPTIMIZATION));
[$run, $steps['Generowanie (dostawca testowy, zapis historii)']] = $measure(static fn () => $ai->generate($context, $topic->publicId, AnalysisType::PAGE_OPTIMIZATION, approvedPlan: $plan->fingerprint(), repeat: true));
[, $steps['Wynik z aktualnością (ai:show)']] = $measure(static fn () => $ai->show($context, $run->publicId));
[$latestRuns, $steps['Historia: 20 najnowszych (ai:runs)']] = $measure(static fn () => $ai->runs($context));
[, $steps['Historia tematu (ai:runs --topic)']] = $measure(static fn () => $ai->runs($context, $topic->publicId));
[, $steps['Aktualność 20 wyników (ai:runs --check-stale)']] = $measure(static fn () => $ai->freshness($context, $latestRuns));
[, $steps['Duplikat planu (indeks project_plan)']] = $measure(static fn () => $aiRuns->succeededWithPlan($main['id'], $plan->fingerprint()));
[, $steps['Budżet AI (wydatki projektu w miesiącu)']] = $measure(static fn () => $ai->budget($context));

$out('| Krok | Czas ms | Zapytania SQL | Zapisujące | Pamięć MB |');
$out('|---|---:|---:|---:|---:|');

foreach ($steps as $label => $step) {
	$out(sprintf('| %s | %.1f | %d | %d | %.1f |', $label, $step['ms'], $step['queries'], $step['writes'], $step['peak_mb']));
}

$out();
$out(sprintf('Wynik generowania: %s, gotowość %s, kontekst %s B; żądania HTTP: %d.', $run->status, (string) $run->readiness, number_format($plan->context->bytes()), $httpAttempts));
$out();
$out('### EXPLAIN zapytań historii');
$out();
$explain = static function (string $label, string $sql, array $params) use ($db, $out): void {
	$rows = $db->fetchAll('EXPLAIN ' . $sql, $params);
	$out(sprintf('- %s: %s', $label, implode('; ', array_map(static fn (array $row): string => sprintf('%s key=%s rows=%s %s', $row['table'], $row['key'] ?? '—', $row['rows'] ?? '?', $row['Extra'] ?? ''), $rows))));
};
$explain('lista projektu', "SELECT r.id FROM `{$t('ai_runs')}` r WHERE r.project_id = %d ORDER BY r.created_at DESC, r.id DESC LIMIT 20", [$main['id']]);
$explain('lista tematu', "SELECT r.id FROM `{$t('ai_runs')}` r WHERE r.project_id = %d AND r.topic_id = %d ORDER BY r.created_at DESC, r.id DESC LIMIT 20", [$main['id'], $topic->id]);
$explain('duplikat planu', "SELECT r.id FROM `{$t('ai_runs')}` r WHERE r.project_id = %d AND r.plan_fingerprint = UNHEX(%s) AND r.status = 'succeeded' ORDER BY r.id DESC LIMIT 1", [$main['id'], $plan->fingerprint()]);
$explain('uruchomienie w toku', "SELECT r.id FROM `{$t('ai_runs')}` r WHERE r.project_id = %d AND r.topic_id = %d AND r.task = %s AND r.status IN ('reserved', 'running') ORDER BY r.id DESC LIMIT 1", [$main['id'], $topic->id, AnalysisType::PAGE_OPTIMIZATION]);
$explain('wydatki projektu', "SELECT COALESCE(SUM(COALESCE(actual_cost, reserved_cost)), 0) FROM `{$t('ai_runs')}` WHERE paid = 1 AND created_at >= %s AND project_id = %d", [gmdate('Y-m-01 00:00:00'), $main['id']]);

// Scenariusz E (faza D/E): ekrany panelu na tych samych danych — wywołania usług dokładnie tak, jak robią to kontrolery motywu.
$workspace = $plugin->get(\OsfSeo\Ai\Workspace\AiWorkspaceService::class);
$pageService = $plugin->get(\OsfSeo\PageIntelligence\PageIntelligenceService::class);
$panel = [];
[, $panel['A. Szczegóły tematu (widok Strategii + sekcja AI)']] = $measure(static function () use ($strategy, $workspace, $context, $topic): array {
	$view = $strategy->topicView($context, $topic->publicId);

	return $workspace->topicSectionFromView($context, $view, $strategy->panelState($context));
});
[, $panel['B. Przygotowanie analizy (typy, gotowość, plan, koszt)']] = $measure(static fn (): array => $workspace->prepare($context, $topic->publicId, AnalysisType::PAGE_OPTIMIZATION));
[, $panel['C. Historia analiz (strona 1 z 20 wierszami)']] = $measure(static fn (): array => $workspace->history($context, []));
[, $panel['C2. Historia analiz (strona 100)']] = $measure(static fn (): array => $workspace->history($context, [], 100));
[, $panel['D. Raport AI (z dokładną aktualnością)']] = $measure(static fn (): array => $workspace->report($context, $run->publicId));
[, $panel['E. Lista stron']] = $measure(static fn (): array => $pageService->list($context, []));
[, $panel['F. Szczegóły kopii strony']] = $measure(static fn (): array => $pageService->pageView($context, $target->publicId));
[, $panel['G. Status analizy (odpytywanie co 5 s)']] = $measure(static fn (): array => $workspace->runStatus($context, $run->publicId));

$out();
$out('## E — ekrany panelu (te same dane, wywołania jak w kontrolerach)');
$out();
$out('| Ekran | Czas ms | Zapytania SQL | Zapisujące | Pamięć MB |');
$out('|---|---:|---:|---:|---:|');

foreach ($panel as $label => $step) {
	$out(sprintf('| %s | %.1f | %d | %d | %.1f |', $label, $step['ms'], $step['queries'], $step['writes'], $step['peak_mb']));
}

$out();
$out(sprintf('Żądania HTTP w całym benchmarku: %d.', $httpAttempts));
