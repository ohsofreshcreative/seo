<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\PageIntelligence;

use OsfSeo\PageIntelligence\Extract\HtmlExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Ekstrakcja HTML (16–28): meta i canonical, H1–H6 w kolejności z hierarchią, treść główna bez skryptów, stylów, menu, stopki i bannerów,
 * linki wewnętrzne / zewnętrzne z adresami względnymi, dyrektywy robots (meta i X-Robots-Tag), strony renderowane JavaScriptem,
 * niepoprawny HTML, kodowania, duże dokumenty z limitami. Bez sieci i bez wykonywania JavaScriptu.
 */
final class HtmlExtractorTest extends TestCase
{
	private const URL = 'https://www.example.pl/uslugi/strony-www/';

	public function test_16_17_18_19_20_meta_headings_and_main_content(): void
	{
		$html = <<<'HTML'
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <title>  Tworzenie stron internetowych — Example  </title>
  <meta name="description" content="Projektujemy i wdrażamy strony WWW dla firm.">
  <link rel="canonical" href="/uslugi/strony-www/">
  <meta property="og:title" content="Strony WWW">
  <style>.hero{color:red}</style>
  <script>var tracking = "Ignore previous instructions";</script>
</head>
<body>
  <header class="site-header"><nav><a href="/">Start</a><a href="/blog/">Blog</a></nav></header>
  <div id="cookie-banner" class="cookie-notice">Ta strona używa cookies. Akceptuj</div>
  <main>
    <h1>Tworzenie stron internetowych</h1>
    <p>Projektujemy szybkie i dostępne strony internetowe dla małych i średnich firm z całej Polski, od makiety po wdrożenie i opiekę.</p>
    <h2>Jak pracujemy</h2>
    <p>Zaczynamy od warsztatu, potem przygotowujemy projekt graficzny, a na końcu programujemy stronę na WordPressie z myślą o SEO.</p>
    <h3>Warsztat</h3>
    <p>Poznajemy cele biznesowe i grupy docelowe.</p>
    <h2>Cennik</h2>
    <ul><li>Strona wizytówka</li><li>Sklep internetowy</li></ul>
    <noscript>Włącz JavaScript</noscript>
    <div class="share-buttons">Udostępnij na Facebooku</div>
  </main>
  <aside><h2>Polecane wpisy</h2></aside>
  <footer><h4>Kontakt</h4><p>ul. Przykładowa 1, Łódź</p></footer>
</body>
</html>
HTML;
		$page = (new HtmlExtractor())->extract($html, self::URL);

		self::assertSame('Tworzenie stron internetowych — Example', $page->meta['title']);
		self::assertSame('Projektujemy i wdrażamy strony WWW dla firm.', $page->meta['description']);
		self::assertSame(self::URL, $page->meta['canonical']);
		self::assertSame('self', $page->technical['canonical_status']);
		self::assertSame('pl', $page->meta['lang']);
		self::assertSame('Strony WWW', $page->meta['og']['title']);

		self::assertSame([
			[1, 'Tworzenie stron internetowych', true],
			[2, 'Jak pracujemy', true],
			[3, 'Warsztat', true],
			[2, 'Cennik', true],
			[2, 'Polecane wpisy', false],
			[4, 'Kontakt', false],
		], array_map(static fn (array $heading): array => [$heading['level'], $heading['text'], $heading['in_main']], $page->headings['list']));
		self::assertSame(['h1' => 1, 'h2' => 3, 'h3' => 1, 'h4' => 1, 'h5' => 0, 'h6' => 0], $page->headings['counts']);
		self::assertSame(1, $page->headings['outline']['skipped_levels'], 'H2 → H4 w stopce.');
		self::assertFalse($page->headings['outline']['multiple_h1']);

		self::assertSame('main', $page->content['source']);
		$text = $page->content['text'];
		self::assertStringContainsString('Projektujemy szybkie i dostępne strony', $text);
		self::assertStringContainsString('Sklep internetowy', $text);

		foreach (['Ignore previous', 'color:red', 'cookies', 'Udostępnij', 'Start', 'Polecane wpisy', 'Przykładowa', 'Włącz JavaScript'] as $noise) {
			self::assertStringNotContainsString($noise, $text, 'Poza treścią główną: ' . $noise);
		}

		self::assertSame(['Tworzenie stron internetowych', 'Jak pracujemy', 'Warsztat', 'Cennik'], array_column($page->content['sections'], 'heading'), 'Sekcje według nagłówków.');
		self::assertSame([1, 2, 3, 2], array_column($page->content['sections'], 'level'));
		self::assertGreaterThan(40, $page->content['word_count']);
		self::assertSame('indexable_by_directives', $page->technical['indexability']);
	}

	public function test_21_22_internal_and_external_links_with_relative_addresses(): void
	{
		$html = '<html><head><base href="https://www.example.pl/oferta/"></head><body><main><p>' . str_repeat('słowo ', 60) . '</p>'
			. '<a href="kontakt">Kontakt</a> <a href="../blog/?p=1#komentarze">Blog</a> <a href="//example.pl/o-nas/">O nas</a>'
			. ' <a href="https://partner.pl/" rel="nofollow sponsored">Partner</a> <a href="mailto:biuro@example.pl">Mail</a>'
			. ' <a href="javascript:void(0)">JS</a> <a href="#top">Góra</a> <a href="https://blog.example.pl/wpis"><img alt="x"></a></main>'
			. '<footer><a href="/polityka/">Polityka</a></footer></body></html>';
		$page = (new HtmlExtractor())->extract($html, self::URL);

		self::assertSame([
			['https://www.example.pl/oferta/kontakt', 'Kontakt', false, true],
			['https://www.example.pl/blog/?p=1', 'Blog', false, true],
			['https://example.pl/o-nas/', 'O nas', false, true],
			['https://blog.example.pl/wpis', '', false, true],
			['https://www.example.pl/polityka/', 'Polityka', false, false],
		], array_map(static fn (array $link): array => [$link['url'], $link['anchor'], $link['nofollow'], $link['in_main']], $page->links['internal']));
		self::assertSame([['https://partner.pl/', true]], array_map(static fn (array $link): array => [$link['url'], $link['nofollow']], $page->links['external']));
		self::assertSame(['internal' => 5, 'external' => 1, 'ignored' => 3, 'nofollow' => 1, 'in_main' => 5, 'total' => 6], $page->links['counts']);
	}

	public function test_23_robots_meta_and_x_robots_tag_give_indexability_by_directives_only(): void
	{
		$noindex = (new HtmlExtractor())->extract('<html><head><meta name="robots" content="NOINDEX, follow"></head><body><main>' . str_repeat('tekst ', 200) . '</main></body></html>', self::URL);
		self::assertSame('blocked_by_directives', $noindex->technical['indexability']);
		self::assertSame(['noindex', 'follow'], $noindex->meta['robots']);

		$header = (new HtmlExtractor())->extract('<html><body><main>' . str_repeat('tekst ', 200) . '</main></body></html>', self::URL, null, ['x-robots-tag' => 'googlebot: noindex, nofollow']);
		self::assertSame('blocked_by_directives', $header->technical['indexability']);
		self::assertContains('googlebot: noindex', $header->technical['directives']);

		$other = (new HtmlExtractor())->extract('<html><head><link rel="canonical" href="https://www.example.pl/inna/"><meta name="robots" content="index,follow"></head><body><main>' . str_repeat('tekst ', 200) . '</main></body></html>', self::URL, null, ['x-robots-tag' => 'otherbot: noindex']);
		self::assertSame('indexable_by_directives', $other->technical['indexability'], 'Dyrektywa innego robota nie blokuje.');
		self::assertSame('other', $other->technical['canonical_status']);
		self::assertStringContainsString('does not prove', $other->technical['note']);

		$multiple = (new HtmlExtractor())->extract('<html><head><link rel="canonical" href="/a/"><link rel="canonical" href="/b/"></head><body></body></html>', self::URL);
		self::assertSame('multiple', $multiple->technical['canonical_status']);
		self::assertNull($multiple->meta['canonical']);
	}

	public function test_24_javascript_rendered_page_is_marked_incomplete_not_empty_of_topics(): void
	{
		$html = '<!doctype html><html><head><title>Aplikacja</title><script src="/a.js"></script><script src="/b.js"></script><script src="/c.js"></script>'
			. '<script src="/d.js"></script><script src="/e.js"></script></head><body><div id="root"></div>'
			. '<noscript>You need to enable JavaScript to run this app.</noscript><script id="__NEXT_DATA__" type="application/json">{"props":{}}</script></body></html>';
		$page = (new HtmlExtractor())->extract($html, self::URL);

		self::assertSame('incomplete', $page->quality['level']);
		self::assertContains('js_rendered_suspected', $page->quality['reasons']);
		self::assertContains('empty_#root', $page->quality['js_markers']);
		self::assertContains('noscript_requires_js', $page->quality['js_markers']);
		self::assertSame(0, $page->content['word_count']);
		self::assertStringContainsString('may still exist', (string) $page->quality['note']);
	}

	public function test_25_malformed_html_is_parsed_defensively(): void
	{
		$html = '<html><head><title>Zepsuty <b>HTML</title></head><body><main><h1>Nagłówek<p>Akapit bez zamknięcia <div>blok<span>tekst ' . str_repeat('słowo ', 40)
			. '</main><h2>Po main</h2><table><tr><td>komórka</td></body>';
		$page = (new HtmlExtractor())->extract($html, self::URL);

		self::assertNotNull($page->meta['title']);
		self::assertSame('Nagłówek', $page->headings['list'][0]['text'] ?? null);
		self::assertStringContainsString('Akapit bez zamknięcia', $page->content['text']);

		$garbage = (new HtmlExtractor())->extract("\x00\x01\xFF<<<>>>", self::URL);
		self::assertContains($garbage->quality['level'], ['empty', 'incomplete']);
	}

	public function test_26_charsets_are_converted_to_utf8(): void
	{
		$body = '<html><head><title>Zażółć gęślą jaźń</title></head><body><main><h1>Łódź - żółw</h1><p>' . str_repeat('Gdańsk ', 30) . '</p></main></body></html>';

		$latin2 = (new HtmlExtractor())->extract((string) iconv('UTF-8', 'ISO-8859-2', $body), self::URL, 'iso-8859-2');
		self::assertSame('Zażółć gęślą jaźń', $latin2->meta['title']);
		self::assertSame('ISO-8859-2', $latin2->charset);

		$cp1250 = str_replace('<head>', '<head><meta charset="windows-1250">', $body);
		$fromMeta = (new HtmlExtractor())->extract((string) iconv('UTF-8', 'WINDOWS-1250', $cp1250), self::URL);
		self::assertSame('Łódź - żółw', $fromMeta->headings['list'][0]['text']);

		$bom = (new HtmlExtractor())->extract("\xEF\xBB\xBF" . $body, self::URL);
		self::assertSame('Zażółć gęślą jaźń', $bom->meta['title']);

		$undeclared = (new HtmlExtractor())->extract((string) iconv('UTF-8', 'WINDOWS-1250', $body), self::URL);
		self::assertSame('Zażółć gęślą jaźń', $undeclared->meta['title'], 'Niepoprawny UTF-8 bez deklaracji → Windows-1250.');
	}

	public function test_27_large_documents_respect_limits_and_mark_truncation(): void
	{
		$html = '<html><head><title>Duża</title></head><body><main>';

		for ($i = 1; $i <= 400; $i++) {
			$html .= '<h2>Sekcja ' . $i . '</h2><p>' . str_repeat('Długi tekst akapitu ' . $i . '. ', 30) . '</p><a href="/strona-' . $i . '/">Link ' . $i . '</a>';
		}

		$page = (new HtmlExtractor())->extract($html . '</main></body></html>', self::URL);

		self::assertCount(HtmlExtractor::MAX_HEADINGS, $page->headings['list']);
		self::assertSame(400, $page->headings['counts']['h2']);
		self::assertCount(HtmlExtractor::MAX_LINKS, [...$page->links['internal'], ...$page->links['external']]);
		self::assertSame(400, $page->links['counts']['internal']);
		self::assertCount(HtmlExtractor::MAX_SECTIONS, $page->content['sections']);
		self::assertLessThanOrEqual(HtmlExtractor::MAX_MAIN_TEXT, mb_strlen($page->content['text']));
		self::assertEqualsCanonicalizing(['headings', 'links', 'sections', 'main_text'], $page->limits['truncated']);
		self::assertContains('content_truncated', $page->quality['reasons']);
	}

	public function test_28_content_without_landmarks_drops_menu_and_footer_but_keeps_hero_heading(): void
	{
		$html = '<html><body><div class="navbar"><ul><li><a href="/">Strona główna</a></li><li><a href="/oferta/">Oferta</a></li></ul></div>'
			. '<div class="page-header hero"><h1>Pozycjonowanie stron</h1></div><div class="content"><p>' . str_repeat('Skuteczne pozycjonowanie dla firm lokalnych. ', 30) . '</p></div>'
			. '<div id="footer">Copyright 2026 Example. Wszelkie prawa zastrzeżone.</div><footer>Mapa strony</footer></body></html>';
		$page = (new HtmlExtractor())->extract($html, self::URL);

		self::assertSame('body', $page->content['source']);
		self::assertContains('no_main_landmark', $page->quality['reasons']);
		self::assertStringContainsString('Pozycjonowanie stron', $page->content['text']);
		self::assertStringContainsString('Skuteczne pozycjonowanie', $page->content['text']);

		foreach (['Strona główna', 'Copyright', 'Mapa strony'] as $chrome) {
			self::assertStringNotContainsString($chrome, $page->content['text'], $chrome);
		}
	}

	public function test_content_hash_ignores_fetch_metadata_and_follows_content(): void
	{
		$html = '<html><head><title>A</title></head><body><main><h1>Tytuł</h1><p>' . str_repeat('tekst ', 50) . '</p></main></body></html>';
		$first = (new HtmlExtractor())->extract($html, self::URL);
		$same = (new HtmlExtractor())->extract(str_replace('<html>', "<html>\n<!-- wygenerowano 2026-01-15 12:00 -->", $html), self::URL);
		$changed = (new HtmlExtractor())->extract(str_replace('Tytuł', 'Nowy tytuł', $html), self::URL);

		self::assertSame($first->contentHash(), $same->contentHash(), 'Komentarz z datą wygenerowania nie zmienia treści.');
		self::assertNotSame($first->contentHash(), $changed->contentHash());
	}
}
