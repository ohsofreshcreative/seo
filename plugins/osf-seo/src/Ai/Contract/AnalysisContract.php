<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Contract;

/**
 * Kontrakt odpowiedzi analizy tematu (wersja 1 — docs/ARCHITECTURE.md, sekcja 22.6): podsumowanie, problemy i szanse, rekomendacje
 * z uzasadnieniem i jakościowym wpływem, odwołania do dowodów, brakujące informacje, zastrzeżenia i kontrole ręczne.
 *
 * Schemat jest wysyłany jako odpowiedź strukturalna (`strict`: wszystkie pola wymagane, bez dodatkowych pól) — z samymi typami
 * i wyliczeniami. Limity długości i liczby elementów, odwołania do dowodów, podstawa twierdzeń i zakaz prognoz liczbowych
 * sprawdza wyłącznie `OutputValidator` po stronie PHP (deklaracja modelu nie jest dowodem poprawności).
 */
final class AnalysisContract
{
	public const VERSION = 1;

	public const NAME = 'whack_a_mole_topic_analysis_v1';

	public const MAX_ITEMS = 8;

	public const MAX_REFS = 12;

	/** Najdłuższe dozwolone teksty (znaki). */
	public const LIMITS = [
		'summary' => 1200,
		'title' => 160,
		'explanation' => 1200,
		'action' => 300,
		'rationale' => 1200,
		'impact_rationale' => 400,
		'item' => 300,
		'why_it_matters' => 400,
		'caveat' => 400,
		'check' => 300,
		'reason' => 400,
	];

	public const KINDS = ['problem', 'opportunity'];

	public const BASIS = ['evidence', 'hypothesis'];

	public const CONFIDENCE = ['high', 'medium', 'low'];

	/** Jakościowy wpływ — bez prognoz ruchu (D93). */
	public const IMPACT = ['high', 'medium', 'low', 'unknown'];

	public const PRIORITIES = [1, 2, 3, 4, 5];

	/**
	 * JSON Schema odpowiedzi (format odpowiedzi strukturalnej).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array
	{
		$string = ['type' => 'string'];
		$refs = ['type' => 'array', 'items' => $string];

		return self::object([
			'contract_version' => ['type' => 'integer', 'enum' => [self::VERSION]],
			'summary' => $string,
			'findings' => ['type' => 'array', 'items' => self::object([
				'id' => $string,
				'kind' => ['type' => 'string', 'enum' => self::KINDS],
				'title' => $string,
				'explanation' => $string,
				'evidence_refs' => $refs,
				'basis' => ['type' => 'string', 'enum' => self::BASIS],
				'confidence' => ['type' => 'string', 'enum' => self::CONFIDENCE],
			])],
			'recommendations' => ['type' => 'array', 'items' => self::object([
				'id' => $string,
				'action' => $string,
				'rationale' => $string,
				'finding_ids' => $refs,
				'expected_impact' => ['type' => 'string', 'enum' => self::IMPACT],
				'impact_rationale' => $string,
				'evidence_refs' => $refs,
				'basis' => ['type' => 'string', 'enum' => self::BASIS],
				'priority' => ['type' => 'integer', 'enum' => self::PRIORITIES],
				'requires_manual_check' => ['type' => 'boolean'],
			])],
			'missing_information' => ['type' => 'array', 'items' => self::object([
				'item' => $string,
				'why_it_matters' => $string,
			])],
			'caveats' => ['type' => 'array', 'items' => $string],
			'manual_checks' => ['type' => 'array', 'items' => self::object([
				'check' => $string,
				'reason' => $string,
				'evidence_refs' => $refs,
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
