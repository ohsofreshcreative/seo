<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Contract;

use OsfSeo\Ai\Analysis\AnalysisType;

/**
 * Kontrakt odpowiedzi analiz rekomendacji (wersja 2 — docs/ARCHITECTURE.md, sekcja 24.6): wspólna koperta (podsumowanie, intencja,
 * ustalenia, rekomendacje, braki, ostrzeżenia, kontrole ręczne) i sekcje typowane (konspekt, propozycje title i meta description,
 * linkowanie wewnętrzne, tematy luki treści, pytania użytkowników, CTA, dane od klienta, ryzyka kanibalizacji). Każdy typ analizy ma
 * te same pola (`strict`); sekcje nieużywane przez typ są puste, a wymagania typu sprawdza `RecommendationValidator`.
 *
 * Podstawa twierdzenia: `fact` (wprost z dowodu mierzonego), `inference` (wniosek z dowodów), `hypothesis` (do sprawdzenia, bez dowodu).
 * Kontrakt v1 (analiza tematu z fazy A) bez zmian.
 */
final class RecommendationContract
{
	public const VERSION = 2;

	public const NAME = 'whack_a_mole_recommendations_v2';

	public const MAX_ITEMS = 8;

	public const MAX_REFS = 12;

	/** Najdłuższe dozwolone teksty (znaki). */
	public const LIMITS = [
		'summary' => 1200,
		'title' => 160,
		'explanation' => 1200,
		'description' => 1200,
		'rationale' => 1000,
		'target' => 200,
		'verification' => 400,
		'impact_rationale' => 400,
		'intent_explanation' => 600,
		'heading' => 120,
		'scope' => 600,
		'suggestion' => 200,
		'anchor' => 80,
		'url' => 300,
		'topic' => 120,
		'note' => 400,
		'question' => 200,
		'cta' => 120,
		'client_data' => 200,
		'risk' => 400,
		'item' => 300,
		'why_it_matters' => 400,
		'warning' => 400,
		'check' => 300,
		'reason' => 400,
	];

	/** Długość propozycji (znaki) — poza zakresem: odrzucenie (bez ucinania). */
	public const TITLE_LENGTH = [15, 70];

	public const META_LENGTH = [70, 170];

	/** Limity liczby elementów sekcji (pozostałe — MAX_ITEMS). */
	public const SECTION_ITEMS = [
		'title_suggestions' => 3,
		'meta_description_suggestions' => 3,
		'content_topics' => 12,
		'outline_sections' => 12,
		'cannibalization_risks' => 5,
		'cta_suggestions' => 3,
	];

	public const BASIS = ['fact', 'inference', 'hypothesis'];

	public const CONFIDENCE = ['high', 'medium', 'low'];

	public const FINDING_KINDS = ['problem', 'opportunity', 'strength', 'risk', 'observation'];

	public const RECOMMENDATION_TYPES = [
		'title', 'meta_description', 'h1', 'heading_structure', 'new_section', 'expand_section', 'internal_linking', 'faq',
		'intent_alignment', 'content_scope', 'page_role', 'consolidation_check', 'technical_check', 'other',
	];

	/** Pilność: `now` — pilne, `next` — w kolejnym kroku, `optional` — opcjonalne. */
	public const URGENCY = ['now', 'next', 'optional'];

	/** Jakościowy wpływ — bez prognoz ruchu i pozycji. */
	public const IMPACT = ['high', 'medium', 'low', 'unknown'];

	public const PRIORITIES = [1, 2, 3, 4, 5];

	public const INTENTS = ['informational', 'commercial', 'transactional', 'navigational', 'local', 'mixed', 'unknown'];

	/** Status tematu w analizie luk treści. */
	public const TOPIC_STATUSES = ['present_on_project_page', 'common_among_competitors', 'potentially_missing', 'shallow_on_project_page', 'not_recommended'];

	/** Pola koperty (kolejność = schemat). */
	public const FIELDS = [
		'contract_version', 'analysis_type', 'summary', 'search_intent', 'findings', 'recommendations', 'content_outline', 'title_suggestions',
		'meta_description_suggestions', 'internal_links', 'content_topics', 'user_questions', 'cta_suggestions', 'client_data_needed',
		'cannibalization_risks', 'missing_information', 'warnings', 'manual_checks',
	];

	/**
	 * JSON Schema odpowiedzi (odpowiedź strukturalna `strict` — same typy i wyliczenia; resztę sprawdza walidator).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array
	{
		$string = ['type' => 'string'];
		$strings = ['type' => 'array', 'items' => $string];
		$basis = ['type' => 'string', 'enum' => self::BASIS];
		$confidence = ['type' => 'string', 'enum' => self::CONFIDENCE];
		$suggestion = self::object(['text' => $string, 'rationale' => $string, 'evidence_refs' => $strings, 'basis' => $basis]);

		return self::object([
			'contract_version' => ['type' => 'integer', 'enum' => [self::VERSION]],
			'analysis_type' => ['type' => 'string', 'enum' => AnalysisType::RECOMMENDATIONS],
			'summary' => $string,
			'search_intent' => self::object([
				'primary' => ['type' => 'string', 'enum' => self::INTENTS],
				'explanation' => $string,
				'evidence_refs' => $strings,
				'basis' => $basis,
				'confidence' => $confidence,
			]),
			'findings' => ['type' => 'array', 'items' => self::object([
				'id' => $string,
				'kind' => ['type' => 'string', 'enum' => self::FINDING_KINDS],
				'title' => $string,
				'explanation' => $string,
				'evidence_refs' => $strings,
				'basis' => $basis,
				'confidence' => $confidence,
			])],
			'recommendations' => ['type' => 'array', 'items' => self::object([
				'id' => $string,
				'type' => ['type' => 'string', 'enum' => self::RECOMMENDATION_TYPES],
				'title' => $string,
				'description' => $string,
				'rationale' => $string,
				'target' => $string,
				'finding_ids' => $strings,
				'priority' => ['type' => 'integer', 'enum' => self::PRIORITIES],
				'urgency' => ['type' => 'string', 'enum' => self::URGENCY],
				'expected_impact' => ['type' => 'string', 'enum' => self::IMPACT],
				'impact_rationale' => $string,
				'evidence_refs' => $strings,
				'basis' => $basis,
				'confidence' => $confidence,
				'requires_manual_check' => ['type' => 'boolean'],
				'verification' => $string,
			])],
			'content_outline' => self::object([
				'h1' => $string,
				'sections' => ['type' => 'array', 'items' => self::object([
					'level' => ['type' => 'integer', 'enum' => [2, 3]],
					'heading' => $string,
					'scope' => $string,
					'evidence_refs' => $strings,
					'basis' => $basis,
				])],
			]),
			'title_suggestions' => ['type' => 'array', 'items' => $suggestion],
			'meta_description_suggestions' => ['type' => 'array', 'items' => $suggestion],
			'internal_links' => ['type' => 'array', 'items' => self::object([
				'from_url' => $string,
				'to_url' => $string,
				'anchor_suggestion' => $string,
				'rationale' => $string,
				'evidence_refs' => $strings,
				'basis' => $basis,
			])],
			'content_topics' => ['type' => 'array', 'items' => self::object([
				'label' => $string,
				'status' => ['type' => 'string', 'enum' => self::TOPIC_STATUSES],
				'evidence_refs' => $strings,
				'basis' => $basis,
				'confidence' => $confidence,
				'note' => $string,
			])],
			'user_questions' => $strings,
			'cta_suggestions' => $strings,
			'client_data_needed' => $strings,
			'cannibalization_risks' => ['type' => 'array', 'items' => self::object([
				'description' => $string,
				'evidence_refs' => $strings,
				'basis' => $basis,
			])],
			'missing_information' => ['type' => 'array', 'items' => self::object([
				'item' => $string,
				'why_it_matters' => $string,
			])],
			'warnings' => $strings,
			'manual_checks' => ['type' => 'array', 'items' => self::object([
				'check' => $string,
				'reason' => $string,
				'evidence_refs' => $strings,
			])],
		]);
	}

	/**
	 * @param array<string, array<string, mixed>> $properties
	 * @return array<string, mixed>
	 */
	private static function object(array $properties): array
	{
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => array_keys($properties),
			'properties' => $properties,
		];
	}
}
