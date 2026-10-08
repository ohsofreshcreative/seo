<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Analysis;

/**
 * Zgodność typu analizy z działaniem Strategii (STEP 16) — AI nie zastępuje silnika Strategii i nie nadpisuje jego decyzji (D105).
 *
 * - `allowed` — typ zgodny z działaniem,
 * - `explicit` — tylko po świadomym wyborze użytkownika (CLI `--override-action`), np. analiza tematu „Do sprawdzenia”,
 * - `blocked` — typ sprzeczny z działaniem (np. brief nowej strony dla optymalizacji istniejącej strony).
 *
 * Ograniczenia (`constraints`) trafiają do kontekstu i instrukcji, a część z nich egzekwuje walidator wyniku:
 * - `existing_page` — analiza istniejącej strony (bez proponowania nowej podstrony zamiast niej),
 * - `recovery_focus` — przyczyny spadku i odzyskanie widoczności przed rozbudową,
 * - `candidate_page` — brief oznaczony jako „Kandydat na nową stronę” (brak znanej strony ≠ brak strony),
 * - `conflict_review` — konflikt adresów: kroki do ręcznej weryfikacji, bez nakazów przekierowań, canonical i usuwania stron,
 * - `maintenance_focus` — utrzymanie widoczności i małe usprawnienia (bez wpływu „high” i bez dużej przebudowy),
 * - `verify_first` — decyzja Strategii niejednoznaczna: najpierw weryfikacja, wnioski z niższą pewnością.
 */
final class ActionCompatibility
{
	public const ALLOWED = 'allowed';

	public const EXPLICIT = 'explicit';

	public const BLOCKED = 'blocked';

	/** @var array<string, array<string, array{mode: string, constraints: list<string>, requires: list<string>}>> */
	public const TABLE = [
		'optimize' => [
			AnalysisType::PAGE_OPTIMIZATION => ['mode' => self::ALLOWED, 'constraints' => ['existing_page'], 'requires' => ['target_page', 'page_snapshot']],
			AnalysisType::NEW_PAGE_BRIEF => ['mode' => self::BLOCKED, 'constraints' => [], 'requires' => []],
			AnalysisType::CONTENT_GAP => ['mode' => self::ALLOWED, 'constraints' => ['existing_page'], 'requires' => ['page_snapshot', 'competitor_snapshot']],
		],
		'recover' => [
			AnalysisType::PAGE_OPTIMIZATION => ['mode' => self::ALLOWED, 'constraints' => ['existing_page', 'recovery_focus'], 'requires' => ['target_page', 'page_snapshot']],
			AnalysisType::NEW_PAGE_BRIEF => ['mode' => self::BLOCKED, 'constraints' => [], 'requires' => []],
			AnalysisType::CONTENT_GAP => ['mode' => self::ALLOWED, 'constraints' => ['existing_page', 'recovery_focus'], 'requires' => ['page_snapshot', 'competitor_snapshot']],
		],
		'create' => [
			AnalysisType::PAGE_OPTIMIZATION => ['mode' => self::BLOCKED, 'constraints' => [], 'requires' => []],
			AnalysisType::NEW_PAGE_BRIEF => ['mode' => self::ALLOWED, 'constraints' => ['candidate_page'], 'requires' => ['keywords']],
			AnalysisType::CONTENT_GAP => ['mode' => self::EXPLICIT, 'constraints' => ['candidate_page'], 'requires' => ['page_snapshot', 'competitor_snapshot']],
		],
		'consolidate' => [
			AnalysisType::PAGE_OPTIMIZATION => ['mode' => self::ALLOWED, 'constraints' => ['conflict_review'], 'requires' => ['target_page', 'page_snapshot']],
			AnalysisType::NEW_PAGE_BRIEF => ['mode' => self::BLOCKED, 'constraints' => [], 'requires' => []],
			AnalysisType::CONTENT_GAP => ['mode' => self::EXPLICIT, 'constraints' => ['conflict_review'], 'requires' => ['page_snapshot', 'competitor_snapshot']],
		],
		'monitor' => [
			AnalysisType::PAGE_OPTIMIZATION => ['mode' => self::ALLOWED, 'constraints' => ['existing_page', 'maintenance_focus'], 'requires' => ['target_page', 'page_snapshot']],
			AnalysisType::NEW_PAGE_BRIEF => ['mode' => self::BLOCKED, 'constraints' => [], 'requires' => []],
			AnalysisType::CONTENT_GAP => ['mode' => self::EXPLICIT, 'constraints' => ['existing_page', 'maintenance_focus'], 'requires' => ['page_snapshot', 'competitor_snapshot']],
		],
		'investigate' => [
			AnalysisType::PAGE_OPTIMIZATION => ['mode' => self::EXPLICIT, 'constraints' => ['verify_first'], 'requires' => ['target_page', 'page_snapshot']],
			AnalysisType::NEW_PAGE_BRIEF => ['mode' => self::EXPLICIT, 'constraints' => ['candidate_page', 'verify_first'], 'requires' => ['keywords']],
			AnalysisType::CONTENT_GAP => ['mode' => self::EXPLICIT, 'constraints' => ['verify_first'], 'requires' => ['page_snapshot', 'competitor_snapshot']],
		],
	];

	/** Znaczenie ograniczeń (dla modelu i człowieka). */
	public const CONSTRAINTS = [
		'existing_page' => 'Analyse the existing page; do not propose replacing it with a new page.',
		'recovery_focus' => 'The topic lost visibility: look for plausible causes and recovery steps before proposing expansion.',
		'candidate_page' => 'This is a "Kandydat na nową stronę": no known project page in available sources, which is NOT proof that no such page exists on the site.',
		'conflict_review' => 'Several project URLs compete: propose steps for manual verification only — never order redirects, canonical changes, noindex or page removal.',
		'maintenance_focus' => 'The topic is stable: focus on maintaining visibility and small improvements; no large rebuild, no high-impact claims.',
		'verify_first' => 'The Strategy decision is ambiguous: start with verification steps; lower confidence for conclusions.',
	];

	/**
	 * @return array{mode: string, constraints: list<string>, requires: list<string>}
	 */
	public static function rule(?string $action, string $type): array
	{
		return self::TABLE[(string) $action][$type] ?? ['mode' => self::BLOCKED, 'constraints' => [], 'requires' => []];
	}

	/**
	 * Tabela do prezentacji (CLI, dokumentacja).
	 *
	 * @return list<array{action: string, type: string, mode: string, constraints: list<string>, requires: list<string>}>
	 */
	public static function matrix(): array
	{
		$rows = [];

		foreach (self::TABLE as $action => $types) {
			foreach ($types as $type => $rule) {
				$rows[] = ['action' => $action, 'type' => $type] + $rule;
			}
		}

		return $rows;
	}
}
