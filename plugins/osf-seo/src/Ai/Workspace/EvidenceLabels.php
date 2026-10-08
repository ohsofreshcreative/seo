<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Workspace;

use OsfSeo\Strategy\Decision\StrategyAction;

/**
 * Czytelne etykiety odwołań do dowodów (`kw:12`, `cpage:…`, `serp:3`) z zapisanego kontekstu analizy (`ai_run_payloads.input`) —
 * raport i eksport pokazują „Fraza „…””, „Strona konkurenta example.com (#2 w SERP)” zamiast kodów. Wyłącznie dane z kontekstu tej
 * analizy (ten sam projekt); adresy tylko http(s). Nieznane odwołanie → „Dowód z danych analizy”.
 */
final class EvidenceLabels
{
	/**
	 * @param array<string, mixed> $context ciało kontekstu (`AiContext::toArray()['body']` albo samo ciało)
	 * @return array<string, array{label: string, url: ?string}>
	 */
	public static function fromContext(array $context): array
	{
		$body = is_array($context['body'] ?? null) ? $context['body'] : $context;
		$labels = [];
		$keywords = [];

		foreach (array_filter((array) ($body['keywords'] ?? []), 'is_array') as $keyword) {
			if (is_string($keyword['ref'] ?? null)) {
				$keywords[$keyword['ref']] = is_string($keyword['keyword'] ?? null) ? $keyword['keyword'] : null;
				$labels[$keyword['ref']] = self::entry($keywords[$keyword['ref']] === null ? 'Fraza tematu' : 'Fraza „' . $keywords[$keyword['ref']] . '”');
			}
		}

		$keyword = static fn (mixed $ref): ?string => is_string($ref) ? ($keywords[$ref] ?? null) : null;
		$evidence = (array) ($body['evidence'] ?? []);
		$decision = (array) ($body['decision'] ?? []);
		$action = is_string($decision['action'] ?? null) ? StrategyAction::tryFrom($decision['action'])?->label() : null;
		$labels['topic'] = self::entry(is_string($body['topic']['label'] ?? null) ? 'Temat „' . $body['topic']['label'] . '”' : 'Temat Strategii');
		$labels['decision'] = self::entry($action === null ? 'Decyzja Strategii' : 'Decyzja Strategii: ' . $action);
		$gscAsOf = $evidence['gsc']['provenance']['as_of'] ?? null;
		$labels['gsc'] = self::entry('Google Search Console' . (is_string($gscAsOf) ? ' (dane do ' . $gscAsOf . ')' : ''));
		$labels['market'] = self::entry('Dane rynkowe (DataForSEO)');
		$target = (array) ($body['target_page'] ?? []);
		$labels['target'] = self::entry('Strona docelowa tematu', $target['url'] ?? null);
		$serp = is_array($evidence['serp'] ?? null) ? $evidence['serp'] : null;

		if ($serp !== null) {
			$measured = $serp['provenance']['as_of'] ?? null;
			$labels['serp'] = self::entry('Pomiar SERP' . (is_string($measured) ? ' z ' . substr($measured, 0, 10) : ''));

			foreach (array_filter((array) ($serp['top_results'] ?? []), 'is_array') as $result) {
				if (is_string($result['ref'] ?? null)) {
					$domain = is_string($result['domain'] ?? null) ? $result['domain'] : 'wynik organiczny';
					$labels[$result['ref']] = self::entry('Wynik SERP #' . (int) ($result['serp_rank_group'] ?? 0) . ' — ' . $domain, $result['url'] ?? null);
				}
			}
		}

		foreach (array_filter((array) ($evidence['labs_gaps']['items'] ?? []), 'is_array') as $gap) {
			if (is_string($gap['ref'] ?? null)) {
				$text = $keyword($gap['keyword_ref'] ?? null);
				$labels[$gap['ref']] = self::entry('Luka fraz (Labs)' . ($text === null ? '' : ': „' . $text . '”'));
			}
		}

		foreach (array_filter((array) ($evidence['content_gaps']['items'] ?? []), 'is_array') as $gap) {
			if (is_string($gap['ref'] ?? null)) {
				$labels[$gap['ref']] = self::entry('Luka treści' . (is_string($gap['label'] ?? null) ? ': „' . $gap['label'] . '”' : ''));
			}
		}

		foreach (array_filter((array) ($evidence['opportunities']['items'] ?? []), 'is_array') as $opportunity) {
			if (is_string($opportunity['ref'] ?? null)) {
				$labels[$opportunity['ref']] = self::entry('Szansa SEO', $opportunity['page'] ?? null);
			}
		}

		foreach (array_filter((array) ($evidence['discovery']['items'] ?? []), 'is_array') as $item) {
			if (is_string($item['ref'] ?? null)) {
				$text = $keyword($item['keyword_ref'] ?? null);
				$labels[$item['ref']] = self::entry('Nowa fraza' . ($text === null ? '' : ': „' . $text . '”'));
			}
		}

		foreach (array_filter((array) ($target['conflicts'] ?? []), 'is_array') as $index => $conflict) {
			if (is_string($conflict['ref'] ?? null)) {
				$labels[$conflict['ref']] = self::entry('Konflikt adresów #' . ($index + 1) . (is_string($conflict['keyword'] ?? null) ? ' („' . $conflict['keyword'] . '”)' : ''));
			}
		}

		$page = (array) ($target['page_content'] ?? []);

		if (is_string($page['ref'] ?? null)) {
			$fetched = $page['provenance']['as_of'] ?? null;
			$labels[$page['ref']] = self::entry('Strona projektu' . (is_string($fetched) ? ' (pobrana ' . substr($fetched, 0, 10) . ')' : ''), $page['url'] ?? null);
		}

		foreach (array_filter((array) ($evidence['competitor_pages']['items'] ?? []), 'is_array') as $competitor) {
			if (is_string($competitor['ref'] ?? null)) {
				$rank = $competitor['serp']['serp_rank_group'] ?? null;
				$domain = is_string($competitor['domain'] ?? null) ? $competitor['domain'] : 'konkurent';
				$labels[$competitor['ref']] = self::entry('Strona konkurenta ' . $domain . (is_int($rank) ? ' (#' . $rank . ' w SERP)' : ''), $competitor['url'] ?? null);
			}
		}

		foreach (array_filter((array) ($body['site']['pages'] ?? []), 'is_array') as $site) {
			if (is_string($site['ref'] ?? null)) {
				$labels[$site['ref']] = self::entry('Temat „' . (is_string($site['label'] ?? null) ? $site['label'] : '—') . '”', $site['url'] ?? null);
			}
		}

		return $labels;
	}

	/**
	 * Etykiety listy odwołań (nieznane — opis ogólny; bez duplikatów).
	 *
	 * @param array<string, array{label: string, url: ?string}> $labels
	 * @return list<array{label: string, url: ?string}>
	 */
	public static function resolve(array $labels, mixed $refs): array
	{
		$result = [];

		foreach (array_unique(array_filter((array) $refs, 'is_string')) as $ref) {
			$entry = $labels[$ref] ?? self::entry('Dowód z danych analizy');
			$result[$entry['label'] . '|' . $entry['url']] = $entry;
		}

		return array_values($result);
	}

	/**
	 * @return array{label: string, url: ?string}
	 */
	private static function entry(string $label, mixed $url = null): array
	{
		return ['label' => $label, 'url' => is_string($url) && preg_match('#^https?://#i', $url) === 1 ? $url : null];
	}
}
