<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Extract;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use OsfSeo\Serp\DomainFamily;

/**
 * Ekstrakcja rzeczywistej zawartości pobranego HTML (wersja 1 — docs/ARCHITECTURE.md, sekcja 23.6) parserem DOM (libxml), bez wykonywania
 * JavaScriptu i bez pobierania zasobów (LIBXML_NONET):
 *
 * - meta: title, description, canonical, robots + X-Robots-Tag (ogólne i dla googlebota), lang, podstawowe Open Graph,
 * - nagłówki H1–H6 w kolejności dokumentu z hierarchią (liczniki, wiele H1, brak H1, przeskoki poziomów) i znacznikiem „w treści głównej”,
 * - treść główna: `<main>` / `[role=main]` → największy `<article>` → `<body>` bez nawigacji, nagłówka i stopki strony; zawsze bez skryptów,
 *   stylów, formularzy, cookie bannerów, okien, udostępniania i komentarzy; sekcje według nagłówków; liczba słów,
 * - linki wewnętrzne i zewnętrzne z tekstem anchora, `nofollow`, w treści głównej (adresy względne rozwiązane względem `<base>` / URL),
 * - sygnały techniczne: indeksowalność WYŁĄCZNIE z dyrektyw (`indexable_by_directives` / `blocked_by_directives` / `unknown` — nigdy
 *   „zaindeksowana w Google”), stan canonical,
 * - jakość ekstrakcji (`good` / `partial` / `incomplete` / `empty`) z powodami — m.in. podejrzenie renderowania JavaScriptem; brak
 *   odczytanej treści NIE oznacza, że strona jej nie ma.
 *
 * Wszystkie listy i teksty ograniczone (liczniki pominięć i znaczniki ucięcia w `limits`). Wynik jest deterministyczny dla tego samego HTML.
 */
final class HtmlExtractor
{
	public const VERSION = 1;

	public const MAX_PARSE_BYTES = 3 * 1024 * 1024;

	public const MAX_HEADINGS = 150;

	public const MAX_LINKS = 300;

	public const MAX_SECTIONS = 60;

	public const MAX_MAIN_TEXT = 30000;

	public const MAX_SECTION_TEXT = 3000;

	public const MAX_HEADING_TEXT = 200;

	public const MAX_ANCHOR = 150;

	public const MAX_META = 1000;

	/** Elementy nigdy nie będące treścią. */
	private const STRIP = ['script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed', 'link', 'meta', 'input', 'select', 'textarea', 'button', 'option', 'math', 'video', 'audio', 'picture', 'source', 'map'];

	/** Elementy blokowe (granice bloków tekstu). */
	private const BLOCKS = ['p', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot', 'caption', 'blockquote', 'pre', 'figure', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'br', 'address', 'details', 'summary', 'form', 'fieldset', 'legend'];

	/** Powtarzalne elementy niezwiązane z treścią (id/klasa) — usuwane z treści głównej zawsze. */
	private const BOILERPLATE = '/(^|[\s_\-])(cookies?|cookie-?(?:banner|notice|bar|law)|consent|gdpr|rodo|cmplz|onetrust|cc-?window|newsletter|popup|modal|lightbox|share|sharing|social|related|comments?|advert|ads|adsbygoogle|breadcrumbs?|skip-?link|sr-only|visually-?hidden)([\s_\-]|$)/i';

	/** Nawigacja i chrom witryny (id/klasa) — usuwane tylko przy treści z `<body>` (bez znacznika semantycznego). */
	private const CHROME = '/(^|[\s_\-])(nav|navbar|navigation|menu|mega-?menu|site-?header|site-?footer|footer|topbar|top-?bar|sidebar|widget|offcanvas)([\s_\-]|$)/i';

	/**
	 * @param array<string, string> $headers wybrane nagłówki odpowiedzi (x-robots-tag, content-language)
	 */
	public function extract(string $html, string $url, ?string $charset = null, array $headers = []): PageExtraction
	{
		$inputBytes = strlen($html);
		$truncated = [];

		if ($inputBytes > self::MAX_PARSE_BYTES) {
			$html = substr($html, 0, self::MAX_PARSE_BYTES);
			$truncated[] = 'html';
		}

		[$html, $usedCharset] = Charset::toUtf8($html, $charset);
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $html !== '' && $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE);
		$parseErrors = count(libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (! $loaded) {
			return PageExtraction::empty($url, $usedCharset, $inputBytes);
		}

		$xpath = new DOMXPath($document);
		$base = $this->baseUrl($xpath, $url);
		$family = DomainFamily::fromUrl($url);
		$meta = $this->meta($xpath, $base, $headers);
		$scripts = $xpath->query('//script')->length;
		$markers = $this->jsMarkers($xpath, $html);

		foreach (self::STRIP as $tag) {
			foreach (iterator_to_array($xpath->query('//' . $tag)) as $node) {
				$node->parentNode?->removeChild($node);
			}
		}

		[$root, $source] = $this->root($xpath);
		$removed = $root === null ? [] : $this->removeBoilerplate($xpath, $root, $source === 'body');
		$headings = $this->headings($xpath, $root, $truncated);
		$content = $root === null ? ['text' => '', 'sections' => [], 'blocks' => 0] : $this->content($root, $truncated);
		$links = $this->links($xpath, $root, $base, $family, $truncated);
		$wordCount = self::words($content['text']);
		$technical = $this->technical($meta, $url);
		$quality = $this->quality($wordCount, $headings['list'], $scripts, $markers, $source, $truncated, strlen($content['text']), $inputBytes, $parseErrors);

		return new PageExtraction(
			self::VERSION,
			$usedCharset,
			$meta,
			$headings,
			[
				'source' => $source,
				'text' => $content['text'],
				'word_count' => $wordCount,
				'sections' => $content['sections'],
				'removed' => $removed,
			],
			$links,
			$technical,
			$quality,
			['truncated' => array_values(array_unique($truncated)), 'input_bytes' => $inputBytes, 'parse_errors' => $parseErrors],
		);
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	private function meta(DOMXPath $xpath, string $base, array $headers): array
	{
		$first = static function (string $query) use ($xpath): ?string {
			$node = $xpath->query($query)->item(0);

			return $node === null ? null : $node->nodeValue;
		};
		$metaContent = function (string $name) use ($xpath): ?string {
			foreach ($xpath->query('//meta[@name or @property]') as $node) {
				if (! $node instanceof DOMElement) {
					continue;
				}

				$key = strtolower(trim($node->getAttribute('name') !== '' ? $node->getAttribute('name') : $node->getAttribute('property')));

				if ($key === $name) {
					return self::clean($node->getAttribute('content'), self::MAX_META);
				}
			}

			return null;
		};
		$canonicals = [];

		foreach ($xpath->query('//link[@rel][@href]') as $node) {
			if ($node instanceof DOMElement && in_array('canonical', preg_split('/\s+/', strtolower(trim($node->getAttribute('rel')))) ?: [], true)) {
				$canonicals[] = trim($node->getAttribute('href'));
			}
		}

		$robots = [];

		foreach ($xpath->query('//meta[@name][@content]') as $node) {
			if ($node instanceof DOMElement && in_array(strtolower(trim($node->getAttribute('name'))), ['robots', 'googlebot'], true)) {
				$scope = strtolower(trim($node->getAttribute('name')));

				foreach (self::directives($node->getAttribute('content')) as $directive) {
					$robots[] = $scope === 'robots' ? $directive : 'googlebot: ' . $directive;
				}
			}
		}

		$xRobots = [];

		foreach (preg_split('/,(?![^,:]*:\s*[a-z_-]+:)/i', (string) ($headers['x-robots-tag'] ?? '')) ?: [] as $part) {
			$part = strtolower(trim($part));

			if ($part === '') {
				continue;
			}

			if (preg_match('/^([a-z0-9_-]+)\s*:\s*(.+)$/', $part, $match) === 1 && ! in_array($match[1], ['unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview'], true)) {
				if (in_array($match[1], ['googlebot', 'robots', '*'], true)) {
					foreach (self::directives($match[2]) as $directive) {
						$xRobots[] = $match[1] === 'googlebot' ? 'googlebot: ' . $directive : $directive;
					}
				}

				continue;
			}

			$xRobots[] = $part;
		}

		$html = $xpath->query('//html')->item(0);

		return [
			'title' => self::clean($first('//head/title') ?? $first('//title'), 600),
			'description' => $metaContent('description'),
			'canonical' => count($canonicals) === 1 ? self::resolve($base, $canonicals[0]) : null,
			'canonical_count' => count($canonicals),
			'canonical_raw' => $canonicals === [] ? null : self::clean($canonicals[0], 2048),
			'robots' => array_values(array_unique($robots)),
			'x_robots_tag' => array_values(array_unique($xRobots)),
			'lang' => $html instanceof DOMElement && $html->getAttribute('lang') !== '' ? self::clean($html->getAttribute('lang'), 35) : (isset($headers['content-language']) ? self::clean($headers['content-language'], 35) : null),
			'og' => [
				'title' => $metaContent('og:title'),
				'description' => $metaContent('og:description'),
				'type' => $metaContent('og:type'),
			],
		];
	}

	/**
	 * Wybór korzenia treści głównej: `<main>` / `[role=main]` z treścią → największy `<article>` → `<body>`.
	 *
	 * @return array{0: ?DOMElement, 1: string}
	 */
	private function root(DOMXPath $xpath): array
	{
		foreach (['//main', '//*[@role="main"]'] as $query) {
			$best = null;

			foreach ($xpath->query($query) as $node) {
				if ($node instanceof DOMElement && self::words((string) $node->textContent) >= 20 && ($best === null || strlen((string) $node->textContent) > strlen((string) $best->textContent))) {
					$best = $node;
				}
			}

			if ($best !== null) {
				return [$best, $query === '//main' ? 'main' : 'role_main'];
			}
		}

		$article = null;

		foreach ($xpath->query('//article') as $node) {
			if ($node instanceof DOMElement && ($article === null || strlen((string) $node->textContent) > strlen((string) $article->textContent))) {
				$article = $node;
			}
		}

		if ($article !== null && self::words((string) $article->textContent) >= 50) {
			return [$article, 'article'];
		}

		$body = $xpath->query('//body')->item(0);

		return [$body instanceof DOMElement ? $body : null, 'body'];
	}

	/**
	 * @return array<string, int> usunięte elementy według rodzaju
	 */
	private function removeBoilerplate(DOMXPath $xpath, DOMElement $root, bool $fromBody): array
	{
		$removed = ['navigation' => 0, 'chrome' => 0, 'boilerplate' => 0, 'forms' => 0];
		$candidates = [];

		foreach ($xpath->query('.//*', $root) as $node) {
			if (! $node instanceof DOMElement) {
				continue;
			}

			$tag = strtolower($node->nodeName);
			$role = strtolower($node->getAttribute('role'));
			$marker = $node->getAttribute('id') . ' ' . $node->getAttribute('class');
			$kind = match (true) {
				$tag === 'nav' || $tag === 'aside' || in_array($role, ['navigation', 'complementary', 'search', 'dialog', 'alertdialog', 'menu', 'menubar'], true) => 'navigation',
				$fromBody && (in_array($tag, ['header', 'footer'], true) && ! self::insideContent($node, $root) || in_array($role, ['banner', 'contentinfo'], true)) => 'chrome',
				$node->getAttribute('aria-hidden') === 'true' || $node->hasAttribute('hidden') => 'boilerplate',
				trim($marker) !== '' && preg_match(self::BOILERPLATE, $marker) === 1 => 'boilerplate',
				$fromBody && trim($marker) !== '' && preg_match(self::CHROME, $marker) === 1 && ! self::containsHeading($xpath, $node, 1) => 'chrome',
				default => null,
			};

			if ($kind !== null) {
				$candidates[] = [$node, $kind];
			}
		}

		foreach ($candidates as [$node, $kind]) {
			if ($node->parentNode !== null && self::attached($node, $root)) {
				$node->parentNode->removeChild($node);
				$removed[$kind]++;
			}
		}

		return array_filter($removed);
	}

	/**
	 * Nagłówki H1–H6 całego dokumentu (po usunięciu skryptów) z hierarchią i znacznikiem „w treści głównej”.
	 *
	 * @param list<string> $truncated
	 * @return array{list: list<array{level: int, text: string, in_main: bool}>, counts: array<string, int>, total: int, outline: array<string, mixed>}
	 */
	private function headings(DOMXPath $xpath, ?DOMElement $root, array &$truncated): array
	{
		$list = [];
		$counts = ['h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0];
		$skipped = 0;
		$empty = 0;
		$previous = 0;
		$total = 0;

		foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $node) {
			if (! $node instanceof DOMElement) {
				continue;
			}

			$level = (int) substr(strtolower($node->nodeName), 1);
			$text = self::clean((string) $node->textContent, self::MAX_HEADING_TEXT);
			$counts['h' . $level]++;
			$total++;

			if ($text === null || $text === '') {
				$empty++;

				continue;
			}

			if ($previous > 0 && $level > $previous + 1) {
				$skipped++;
			}

			$previous = $level;

			if (count($list) < self::MAX_HEADINGS) {
				$list[] = ['level' => $level, 'text' => $text, 'in_main' => $root !== null && self::attached($node, $root)];
			}
		}

		if ($total - $empty > self::MAX_HEADINGS) {
			$truncated[] = 'headings';
		}

		return [
			'list' => $list,
			'counts' => $counts,
			'total' => $total,
			'outline' => [
				'missing_h1' => $counts['h1'] === 0,
				'multiple_h1' => $counts['h1'] > 1,
				'first_level' => $list[0]['level'] ?? null,
				'skipped_levels' => $skipped,
				'empty_headings' => $empty,
			],
		];
	}

	/**
	 * Tekst treści głównej w blokach i sekcje według nagłówków.
	 *
	 * @param list<string> $truncated
	 * @return array{text: string, sections: list<array{level: ?int, heading: ?string, text: string, words: int}>, blocks: int}
	 */
	private function content(DOMElement $root, array &$truncated): array
	{
		$blocks = [];
		$buffer = '';
		$flush = static function () use (&$buffer, &$blocks): void {
			$text = trim((string) preg_replace('/\s+/u', ' ', $buffer));

			if ($text !== '') {
				$blocks[] = ['text' => $text];
			}

			$buffer = '';
		};
		$walk = static function (DOMNode $node) use (&$walk, &$buffer, &$blocks, $flush): void {
			foreach ($node->childNodes as $child) {
				if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
					$buffer .= $child->nodeValue;

					continue;
				}

				if (! $child instanceof DOMElement) {
					continue;
				}

				$tag = strtolower($child->nodeName);

				if (preg_match('/^h([1-6])$/', $tag, $match) === 1) {
					$flush();
					$heading = trim((string) preg_replace('/\s+/u', ' ', (string) $child->textContent));

					if ($heading !== '') {
						$blocks[] = ['heading' => (int) $match[1], 'text' => $heading];
					}

					continue;
				}

				$block = in_array($tag, self::BLOCKS, true);

				if ($block) {
					$flush();
				}

				$walk($child);

				if ($block) {
					$flush();
				}
			}
		};
		$walk($root);
		$flush();

		$sections = [];
		$current = ['level' => null, 'heading' => null, 'parts' => []];
		$texts = [];

		foreach ($blocks as $block) {
			if (isset($block['heading'])) {
				$sections[] = $current;
				$current = ['level' => $block['heading'], 'heading' => $block['text'], 'parts' => []];
				$texts[] = $block['text'];

				continue;
			}

			$current['parts'][] = $block['text'];
			$texts[] = $block['text'];
		}

		$sections[] = $current;
		$result = [];

		foreach ($sections as $section) {
			$body = implode("\n", $section['parts']);

			if ($section['heading'] === null && $body === '') {
				continue;
			}

			if (count($result) >= self::MAX_SECTIONS) {
				$truncated[] = 'sections';

				break;
			}

			$clipped = self::clean($body, self::MAX_SECTION_TEXT, true) ?? '';

			if (mb_strlen($body, 'UTF-8') > self::MAX_SECTION_TEXT) {
				$truncated[] = 'section_text';
			}

			$result[] = [
				'level' => $section['level'],
				'heading' => $section['heading'] === null ? null : self::clean($section['heading'], self::MAX_HEADING_TEXT),
				'text' => $clipped,
				'words' => self::words($body),
			];
		}

		$text = implode("\n", $texts);

		if (mb_strlen($text, 'UTF-8') > self::MAX_MAIN_TEXT) {
			$truncated[] = 'main_text';
			$text = rtrim(mb_substr($text, 0, self::MAX_MAIN_TEXT - 1, 'UTF-8')) . '…';
		}

		return ['text' => $text, 'sections' => $result, 'blocks' => count($blocks)];
	}

	/**
	 * Linki całego dokumentu (bez skryptów): wewnętrzne / zewnętrzne względem rodziny domeny strony.
	 *
	 * @param list<string> $truncated
	 * @return array{internal: list<array<string, mixed>>, external: list<array<string, mixed>>, counts: array<string, int>}
	 */
	private function links(DOMXPath $xpath, ?DOMElement $root, string $base, ?string $family, array &$truncated): array
	{
		$internal = [];
		$external = [];
		$seen = [];
		$counts = ['internal' => 0, 'external' => 0, 'ignored' => 0, 'nofollow' => 0, 'in_main' => 0];

		foreach ($xpath->query('//a[@href]') as $node) {
			if (! $node instanceof DOMElement) {
				continue;
			}

			$href = trim($node->getAttribute('href'));
			$resolved = $href === '' || str_starts_with($href, '#') ? null : self::resolve($base, $href);

			if ($resolved === null) {
				$counts['ignored']++;

				continue;
			}

			$host = DomainFamily::fromUrl($resolved);
			$isInternal = $family !== null && $host !== null && DomainFamily::matches($host, $family);
			$nofollow = in_array('nofollow', preg_split('/\s+/', strtolower($node->getAttribute('rel'))) ?: [], true);
			$inMain = $root !== null && self::attached($node, $root);
			$counts[$isInternal ? 'internal' : 'external']++;
			$counts['nofollow'] += $nofollow ? 1 : 0;
			$counts['in_main'] += $inMain ? 1 : 0;
			$anchor = self::clean((string) $node->textContent, self::MAX_ANCHOR) ?? '';

			if ($anchor === '') {
				$anchor = self::clean($node->getAttribute('aria-label') !== '' ? $node->getAttribute('aria-label') : $node->getAttribute('title'), self::MAX_ANCHOR) ?? '';
			}

			$key = $resolved . "\n" . $anchor;

			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;

			if (count($internal) + count($external) >= self::MAX_LINKS) {
				$truncated[] = 'links';

				continue;
			}

			$entry = ['url' => $resolved, 'anchor' => $anchor, 'nofollow' => $nofollow, 'in_main' => $inMain];

			if ($isInternal) {
				$internal[] = $entry;
			} else {
				$external[] = $entry;
			}
		}

		return ['internal' => $internal, 'external' => $external, 'counts' => $counts + ['total' => $counts['internal'] + $counts['external']]];
	}

	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	private function technical(array $meta, string $url): array
	{
		$directives = array_values(array_unique([...$meta['robots'], ...$meta['x_robots_tag']]));
		$blocking = array_values(array_filter($directives, static fn (string $directive): bool => in_array(preg_replace('/^googlebot:\s*/', '', $directive), ['noindex', 'none'], true)));
		$canonical = $meta['canonical'];

		return [
			'indexability' => $blocking !== [] ? 'blocked_by_directives' : 'indexable_by_directives',
			'indexability_basis' => $blocking !== [] ? $blocking : ['no_noindex_directive'],
			'directives' => $directives,
			'canonical_status' => match (true) {
				$meta['canonical_count'] > 1 => 'multiple',
				$meta['canonical_count'] === 0 => 'missing',
				$canonical === null => 'invalid',
				self::comparable($canonical) === self::comparable($url) => 'self',
				default => 'other',
			},
			'note' => 'Directives only: HTML does not prove whether Google indexed the page.',
		];
	}

	/**
	 * @param list<array<string, mixed>> $headings
	 * @param list<string> $markers
	 * @param list<string> $truncated
	 * @return array<string, mixed>
	 */
	private function quality(int $words, array $headings, int $scripts, array $markers, string $source, array $truncated, int $textBytes, int $htmlBytes, int $parseErrors): array
	{
		$reasons = [];

		if ($markers !== []) {
			$reasons[] = 'js_framework_markers';
		}

		if ($words < 50 && ($markers !== [] || $scripts >= 5)) {
			$reasons[] = 'js_rendered_suspected';
		}

		if ($words < 150) {
			$reasons[] = 'thin_extracted_text';
		}

		if ($source === 'body') {
			$reasons[] = 'no_main_landmark';
		}

		if (array_intersect($truncated, ['html', 'main_text', 'sections', 'section_text']) !== []) {
			$reasons[] = 'content_truncated';
		}

		if ($parseErrors > 200) {
			$reasons[] = 'malformed_html';
		}

		$level = match (true) {
			$words === 0 && $headings === [] && $markers === [] && $scripts < 5 => 'empty',
			in_array('js_rendered_suspected', $reasons, true) || $words < 20 => 'incomplete',
			$reasons !== [] && array_diff($reasons, ['no_main_landmark', 'js_framework_markers']) !== [] || $source === 'body' && $words < 300 => 'partial',
			default => 'good',
		};

		return [
			'level' => $level,
			'reasons' => $reasons,
			'word_count' => $words,
			'script_count' => $scripts,
			'js_markers' => $markers,
			'text_to_html_ratio' => $htmlBytes > 0 ? round($textBytes / $htmlBytes, 4) : 0.0,
			'note' => $level === 'good' ? null : 'Content not detected in the fetched HTML may still exist on the page (JavaScript rendering, blocked resources).',
		];
	}

	/**
	 * Ślady aplikacji renderowanej JavaScriptem.
	 *
	 * @return list<string>
	 */
	private function jsMarkers(DOMXPath $xpath, string $html): array
	{
		$markers = [];

		foreach (['root', 'app', '__next', '__nuxt', 'svelte'] as $id) {
			$node = $xpath->query('//*[@id="' . $id . '"]')->item(0);

			if ($node !== null && self::words((string) $node->textContent) < 20) {
				$markers[] = 'empty_#' . $id;
			}
		}

		foreach (['__NEXT_DATA__' => 'next_data', '__NUXT__' => 'nuxt_state', 'ng-version=' => 'angular', 'data-reactroot' => 'react_root', 'data-server-rendered' => 'vue_ssr'] as $needle => $marker) {
			if (str_contains($html, $needle)) {
				$markers[] = $marker;
			}
		}

		foreach ($xpath->query('//noscript') as $node) {
			if (preg_match('/(enable|włącz|wymaga)\s+javascript|javascript\s+(is\s+)?(required|disabled)/iu', (string) $node->textContent) === 1) {
				$markers[] = 'noscript_requires_js';

				break;
			}
		}

		return array_values(array_unique($markers));
	}

	private function baseUrl(DOMXPath $xpath, string $url): string
	{
		$base = $xpath->query('//base[@href]')->item(0);

		if ($base instanceof DOMElement) {
			$resolved = self::resolve($url, trim($base->getAttribute('href')));

			if ($resolved !== null) {
				return $resolved;
			}
		}

		return $url;
	}

	/**
	 * Adres bezwzględny http(s) bez fragmentu (względem bazy) albo null (javascript:, mailto:, tel:, data:, niepoprawny).
	 */
	public static function resolve(string $base, string $href): ?string
	{
		$href = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $href));

		if ($href === '' || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href) === 1 && preg_match('#^https?://#i', $href) !== 1) {
			return null;
		}

		$href = (string) preg_replace('/#.*$/s', '', $href);
		$parts = parse_url($base);

		if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
			return null;
		}

		if (str_starts_with($href, '//')) {
			$href = $parts['scheme'] . ':' . $href;
		} elseif (preg_match('#^https?://#i', $href) !== 1) {
			$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
			$path = (string) ($parts['path'] ?? '/');

			if ($href === '') {
				$href = $origin . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
			} elseif (str_starts_with($href, '/')) {
				$href = $origin . $href;
			} elseif (str_starts_with($href, '?')) {
				$href = $origin . $path . $href;
			} else {
				$href = $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $href;
			}
		}

		$resolved = parse_url($href);

		if (! is_array($resolved) || ! isset($resolved['scheme'], $resolved['host']) || ! in_array(strtolower($resolved['scheme']), ['http', 'https'], true)) {
			return null;
		}

		$path = self::dotSegments((string) ($resolved['path'] ?? '/'));

		return strtolower($resolved['scheme']) . '://' . strtolower($resolved['host']) . (isset($resolved['port']) ? ':' . $resolved['port'] : '') . ($path === '' ? '/' : $path)
			. (isset($resolved['query']) && $resolved['query'] !== '' ? '?' . $resolved['query'] : '');
	}

	/** Usunięcie segmentów `.` i `..` (RFC 3986, 5.2.4). */
	private static function dotSegments(string $path): string
	{
		$output = [];

		foreach (explode('/', $path) as $index => $segment) {
			if ($segment === '..') {
				if (count($output) > 1) {
					array_pop($output);
				}
			} elseif ($segment !== '.' || $index === 0) {
				$output[] = $segment;
			}
		}

		$result = implode('/', $output);

		return str_ends_with($path, '/.') || str_ends_with($path, '/..') ? rtrim($result, '/') . '/' : $result;
	}

	/** Postać do porównania adresów (bez `www.`, końcowego ukośnika i domyślnego portu). */
	private static function comparable(string $url): string
	{
		$parts = parse_url($url);

		if (! is_array($parts) || ! isset($parts['host'])) {
			return $url;
		}

		return (string) preg_replace('/^www\./', '', strtolower($parts['host'])) . rtrim((string) ($parts['path'] ?? '/'), '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
	}

	/**
	 * @return list<string>
	 */
	private static function directives(string $content): array
	{
		return array_values(array_filter(array_map(static fn (string $part): string => strtolower(trim($part)), explode(',', $content)), static fn (string $part): bool => $part !== '' && strlen($part) <= 60));
	}

	private static function attached(DOMNode $node, DOMElement $root): bool
	{
		for ($current = $node; $current !== null; $current = $current->parentNode) {
			if ($current->isSameNode($root)) {
				return true;
			}
		}

		return false;
	}

	/** `<header>` / `<footer>` wewnątrz `<article>` albo `<main>` należy do treści. */
	private static function insideContent(DOMElement $node, DOMElement $root): bool
	{
		for ($current = $node->parentNode; $current !== null && ! $current->isSameNode($root); $current = $current->parentNode) {
			if (in_array(strtolower($current->nodeName), ['article', 'main'], true)) {
				return true;
			}
		}

		return false;
	}

	private static function containsHeading(DOMXPath $xpath, DOMElement $node, int $level): bool
	{
		return $xpath->query('.//h' . $level, $node)->length > 0;
	}

	public static function words(string $text): int
	{
		return (int) preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text);
	}

	/** Jedna linia (albo tekst z akapitami) bez znaków niewidocznych, z limitem i „…”. */
	public static function clean(?string $value, int $max, bool $multiline = false): ?string
	{
		if ($value === null) {
			return null;
		}

		$value = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u', '', mb_scrub($value, 'UTF-8'));
		$value = $multiline
			? trim((string) preg_replace(['/[^\S\n]+/u', '/\n{2,}/'], [' ', "\n"], $value))
			: trim((string) preg_replace('/\s+/u', ' ', $value));

		return mb_strlen($value, 'UTF-8') > $max ? rtrim(mb_substr($value, 0, $max - 1, 'UTF-8')) . '…' : $value;
	}
}
