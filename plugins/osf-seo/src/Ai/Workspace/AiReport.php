<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Workspace;

use OsfSeo\Ai\Analysis\AnalysisType;

/**
 * Raport analizy AI dla panelu i eksportu (STEP 17, faza D — czysta funkcja zapisanego wyniku): wyłącznie wynik zwalidowany
 * (`ai_run_payloads.result`) — nigdy surowa odpowiedź modelu ani JSON. Rekomendacje posortowane według priorytetu i pilności, każde
 * twierdzenie z podstawą (fakt / wniosek / hipoteza), pewnością i czytelnymi dowodami (`EvidenceLabels`); sekcje typowane tylko, gdy
 * mają treść. Analiza tematu z fazy A (kontrakt v1) mapowana na te same pola.
 */
final class AiReport
{
	private const URGENCY_ORDER = ['now' => 0, 'next' => 1, 'optional' => 2];

	/**
	 * @param array<string, mixed> $result zwalidowany wynik
	 * @param array<string, mixed> $context zapisany kontekst analizy (ciało)
	 * @param array<string, mixed>|null $sources źródła zapisane przy uruchomieniu (`ai_runs.sources`)
	 * @return array<string, mixed>
	 */
	public static function build(string $type, array $result, array $context, ?array $sources = null): array
	{
		$labels = EvidenceLabels::fromContext($context);
		$evidence = static fn (mixed $refs): array => EvidenceLabels::resolve($labels, $refs);
		// Kody braków danych i odwołania do dowodów w tekście (dostawca testowy, ale też model, który powtórzy kod z kontekstu) → polskie
		// etykiety; wyłącznie prezentacja — zapisany wynik pozostaje bez zmian.
		$text = static fn (mixed $value): string => is_string($value) ? self::humanize(trim($value), $labels) : '';
		$findings = [];
		$findingTitles = [];

		foreach (self::items($result['findings'] ?? []) as $finding) {
			$id = $text($finding['id'] ?? '');
			$findingTitles[$id] = $text($finding['title'] ?? '');
			$findings[] = [
				'id' => $id,
				'kind' => $text($finding['kind'] ?? ''),
				'kind_label' => ReportLabels::findingKind($finding['kind'] ?? null),
				'title' => $text($finding['title'] ?? ''),
				'explanation' => $text($finding['explanation'] ?? ''),
			] + self::claim($finding, $evidence);
		}

		$recommendations = [];

		foreach (self::items($result['recommendations'] ?? []) as $index => $item) {
			// Kontrakt v1 (analiza tematu): `action` zamiast tytułu i opisu.
			$title = $text($item['title'] ?? $item['action'] ?? '');
			$priority = is_int($item['priority'] ?? null) ? $item['priority'] : null;
			$recommendations[] = [
				'id' => $text($item['id'] ?? ''),
				'order' => $index,
				'title' => $title,
				'description' => $text($item['description'] ?? ''),
				'rationale' => $text($item['rationale'] ?? ''),
				'target' => $text($item['target'] ?? ''),
				'type_label' => isset($item['type']) ? ReportLabels::recommendationType($item['type']) : null,
				'priority' => $priority,
				'priority_label' => ReportLabels::priority($priority),
				'urgency' => is_string($item['urgency'] ?? null) ? $item['urgency'] : null,
				'urgency_label' => isset($item['urgency']) ? ReportLabels::urgency($item['urgency']) : null,
				'impact_label' => ReportLabels::impact($item['expected_impact'] ?? null),
				'impact_rationale' => $text($item['impact_rationale'] ?? ''),
				'manual_check' => ($item['requires_manual_check'] ?? false) === true,
				'verification' => $text($item['verification'] ?? ''),
				'findings' => array_values(array_filter(array_map(static fn (mixed $id): ?string => is_string($id) && ($findingTitles[$id] ?? '') !== '' ? $findingTitles[$id] : null, (array) ($item['finding_ids'] ?? [])))),
			] + self::claim($item, $evidence);
		}

		usort($recommendations, static fn (array $a, array $b): int => [$a['priority'] ?? 9, self::URGENCY_ORDER[$a['urgency'] ?? ''] ?? 3, $a['order']]
			<=> [$b['priority'] ?? 9, self::URGENCY_ORDER[$b['urgency'] ?? ''] ?? 3, $b['order']]);

		$intent = is_array($result['search_intent'] ?? null) ? $result['search_intent'] : null;
		$outline = is_array($result['content_outline'] ?? null) ? $result['content_outline'] : [];
		$outlineSections = array_map(static fn (array $section): array => [
			'level' => (int) ($section['level'] ?? 2) === 3 ? 3 : 2,
			'heading' => $text($section['heading'] ?? ''),
			'scope' => $text($section['scope'] ?? ''),
		] + self::claim($section, $evidence), self::items($outline['sections'] ?? []));
		$suggestions = static fn (mixed $items): array => array_map(static fn (array $item): array => [
			'text' => $text($item['text'] ?? ''),
			'length' => mb_strlen($text($item['text'] ?? ''), 'UTF-8'),
			'rationale' => $text($item['rationale'] ?? ''),
		] + self::claim($item, $evidence), self::items($items));
		$strings = static fn (mixed $items): array => array_values(array_filter(array_map($text, (array) $items), static fn (string $value): bool => $value !== ''));
		$readiness = is_array($sources['readiness'] ?? null) ? $sources['readiness'] : [];

		return [
			'type' => $type,
			'type_label' => ReportLabels::type($type),
			'candidate' => $type === AnalysisType::NEW_PAGE_BRIEF,
			'summary' => $text($result['summary'] ?? ''),
			'intent' => $intent === null || ($intent['primary'] ?? 'unknown') === 'unknown' && $text($intent['explanation'] ?? '') === '' ? null : [
				'primary' => $intent['primary'] ?? 'unknown',
				'label' => ReportLabels::intent($intent['primary'] ?? null),
				'explanation' => $text($intent['explanation'] ?? ''),
			] + self::claim($intent, $evidence),
			'top' => $recommendations[0] ?? null,
			'now' => array_values(array_filter($recommendations, static fn (array $item): bool => $item['urgency'] === 'now')),
			'recommendations' => $recommendations,
			'findings' => $findings,
			'outline' => $text($outline['h1'] ?? '') === '' && $outlineSections === [] ? null : ['h1' => $text($outline['h1'] ?? ''), 'sections' => $outlineSections],
			'titles' => $suggestions($result['title_suggestions'] ?? []),
			'metas' => $suggestions($result['meta_description_suggestions'] ?? []),
			'links' => array_map(static fn (array $link): array => [
				'from_url' => $text($link['from_url'] ?? ''),
				'to_url' => $text($link['to_url'] ?? ''),
				'anchor' => $text($link['anchor_suggestion'] ?? ''),
				'rationale' => $text($link['rationale'] ?? ''),
			] + self::claim($link, $evidence), self::items($result['internal_links'] ?? [])),
			'topics' => array_map(static fn (array $topic): array => [
				'label' => $text($topic['label'] ?? ''),
				'status' => is_string($topic['status'] ?? null) ? $topic['status'] : null,
				'status_label' => ReportLabels::topicStatus($topic['status'] ?? null),
				'note' => $text($topic['note'] ?? ''),
			] + self::claim($topic, $evidence), self::items($result['content_topics'] ?? [])),
			'questions' => $strings($result['user_questions'] ?? []),
			'ctas' => $strings($result['cta_suggestions'] ?? []),
			'client_data' => $strings($result['client_data_needed'] ?? []),
			'risks' => array_map(static fn (array $risk): array => ['description' => $text($risk['description'] ?? '')] + self::claim($risk, $evidence), self::items($result['cannibalization_risks'] ?? [])),
			'missing' => array_map(static fn (array $item): array => ['item' => $text($item['item'] ?? ''), 'why' => $text($item['why_it_matters'] ?? '')], self::items($result['missing_information'] ?? [])),
			'warnings' => $strings([...(array) ($result['warnings'] ?? []), ...(array) ($result['caveats'] ?? [])]),
			'checks' => array_map(static fn (array $check): array => [
				'check' => $text($check['check'] ?? ''),
				'reason' => $text($check['reason'] ?? ''),
				'evidence' => $evidence($check['evidence_refs'] ?? []),
			], self::items($result['manual_checks'] ?? [])),
			'limitations' => array_map(ReportLabels::readinessCode(...), array_values(array_filter((array) ($readiness['limitations'] ?? []), 'is_string'))),
		];
	}

	/**
	 * Tekst bez kodów technicznych: odwołania z kontekstu (`kw:7`, `cpage:…`) → etykieta dowodu, znane kody braków danych i ograniczeń
	 * (`competitor_pages_not_fetched`, `page_index_incomplete`, `keywords_omitted`, `context_reduced` …) → zdanie po polsku. Nieznane kody
	 * i odwołania zostają bez zmian (nie ukrywamy treści).
	 *
	 * @param array<string, array{label: string, url: ?string}> $labels `EvidenceLabels::fromContext()`
	 */
	public static function humanize(string $value, array $labels = []): string
	{
		if ($value === '' || (! str_contains($value, '_') && ! str_contains($value, ':'))) {
			return $value;
		}

		$value = (string) preg_replace_callback(
			'/\b(?:kw|page|cpage|serp|site|gap|cg|opp|disc|conflict):[0-9A-Za-z_\-]+/',
			static fn (array $match): string => isset($labels[$match[0]]) ? $labels[$match[0]]['label'] : $match[0],
			$value,
		);
		$codes = ReportLabels::DATA_GAPS + ReportLabels::READINESS_CODES;

		return (string) preg_replace_callback(
			'/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b/',
			static fn (array $match): string => isset($codes[$match[0]]) ? self::inline($codes[$match[0]]) : $match[0],
			$value,
		);
	}

	/** Zdanie etykiety wstawiane w tekst: bez kropki końcowej, mała litera na początku (poza skrótami typu „GSC”). */
	private static function inline(string $sentence): string
	{
		$sentence = rtrim($sentence, '.');
		$second = mb_substr($sentence, 1, 1, 'UTF-8');

		if ($second !== '' && mb_strtoupper($second, 'UTF-8') === $second && mb_strtolower($second, 'UTF-8') !== $second) {
			return $sentence;
		}

		return mb_strtolower(mb_substr($sentence, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($sentence, 1, null, 'UTF-8');
	}

	/**
	 * Podstawa, pewność i dowody twierdzenia.
	 *
	 * @param array<string, mixed> $item
	 * @param \Closure(mixed): list<array{label: string, url: ?string}> $evidence
	 * @return array<string, mixed>
	 */
	private static function claim(array $item, \Closure $evidence): array
	{
		$basis = is_string($item['basis'] ?? null) ? $item['basis'] : null;

		return [
			'basis' => $basis,
			'basis_label' => $basis === null ? null : ReportLabels::basis($basis),
			'confidence_label' => isset($item['confidence']) ? ReportLabels::confidence($item['confidence']) : null,
			'evidence' => $evidence($item['evidence_refs'] ?? []),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function items(mixed $items): array
	{
		return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
	}
}
