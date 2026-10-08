<?php

/**
 * Benchmark ekstrakcji Page Intelligence (STEP 17, faza B) na syntetycznym HTML — bez sieci i bez bazy:
 *
 * - dokumenty małe (~20 KB), średnie (~300 KB), duże (~2 MB, domyślny limit pobrania) i ponad limit parsowania (~5 MB → przycięcie),
 *   strona oparta na JS (szkielet + skrypty) oraz strona z bardzo wieloma linkami i nagłówkami,
 * - czas `HtmlExtractor::extract` (mediana z N przebiegów), odcisk treści, rozmiar zapisywanych danych (JSON), szczytowa pamięć,
 *   liczby słów, nagłówków, sekcji i linków po limitach oraz poziom jakości ekstrakcji,
 * - koszt dołożenia snapshotów strony i stron konkurencji do kontekstu AI (`TopicContextAssembler`, budżet 32 KB).
 *
 *   composer test:performance:pages
 *   php tests/Performance/page-extract-benchmark.php [--runs=5]
 */

declare(strict_types=1);

use OsfSeo\Ai\Context\TopicContextAssembler;
use OsfSeo\PageIntelligence\Extract\HtmlExtractor;
use OsfSeo\Tests\Support\AiFakes;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['runs::']);
$runs = max(1, (int) ($options['runs'] ?? 5));

$paragraph = static fn (int $i): string => '<p>Akapit ' . $i . ' opisuje pozycjonowanie stron, audyt SEO, treści i linkowanie wewnętrzne. '
	. 'Zawiera <a href="/oferta/' . ($i % 40) . '/">link wewnętrzny</a> oraz <a href="https://zewnetrzny-' . ($i % 7) . '.example/">link zewnętrzny</a>. '
	. str_repeat('Słowa treści głównej strony usługowej. ', 6) . '</p>';
$document = static function (int $targetBytes, int $linksPerSection = 0) use ($paragraph): string {
	$head = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Pozycjonowanie stron — benchmark</title>'
		. '<meta name="description" content="Syntetyczna strona do pomiaru ekstrakcji."><link rel="canonical" href="https://example.pl/benchmark/">'
		. '<style>' . str_repeat('.a{color:red}', 200) . '</style><script>' . str_repeat('var x=1;', 500) . '</script></head><body>'
		. '<header><nav>' . str_repeat('<a href="/menu/">Menu</a>', 30) . '</nav></header><main><h1>Pozycjonowanie stron</h1>';
	$body = '';
	$i = 0;

	while (strlen($head) + strlen($body) < $targetBytes) {
		$i++;
		$body .= '<section><h' . (2 + $i % 3) . '>Sekcja ' . $i . '</h' . (2 + $i % 3) . '>' . $paragraph($i) . $paragraph($i + 1);

		for ($l = 0; $l < $linksPerSection; $l++) {
			$body .= '<a href="/link-' . $i . '-' . $l . '/">Odnośnik ' . $l . '</a>';
		}

		$body .= '</section>';
	}

	return $head . $body . '</main><footer>' . str_repeat('<a href="/stopka/">Stopka</a>', 20) . '</footer><div class="cookie-banner">Ciasteczka</div></body></html>';
};

$cases = [
	'mały (~20 KB)' => $document(20_000),
	'średni (~300 KB)' => $document(300_000),
	'duży (~2 MB)' => $document(2_000_000),
	'ponad limit parsowania (~5 MB)' => $document(5_000_000),
	'strona JS (szkielet)' => '<!doctype html><html><head><title>App</title>' . str_repeat('<script src="/static/chunk.js"></script>', 12)
		. '<script id="__NEXT_DATA__" type="application/json">' . str_repeat('{"a":1},', 20000) . '</script></head><body><div id="__next"></div><noscript>Włącz JavaScript</noscript></body></html>',
	'wiele linków i nagłówków (~1 MB)' => $document(1_000_000, 25),
];

$extractor = new HtmlExtractor();
$rows = [];

foreach ($cases as $name => $html) {
	gc_collect_cycles();
	memory_reset_peak_usage();
	$base = memory_get_usage();
	$times = [];
	$extraction = null;

	for ($run = 0; $run < $runs; $run++) {
		$started = hrtime(true);
		$extraction = $extractor->extract($html, 'https://example.pl/benchmark/', 'utf-8', []);
		$hash = $extraction->contentHash();
		$times[] = (hrtime(true) - $started) / 1e6;
	}

	sort($times);
	$data = (string) json_encode($extraction->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$rows[] = [
		'case' => $name,
		'input_kb' => round(strlen($html) / 1024),
		'median_ms' => round($times[intdiv(count($times), 2)], 1),
		'max_ms' => round(max($times), 1),
		'peak_mb' => round((memory_get_peak_usage() - $base) / 1048576, 1),
		'stored_kb' => round(strlen($data) / 1024, 1),
		'words' => $extraction->content['word_count'],
		'headings' => count($extraction->headings['list']) . '/' . $extraction->headings['total'],
		'sections' => count($extraction->content['sections']),
		'links' => $extraction->links['counts']['total'],
		'links_kept' => count($extraction->links['internal']) + count($extraction->links['external']),
		'quality' => $extraction->quality['level'],
		'truncated' => implode(',', $extraction->limits['truncated']) ?: '—',
	];
}

$widths = [];

foreach ($rows as $row) {
	foreach ($row as $key => $value) {
		$widths[$key] = max($widths[$key] ?? mb_strlen($key), mb_strlen((string) $value));
	}
}

$line = static fn (array $values): string => implode(' | ', array_map(static fn (string $key): string => str_pad((string) $values[$key], $widths[$key] + strlen((string) $values[$key]) - mb_strlen((string) $values[$key])), array_keys($widths)));
echo 'Page Intelligence — extraction benchmark (', $runs, ' runs, PHP ', PHP_VERSION, ")\n\n";
echo $line(array_combine(array_keys($widths), array_keys($widths))), "\n";

foreach ($rows as $row) {
	echo $line($row), "\n";
}

// Kontekst AI: strona projektu + 5 stron konkurencji z największego dokumentu (limity elementów i budżet 32 KB).
$big = $cases['wiele linków i nagłówków (~1 MB)'];
$competitors = [];

for ($rank = 1; $rank <= 5; $rank++) {
	$competitors[] = AiFakes::pageEvidence('https://wynik-' . $rank . '.example/seo/', $big, 'fresh', [], sprintf('01M4BRH20000000000000000%02d', $rank))
		+ ['serp' => ['keyword_id' => AiFakes::LEADER, 'rank_group' => $rank, 'checked_at' => '2026-01-10 18:02:30']];
}

$without = (new TopicContextAssembler())->assemble(AiFakes::source());
$source = AiFakes::source(source: ['pages' => ['project' => AiFakes::pageEvidence(html: $big), 'competitors' => $competitors, 'competitors_total' => 5]]);
$started = hrtime(true);
$with = (new TopicContextAssembler())->assemble($source);
$elapsed = (hrtime(true) - $started) / 1e6;

printf(
	"\nAI context: %d bytes without pages, %d bytes with project page + 5 competitor pages (max %d, within budget: %s), reductions: %s, assembled in %.1f ms\n",
	$without->bytes(),
	$with->bytes(),
	TopicContextAssembler::MAX_BYTES,
	$with->withinBudget() ? 'yes' : 'no',
	implode(', ', $with->toArray()['limits']['reductions']) ?: 'none',
	$elapsed,
);
