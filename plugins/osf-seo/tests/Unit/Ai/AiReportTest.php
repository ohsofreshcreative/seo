<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Ai;

use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Analysis\Readiness;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Workspace\AiReport;
use OsfSeo\Ai\Workspace\AiReportText;
use OsfSeo\Ai\Workspace\EvidenceLabels;
use OsfSeo\Ai\Workspace\ReportLabels;
use PHPUnit\Framework\TestCase;

/**
 * Raport AI dla panelu i eksport tekstowy (STEP 17, faza D) — czyste funkcje zapisanego wyniku: kolejność rekomendacji, etykiety dowodów
 * zamiast kodów, ukrywanie pustych sekcji, kontrakt v1, eksport bez kodów technicznych i bez JSON-a, bezpieczne adresy.
 */
final class AiReportTest extends TestCase
{
	public function test_recommendations_are_sorted_by_priority_and_urgency_with_readable_evidence(): void
	{
		$result = FakeProvider::recommendations(['analysis_type' => AnalysisType::PAGE_OPTIMIZATION, 'refs' => self::refs(), 'action' => 'optimize']);
		$result['recommendations'][] = ['priority' => 1, 'urgency' => 'now', 'title' => 'Najpierw to', 'evidence_refs' => ['kw:7', 'nieznany:1'], 'basis' => 'fact', 'confidence' => 'high'] + $result['recommendations'][0];
		$report = AiReport::build(AnalysisType::PAGE_OPTIMIZATION, $result, self::context(), ['readiness' => ['limitations' => ['page_content_partial']]]);

		self::assertSame('Najpierw to', $report['top']['title']);
		self::assertSame(['Najpierw to'], array_column($report['now'], 'title'));
		self::assertSame([1], array_values(array_unique(array_slice(array_column($report['recommendations'], 'priority'), 0, 1))));
		self::assertSame(['Fakt', 'Wysoka pewność', 'Priorytet 1 z 5', 'Teraz'], [$report['top']['basis_label'], $report['top']['confidence_label'], $report['top']['priority_label'], $report['top']['urgency_label']]);
		self::assertSame([['label' => 'Fraza „pozycjonowanie stron”', 'url' => null], ['label' => 'Dowód z danych analizy', 'url' => null]], $report['top']['evidence']);
		self::assertSame([ReportLabels::readinessCode('page_content_partial')], $report['limitations']);
		self::assertNull($report['outline'], 'Pusta sekcja typowana jest ukryta.');
		self::assertFalse($report['candidate']);
	}

	public function test_evidence_labels_come_only_from_the_stored_context_and_links_are_http_only(): void
	{
		$labels = EvidenceLabels::fromContext(self::context());

		self::assertSame('Strona projektu (pobrana 2026-10-01)', $labels['page:01AAAAAAAAAAAAAAAAAAAAAAAA']['label']);
		self::assertSame('https://example.pl/pozycjonowanie/', $labels['page:01AAAAAAAAAAAAAAAAAAAAAAAA']['url']);
		self::assertSame('Strona konkurenta konkurent.pl (#2 w SERP)', $labels['cpage:01BBBBBBBBBBBBBBBBBBBBBBBB']['label']);
		self::assertNull($labels['cpage:01BBBBBBBBBBBBBBBBBBBBBBBB']['url'], 'Adres inny niż http(s) nie staje się odnośnikiem.');
		self::assertSame('Wynik SERP #3 — wynik.example', $labels['serp:3']['label']);
		self::assertSame('Decyzja Strategii: Optymalizacja strony', $labels['decision']['label']);
		self::assertSame('Temat „SEO dla firm”', $labels['site:1']['label']);

		foreach ($labels as $ref => $entry) {
			self::assertDoesNotMatchRegularExpression('/\b(kw|cpage|page|serp|site):[0-9A-Z]/', $entry['label'], $ref);
		}
	}

	public function test_brief_sections_and_text_exports_are_plain_text_without_technical_codes(): void
	{
		$result = FakeProvider::recommendations(['analysis_type' => AnalysisType::NEW_PAGE_BRIEF, 'refs' => self::refs(), 'action' => 'create', 'constraints' => ['candidate_page']]);
		$report = AiReport::build(AnalysisType::NEW_PAGE_BRIEF, $result, self::context(), null);

		self::assertTrue($report['candidate']);
		self::assertNotNull($report['outline']);
		self::assertNotSame('', $report['outline']['h1']);

		$meta = ['topic' => 'pozycjonowanie stron', 'date' => '2026-10-08'];
		$brief = AiReportText::brief($report, $meta);
		self::assertStringContainsString('Kandydat na nową stronę', $brief);
		self::assertStringContainsString('H1: ' . $report['outline']['h1'], $brief);

		foreach ([AiReportText::summary($report, $meta), AiReportText::recommendations($report, $meta), $brief] as $text) {
			self::assertStringStartsWith(ReportLabels::type(AnalysisType::NEW_PAGE_BRIEF) . ' — temat: pozycjonowanie stron — 2026-10-08', $text);
			self::assertStringNotContainsString('{', $text);

			foreach (['new_page_brief', 'kw:', 'cpage:', 'evidence_refs', 'fingerprint', 'USD'] as $needle) {
				self::assertStringNotContainsString($needle, $text);
			}

			self::assertStringEndsWith("hipotezy do sprawdzenia, a nie gwarancja wzrostu widoczności.\n", $text);
		}
	}

	public function test_topic_analysis_contract_v1_maps_to_the_same_report_fields(): void
	{
		$report = AiReport::build(AnalysisType::TOPIC_ANALYSIS, [
			'contract_version' => 1,
			'summary' => 'Podsumowanie',
			'findings' => [['id' => 'F1', 'kind' => 'problem', 'title' => 'Spadek', 'explanation' => 'Opis', 'evidence_refs' => ['gsc'], 'basis' => 'evidence', 'confidence' => 'medium']],
			'recommendations' => [['id' => 'R1', 'action' => 'Sprawdź stronę', 'rationale' => 'Bo', 'finding_ids' => ['F1'], 'expected_impact' => 'medium', 'impact_rationale' => '', 'evidence_refs' => ['gsc'], 'basis' => 'hypothesis', 'priority' => 2, 'requires_manual_check' => false]],
			'missing_information' => [],
			'caveats' => ['Dane GSC są średnią pozycją.'],
			'manual_checks' => [],
		], self::context());

		self::assertSame(['Sprawdź stronę', 'Hipoteza', ['Spadek'], 'Średni'], [$report['top']['title'], $report['top']['basis_label'], $report['top']['findings'], $report['top']['impact_label']]);
		self::assertSame('Z dowodów', $report['findings'][0]['basis_label']);
		self::assertSame(['Dane GSC są średnią pozycją.'], $report['warnings']);
		self::assertSame('Analiza tematu', $report['type_label']);
	}

	/**
	 * Faza E (obserwacja ze stagingu): raport dostawcy testowego pokazywał kody `competitor_pages_not_fetched`, `page_index_incomplete`,
	 * `keywords_omitted`, `context_reduced`. Prezentacja zamienia znane kody i odwołania na polskie etykiety — zapisany wynik bez zmian.
	 */
	public function test_report_text_has_no_technical_codes_and_stored_result_is_unchanged(): void
	{
		$codes = ['competitor_pages_not_fetched', 'page_index_incomplete', 'keywords_omitted', 'context_reduced'];
		$result = FakeProvider::recommendations(['analysis_type' => AnalysisType::CONTENT_GAP, 'refs' => self::refs(), 'action' => 'optimize', 'data_gaps' => $codes]);
		$result['summary'] = 'Fraza kw:7 i strona cpage:01BBBBBBBBBBBBBBBBBBBBBBBB; nieznany_kod_x zostaje; kw:999 zostaje.';
		$stored = $result;
		$report = AiReport::build(AnalysisType::CONTENT_GAP, $result, self::context(), ['readiness' => ['limitations' => ['page_index_incomplete']]]);

		self::assertSame($stored, $result, 'Wynik zapisany w historii nie jest zmieniany.');
		self::assertSame('Fraza Fraza „pozycjonowanie stron” i strona Strona konkurenta konkurent.pl (#2 w SERP); nieznany_kod_x zostaje; kw:999 zostaje.', $report['summary']);

		$texts = [AiReportText::summary($report, ['topic' => 't', 'date' => 'd']), AiReportText::recommendations($report, ['topic' => 't', 'date' => 'd']), (string) json_encode($report['missing'], JSON_UNESCAPED_UNICODE)];

		foreach ($texts as $text) {
			foreach ($codes as $code) {
				self::assertStringNotContainsString($code, $text);
			}
		}

		self::assertStringContainsString('nie pobrano stron konkurencji z SERP', implode(' ', array_column($report['missing'], 'item')));
		self::assertSame('Dane GSC są średnią', AiReport::humanize('Dane GSC są średnią'), 'Tekst bez kodów — bez zmian.');
		self::assertSame('Brak: projekt nie ma połączenia z Google Search Console — dane GSC są nieznane (nie zerowe)', AiReport::humanize('Brak: no_gsc_connection'));
	}

	public function test_labels_never_expose_codes(): void
	{
		foreach (array_keys(Readiness::MEANINGS) as $code) {
			self::assertArrayHasKey($code, ReportLabels::READINESS_CODES, 'Brak polskiej etykiety: ' . $code);
		}

		self::assertSame('Ograniczenie danych analizy.', ReportLabels::readinessCode('nowy_kod'));
		self::assertSame('Analiza nie została wykonana.', ReportLabels::error('nieznany_blad'));
		self::assertSame('Nieznany status', ReportLabels::status('dziwny'));
		self::assertSame('Odrzucona przez użytkownika', ReportLabels::status('succeeded', 'rejected'));
		self::assertSame('Analiza AI', ReportLabels::type('cos_innego'));
		self::assertNull(ReportLabels::error(null));
	}

	/**
	 * @return list<string>
	 */
	private static function refs(): array
	{
		return ['decision', 'gsc', 'kw:7', 'page:01AAAAAAAAAAAAAAAAAAAAAAAA', 'cpage:01BBBBBBBBBBBBBBBBBBBBBBBB', 'serp', 'serp:3', 'site:1', 'target', 'topic'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function context(): array
	{
		return [
			'topic' => ['label' => 'pozycjonowanie stron'],
			'decision' => ['action' => 'optimize'],
			'keywords' => [['ref' => 'kw:7', 'keyword' => 'pozycjonowanie stron']],
			'evidence' => [
				'gsc' => ['provenance' => ['as_of' => '2026-10-04']],
				'serp' => ['provenance' => ['as_of' => '2026-10-02 10:00:00'], 'top_results' => [['ref' => 'serp:3', 'serp_rank_group' => 3, 'domain' => 'wynik.example', 'url' => 'https://wynik.example/']]],
				'competitor_pages' => ['items' => [['ref' => 'cpage:01BBBBBBBBBBBBBBBBBBBBBBBB', 'domain' => 'konkurent.pl', 'url' => 'javascript:alert(1)', 'serp' => ['serp_rank_group' => 2]]]],
			],
			'target_page' => ['url' => 'https://example.pl/pozycjonowanie/', 'page_content' => ['ref' => 'page:01AAAAAAAAAAAAAAAAAAAAAAAA', 'url' => 'https://example.pl/pozycjonowanie/', 'provenance' => ['as_of' => '2026-10-01 08:00:00']]],
			'site' => ['pages' => [['ref' => 'site:1', 'label' => 'SEO dla firm', 'url' => 'https://example.pl/seo/']]],
		];
	}
}
