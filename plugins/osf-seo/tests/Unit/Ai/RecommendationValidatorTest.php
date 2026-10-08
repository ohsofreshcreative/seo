<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Ai;

use Closure;
use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Context\AiContext;
use OsfSeo\Ai\Contract\RecommendationContract;
use OsfSeo\Ai\Contract\RecommendationValidator;
use OsfSeo\Ai\Contract\ValidationResult;
use OsfSeo\Tests\Support\AiFakes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Walidacja odpowiedzi analiz rekomendacji (kontrakt v2, D107): odwołania do dowodów (10–13), title i meta (14), prognozy (15), liczba
 * słów (16), diagnostyka parsera (17), przeskoki nagłówków (18), etykiety interfejsu i karty realizacji jako tematy (19), uszkodzony JSON
 * (29), zła struktura (30), sekcje typu, ograniczenia działania Strategii, kopiowanie treści konkurencji i działania nieodwracalne.
 * Każde naruszenie odrzuca całą odpowiedź (bez częściowego wyniku).
 */
final class RecommendationValidatorTest extends TestCase
{
	public function test_34_fake_provider_samples_of_all_three_types_pass_the_contract(): void
	{
		foreach ([AnalysisType::PAGE_OPTIMIZATION, AnalysisType::NEW_PAGE_BRIEF, AnalysisType::CONTENT_GAP] as $type) {
			$context = $this->context($type);
			$result = $this->validate($context, AiFakes::recommendation($context));

			self::assertTrue($result->valid(), $type . ': ' . json_encode($result->errors));
			self::assertSame([RecommendationContract::VERSION, $type], [$result->result['contract_version'], $result->result['analysis_type']]);
			self::assertStringStartsWith('[TEST]', $result->result['summary'], 'Atrapa nie udaje analizy.');
		}
	}

	public function test_10_valid_refs_from_the_context_are_accepted(): void
	{
		$context = $this->context(AnalysisType::CONTENT_GAP);
		$sample = AiFakes::recommendation($context);
		$sample['findings'][0]['evidence_refs'] = ['page:01M4BRH0000000000000000001', 'cpage:01M4BRH2000000000000000001', 'kw:' . AiFakes::LEADER, 'site:2'];
		$sample['findings'][0]['basis'] = 'fact';
		$result = $this->validate($context, $sample);

		self::assertTrue($result->valid(), (string) json_encode($result->errors));
		self::assertSame(['page:01M4BRH0000000000000000001', 'cpage:01M4BRH2000000000000000001', 'kw:' . AiFakes::LEADER, 'site:2'], $result->result['findings'][0]['evidence_refs']);
	}

	/**
	 * @return iterable<string, array{0: string, 1: Closure(array<string, mixed>): array<string, mixed>, 2: string, 3: string}>
	 */
	public static function violations(): iterable
	{
		$po = AnalysisType::PAGE_OPTIMIZATION;
		$cg = AnalysisType::CONTENT_GAP;
		$npb = AnalysisType::NEW_PAGE_BRIEF;
		$recommendation = static fn (array $changes): Closure => static function (array $sample) use ($changes): array {
			$sample['recommendations'][0] = array_replace($sample['recommendations'][0], $changes);

			return $sample;
		};
		$topic = static fn (array $changes): Closure => static function (array $sample) use ($changes): array {
			$sample['content_topics'][] = array_replace(['label' => 'Etapy współpracy przy pozycjonowaniu', 'status' => 'potentially_missing', 'evidence_refs' => ['cpage:01M4BRH2000000000000000001'], 'basis' => 'hypothesis', 'confidence' => 'low', 'note' => ''], $changes);

			return $sample;
		};
		$title = static fn (string $text, string $section = 'title_suggestions'): Closure => static function (array $sample) use ($text, $section): array {
			$sample[$section] = [['text' => $text, 'rationale' => 'Uzasadnienie.', 'evidence_refs' => ['kw:' . AiFakes::LEADER], 'basis' => 'inference']];

			return $sample;
		};

		// 11–13: odwołania i podstawa twierdzeń.
		yield '11 invented ref' => [$po, $recommendation(['evidence_refs' => ['page:999']]), '$.recommendations[0].evidence_refs[0]', 'unknown_ref'];
		yield '11 text id is not a ref' => [$po, $recommendation(['evidence_refs' => ['txt:1']]), '$.recommendations[0].evidence_refs[0]', 'unknown_ref'];
		yield '12 fact without refs' => [$po, $recommendation(['evidence_refs' => [], 'basis' => 'fact']), '$.recommendations[0].evidence_refs', 'fact_without_refs'];
		yield '12 inference without refs' => [$po, $recommendation(['evidence_refs' => [], 'basis' => 'inference']), '$.recommendations[0].evidence_refs', 'inference_without_refs'];
		yield '13 fact from heuristics only' => [$po, $recommendation(['evidence_refs' => ['decision', 'target'], 'basis' => 'fact']), '$.recommendations[0].basis', 'fact_from_heuristic'];
		yield '13 absence as fact' => [$cg, $topic(['basis' => 'fact']), '$.content_topics[2].basis', 'absence_as_fact'];
		yield 'unknown finding id' => [$po, $recommendation(['finding_ids' => ['F9']]), '$.recommendations[0].finding_ids[0]', 'unknown_finding'];

		// 14: title i meta description.
		yield '14 title too short' => [$po, $title('Krótki'), '$.title_suggestions[0].text', 'too_short'];
		yield '14 title too long' => [$po, $title(str_repeat('Pozycjonowanie stron firmowych ', 4)), '$.title_suggestions[0].text', 'too_long'];
		yield '14 title keyword stuffing' => [$po, $title('Pozycjonowanie stron, pozycjonowanie SEO, pozycjonowanie'), '$.title_suggestions[0].text', 'keyword_stuffing'];
		yield '14 meta too short' => [$po, $title('Opis strony usług pozycjonowania.', 'meta_description_suggestions'), '$.meta_description_suggestions[0].text', 'too_short'];

		// 15: prognozy i obietnice.
		yield '15 numeric forecast' => [$po, $recommendation(['impact_rationale' => 'Ruch wzrośnie o 30%.']), '$.recommendations[0]', 'numeric_forecast'];
		yield '15 ranking forecast' => [$po, $recommendation(['description' => 'Po zmianie strona awansuje do TOP3 w Google.']), '$.recommendations[0]', 'ranking_forecast'];
		yield '15 promise' => [$po, $recommendation(['rationale' => 'To gwarantuje lepsze wyniki.']), '$', 'promise'];
		yield '15 google requirement' => [$po, $recommendation(['rationale' => 'Google wymaga dłuższych opisów usług.']), '$', 'google_requirement_claim'];

		// 16: cele liczby słów.
		yield '16 word count target' => [$po, $recommendation(['description' => 'Rozbuduj treść do 1500 słów.']), '$.recommendations[0]', 'word_count_target'];
		yield '16 longer than competitors' => [$po, $recommendation(['rationale' => 'Tekst powinien być dłuższy niż konkurencja.']), '$.recommendations[0]', 'word_count_target'];

		// 17–18: diagnostyka parsera i nagłówki.
		yield '17 parse errors as SEO issue' => [$po, $recommendation(['title' => 'Napraw 180 błędów parsowania HTML']), '$.recommendations[0]', 'parser_diagnostic_as_issue'];
		yield '17 parse errors in a finding' => [$po, static function (array $sample): array {
			$sample['findings'][0]['explanation'] = 'Strona ma parse errors w kodzie.';

			return $sample;
		}, '$.findings', 'parser_diagnostic_as_issue'];
		yield '18 skipped levels as ranking factor' => [$po, $recommendation(['rationale' => 'Przeskoki poziomów nagłówków obniżają ranking strony.']), '$.recommendations[0]', 'heading_levels_overstated'];
		yield '18 heading structure with high impact' => [$po, $recommendation(['type' => 'heading_structure', 'expected_impact' => 'high']), '$.recommendations[0]', 'heading_levels_overstated'];

		// 19: etykiety interfejsu jako tematy.
		yield '19 CTA as topic' => [$cg, $topic(['label' => 'Zobacz więcej']), '$.content_topics[2].label', 'ui_label_as_topic'];
		yield '19 caps category as topic' => [$cg, $topic(['label' => 'STRONY WWW']), '$.content_topics[2].label', 'ui_label_as_topic'];

		// Działania nieodwracalne, meta description, FAQ, duplikaty, kopiowanie treści konkurencji.
		yield 'redirect without manual check' => [$po, $recommendation(['description' => 'Ustaw przekierowanie 301 na stronę główną.', 'requires_manual_check' => false]), '$.recommendations[0]', 'destructive_without_check'];
		yield 'canonical as fact' => [$po, $recommendation(['type' => 'consolidation_check', 'description' => 'Zmień canonical na inną stronę.', 'basis' => 'fact', 'evidence_refs' => ['page:01M4BRH0000000000000000001']]), '$.recommendations[0]', 'destructive_without_check'];
		yield 'two faq recommendations' => [$po, static function (array $sample): array {
			$sample['recommendations'][0]['type'] = 'faq';
			$sample['recommendations'][1]['type'] = 'faq';

			return $sample;
		}, '$.recommendations[1]', 'faq_unjustified'];
		yield 'duplicate recommendation' => [$po, static function (array $sample): array {
			$sample['recommendations'][1]['title'] = $sample['recommendations'][0]['title'];

			return $sample;
		}, '$.recommendations[1]', 'duplicate_recommendation'];
		yield 'copied competitor text' => [$cg, $recommendation(['description' => 'Dodaj akapit: pozycjonowanie stron to długofalowa praca nad widocznością witryny w wynikach wyszukiwania.']), '$.recommendations[0]', 'copied_competitor_text'];
		yield 'absence claim' => [$po, $recommendation(['rationale' => 'Serwis nie ma żadnej podstrony o tym temacie.']), '$', 'absence_claim'];
		yield 'unknown internal link url' => [$po, static function (array $sample): array {
			$sample['internal_links'] = [['from_url' => 'https://example.pl/nieznana/', 'to_url' => '', 'anchor_suggestion' => 'pozycjonowanie stron', 'rationale' => 'Uzasadnienie.', 'evidence_refs' => ['site:1'], 'basis' => 'inference']];

			return $sample;
		}, '$.internal_links[0].from_url', 'unknown_url'];

		// Sekcje typu i zgodność z typem żądania.
		yield 'analysis type mismatch' => [$po, static fn (array $sample): array => ['analysis_type' => AnalysisType::CONTENT_GAP] + $sample, '$.analysis_type', 'analysis_type'];
		yield 'page optimization with content topics' => [$po, $topic([]), '$.content_topics', 'section_not_allowed'];
		yield 'page optimization without recommendations' => [$po, static fn (array $sample): array => ['recommendations' => []] + $sample, '$.recommendations', 'section_required'];
		yield 'brief without outline' => [$npb, static fn (array $sample): array => ['content_outline' => ['h1' => '', 'sections' => []]] + $sample, '$.content_outline', 'outline_required'];
		yield 'brief without title suggestions' => [$npb, static fn (array $sample): array => ['title_suggestions' => []] + $sample, '$.title_suggestions', 'section_required'];
		yield 'content gap without topics' => [$cg, static fn (array $sample): array => ['content_topics' => []] + $sample, '$.content_topics', 'section_required'];
		yield 'content gap with CTA suggestions' => [$cg, static fn (array $sample): array => ['cta_suggestions' => ['Zamów wycenę']] + $sample, '$.cta_suggestions', 'section_not_allowed'];
		yield 'outline word count' => [$npb, static function (array $sample): array {
			$sample['content_outline']['sections'][0]['scope'] = 'Sekcja na 800 słów.';

			return $sample;
		}, '$.content_outline.sections[0]', 'word_count_target'];

		// 30: zła struktura.
		yield '30 missing field' => [$po, static function (array $sample): array {
			unset($sample['warnings']);

			return $sample;
		}, '$.warnings', 'missing_field'];
		yield '30 unexpected field' => [$po, static fn (array $sample): array => $sample + ['content_score' => 100], '$.content_score', 'unexpected_field'];
		yield '30 wrong type' => [$po, $recommendation(['priority' => 'high']), '$.recommendations[0].priority', 'invalid_enum'];
		yield '30 wrong enum' => [$po, $recommendation(['urgency' => 'asap']), '$.recommendations[0].urgency', 'invalid_enum'];
		yield '30 too many items' => [$po, static function (array $sample): array {
			$sample['warnings'] = array_fill(0, RecommendationContract::MAX_ITEMS + 1, 'Ostrzeżenie.');

			return $sample;
		}, '$.warnings', 'too_many_items'];
		yield '30 contract version' => [$po, static fn (array $sample): array => ['contract_version' => 1] + $sample, '$.contract_version', 'contract_version'];
	}

	/**
	 * @param Closure(array<string, mixed>): array<string, mixed> $mutate
	 */
	#[DataProvider('violations')]
	public function test_violation_rejects_the_whole_response(string $type, Closure $mutate, string $path, string $code): void
	{
		$context = $this->context($type);
		$result = $this->validate($context, $mutate(AiFakes::recommendation($context)));

		self::assertFalse($result->valid());
		self::assertNull($result->result, 'Bez częściowego wyniku.');
		self::assertContains(['path' => $path, 'code' => $code], $result->errors, (string) json_encode($result->errors));
	}

	public function test_13_hypothesis_without_refs_is_allowed_and_absence_stays_a_hypothesis(): void
	{
		$context = $this->context(AnalysisType::CONTENT_GAP);
		$sample = AiFakes::recommendation($context);
		$sample['recommendations'][0]['evidence_refs'] = [];
		$sample['recommendations'][0]['basis'] = 'hypothesis';
		$sample['content_topics'][1]['basis'] = 'hypothesis';
		$result = $this->validate($context, $sample);

		self::assertTrue($result->valid(), (string) json_encode($result->errors));
		self::assertSame('hypothesis', $result->result['content_topics'][1]['basis']);

		// Snapshot projektu niepełny: brak tematu tylko z niską pewnością.
		$project = AiFakes::pageEvidence(snapshot: ['content_quality' => 'partial']);
		$partial = AiFakes::analysisContext(AnalysisType::CONTENT_GAP, source: ['pages' => AiFakes::pages($project)]);
		$sample = AiFakes::recommendation($partial);
		$sample['content_topics'][1]['confidence'] = 'medium';
		self::assertContains(['path' => '$.content_topics[1].confidence', 'code' => 'absence_unconfirmed'], $this->validate($partial, $sample)->errors);
	}

	public function test_14_valid_title_and_meta_suggestions(): void
	{
		$context = $this->context(AnalysisType::PAGE_OPTIMIZATION);
		$sample = AiFakes::recommendation($context);
		$sample['title_suggestions'] = [['text' => 'Pozycjonowanie stron dla firm — audyt i treści', 'rationale' => 'Fraza główna na początku.', 'evidence_refs' => ['kw:' . AiFakes::LEADER], 'basis' => 'inference']];
		$sample['meta_description_suggestions'] = [['text' => 'Sprawdź, jak wygląda pozycjonowanie stron: audyt techniczny, plan treści i stała analiza fraz dla Twojej firmy.', 'rationale' => 'Opis zakresu usługi.', 'evidence_refs' => ['page:01M4BRH0000000000000000001'], 'basis' => 'inference']];
		$result = $this->validate($context, $sample);

		self::assertTrue($result->valid(), (string) json_encode($result->errors));
		self::assertCount(1, $result->result['title_suggestions']);
	}

	public function test_17_18_ohsofresh_like_page_does_not_force_false_alarms_but_rejects_them(): void
	{
		$project = AiFakes::pageEvidence('https://example.pl/', AiFakes::ohSoFreshHtml());
		$context = AiFakes::analysisContext(AnalysisType::PAGE_OPTIMIZATION, source: ['pages' => AiFakes::pages($project, 2)]);

		// Atrapa (bez żadnych alarmów o meta, parserze i nagłówkach) przechodzi.
		self::assertTrue($this->validate($context, AiFakes::recommendation($context))->valid());

		// Meta description „nie wykryto w pobranym HTML” — rekomendacja tylko z ręczną weryfikacją.
		$meta = AiFakes::recommendation($context);
		$meta['recommendations'][0] = array_replace($meta['recommendations'][0], ['type' => 'meta_description', 'title' => 'Sprawdź opis meta strony', 'requires_manual_check' => false]);
		self::assertContains(['path' => '$.recommendations[0].requires_manual_check', 'code' => 'manual_check_required'], $this->validate($context, $meta)->errors);

		$meta['recommendations'][0]['requires_manual_check'] = true;
		self::assertTrue($this->validate($context, $meta)->valid());

		// Opcjonalna uwaga o strukturze nagłówków (niski wpływ, bez pilności) jest dozwolona.
		$headings = AiFakes::recommendation($context);
		$headings['recommendations'][1] = array_replace($headings['recommendations'][1], ['type' => 'heading_structure', 'title' => 'Rozważ uporządkowanie poziomów nagłówków', 'expected_impact' => 'low', 'urgency' => 'optional']);
		self::assertTrue($this->validate($context, $headings)->valid(), (string) json_encode($this->validate($context, $headings)->errors));
	}

	public function test_19_portfolio_cards_are_not_content_topics_of_a_content_gap(): void
	{
		$pages = AiFakes::pages(null, 2);
		$pages['competitors'][0] = AiFakes::pageEvidence('https://wynik-1.example/seo/', AiFakes::ohSoFreshHtml(), 'fresh', [], '01M4BRH2000000000000000001', '2026-01-11 09:00:00') + ['serp' => $pages['competitors'][0]['serp']];
		$context = AiFakes::analysisContext(AnalysisType::CONTENT_GAP, source: ['pages' => $pages]);
		self::assertContains('Hotel Alpejski', $context->cardHeadings());

		$sample = AiFakes::recommendation($context);
		$sample['content_topics'][] = ['label' => 'Hotel Alpejski', 'status' => 'common_among_competitors', 'evidence_refs' => ['cpage:01M4BRH2000000000000000001'], 'basis' => 'inference', 'confidence' => 'low', 'note' => ''];
		self::assertContains(['path' => '$.content_topics[2].label', 'code' => 'ui_label_as_topic'], $this->validate($context, $sample)->errors);
	}

	public function test_strategy_constraints_limit_the_recommendations(): void
	{
		// monitor → utrzymanie: bez wpływu „high”.
		$monitor = AiFakes::analysisContext(AnalysisType::PAGE_OPTIMIZATION, ['decision' => ['action' => 'monitor']]);
		$sample = AiFakes::recommendation($monitor);
		$sample['recommendations'][0]['expected_impact'] = 'high';
		self::assertContains(['path' => '$.recommendations[0]', 'code' => 'maintenance_overreach'], $this->validate($monitor, $sample)->errors);

		// investigate (wybór jawny) → najpierw weryfikacja: bez wysokiej pewności.
		$investigate = AiFakes::analysisContext(AnalysisType::PAGE_OPTIMIZATION, ['decision' => ['action' => 'investigate']], explicit: true);
		$sample = AiFakes::recommendation($investigate);
		$sample['recommendations'][0]['confidence'] = 'high';
		self::assertContains(['path' => '$.recommendations[0].confidence', 'code' => 'overconfident'], $this->validate($investigate, $sample)->errors);

		// consolidate → weryfikacja konfliktu jako kontrola ręczna jest poprawna.
		$consolidate = AiFakes::analysisContext(AnalysisType::PAGE_OPTIMIZATION, ['decision' => ['action' => 'consolidate']]);
		$sample = AiFakes::recommendation($consolidate);
		$sample['recommendations'][0] = array_replace($sample['recommendations'][0], ['type' => 'consolidation_check', 'title' => 'Sprawdź, czy przekierowanie jednej z konkurujących stron jest uzasadnione', 'requires_manual_check' => true, 'basis' => 'hypothesis', 'evidence_refs' => []]);
		self::assertTrue($this->validate($consolidate, $sample)->valid(), (string) json_encode($this->validate($consolidate, $sample)->errors));
	}

	public function test_limitations_must_be_disclosed_when_readiness_is_partial(): void
	{
		$project = AiFakes::pageEvidence(cache: 'stale');
		$context = AiFakes::analysisContext(AnalysisType::PAGE_OPTIMIZATION, source: ['pages' => AiFakes::pages($project)]);
		self::assertSame('partial', $context->body['analysis']['readiness']);
		$sample = AiFakes::recommendation($context);
		$sample['warnings'] = [];
		$sample['missing_information'] = [];

		self::assertContains(['path' => '$.warnings', 'code' => 'limitations_not_disclosed'], $this->validate($context, $sample)->errors);
	}

	public function test_29_malformed_json_is_a_controlled_error(): void
	{
		$context = $this->context(AnalysisType::PAGE_OPTIMIZATION);

		foreach (['{"contract_version": 2, "analysis_type": ', 'Oto analiza: {...}', '[]', ''] as $text) {
			$result = (new RecommendationValidator())->validate($text, $context);
			self::assertFalse($result->valid());
			self::assertNull($result->result);
			self::assertContains($result->errors[0]['code'], ['invalid_json', 'not_object'], $text);
		}
	}

	private function context(string $type): AiContext
	{
		return AiFakes::analysisContext($type, $type === AnalysisType::NEW_PAGE_BRIEF ? [
			'decision' => ['action' => 'create', 'action_label' => 'Kandydat na nową stronę', 'reason' => 'no_page', 'basis' => [], 'checks' => []],
			'target' => ['state' => 'none', 'url' => null, 'manual_none' => false],
		] : []);
	}

	/**
	 * @param array<string, mixed> $sample
	 */
	private function validate(AiContext $context, array $sample): ValidationResult
	{
		return (new RecommendationValidator())->validate((string) json_encode($sample, JSON_UNESCAPED_UNICODE), $context);
	}
}
