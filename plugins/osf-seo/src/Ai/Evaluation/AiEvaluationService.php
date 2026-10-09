<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Evaluation;

use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\AiRunNotFound;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Ai\Run\AiRunRepository;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Support\Logger;

/**
 * Quality Evaluation Framework (STEP 17, faza E — docs/ARCHITECTURE.md, sekcja 26): ręczna ocena ekspercka zapisanych wyników analiz AI
 * według `QualityRubric` i raport porównawczy wersji instrukcji. Zasady:
 * - ocenia wyłącznie człowiek z uprawnieniem `osf_seo_manage_ai` (identyfikator oceniającego wymagany — proces systemowy, krok w tle
 *   ani model nie zapisują ocen; żadnej automatycznej oceny odpowiedzi przez model),
 * - ocena nie zmienia wyniku, decyzji o wyniku, Strategii ani statusu pracy tematu (osobna tabela),
 * - odrzucenie wymaga wskazania co najmniej jednego błędu; błąd może wskazywać konkretną rekomendację albo ustalenie z wyniku (`R2`, `F1`),
 * - raport bez łącznego wyniku: rozkład i mediana każdego kryterium osobno, werdykty i najczęstsze błędy — w podziale na typ analizy,
 *   wersję instrukcji i model; wyniki dostawcy testowego domyślnie pominięte (to nie są odpowiedzi modelu).
 */
final class AiEvaluationService
{
	public function __construct(
		private readonly AiRunRepository $runs,
		private readonly AiEvaluationRepository $evaluations,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $scores kryterium → 1–5 albo null / „na” (nie dotyczy)
	 * @param list<array<string, mixed>> $issues `code`, opcjonalnie `item` (np. R2) i `note`
	 * @return array<string, mixed>
	 *
	 * @throws AccessDenied
	 * @throws AiRunNotFound
	 * @throws AiRefused
	 */
	public function record(ProjectContext $context, string $runId, array $scores, array $issues, string $verdict, string $action = 'none', ?string $notes = null, ?string $caseId = null): array
	{
		$context->assertCan(Capabilities::MANAGE_AI);

		if ($context->isSystem() || $context->userId() <= 0) {
			throw new AiRefused('evaluator_required');
		}

		$run = $this->runs->find($context->projectId(), $runId) ?? throw new AiRunNotFound();

		if (! in_array($run->status, [AiRun::STATUS_SUCCEEDED, AiRun::STATUS_INVALID], true)) {
			throw new AiRefused('run_not_evaluable');
		}

		try {
			$scores = QualityRubric::scores($scores);
			$issues = QualityRubric::issues($issues);
		} catch (\InvalidArgumentException $exception) {
			throw new AiRefused('evaluation_invalid', [$exception->getMessage()]);
		}

		if (! isset(QualityRubric::VERDICTS[$verdict])) {
			throw new AiRefused('evaluation_invalid', ['unknown_verdict:' . $verdict]);
		}

		if (! isset(QualityRubric::ACTIONS[$action])) {
			throw new AiRefused('evaluation_invalid', ['unknown_action:' . $action]);
		}

		if ($verdict === 'rejected' && $issues === []) {
			throw new AiRefused('evaluation_invalid', ['issues_required']);
		}

		$caseId = $caseId === null || trim($caseId) === '' ? null : strtoupper(trim($caseId));

		if ($caseId !== null && EvaluationCases::find($caseId) === null) {
			throw new AiRefused('evaluation_invalid', ['unknown_case:' . $caseId]);
		}

		$items = self::items($this->runs->payload($run)['result'] ?? null);

		foreach ($issues as $issue) {
			if ($issue['item'] !== null && ! in_array($issue['item'], $items, true)) {
				throw new AiRefused('evaluation_invalid', ['unknown_item:' . $issue['item']]);
			}
		}

		$notes = $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 4000);
		$evaluation = $this->evaluations->save($run, $context->userId(), $scores, $issues, $verdict, $action, $notes, $caseId);
		$this->logger->info('AI run {run} evaluated: verdict {verdict}, action {action}, {issues} issue(s).', ['run' => $run->publicId, 'verdict' => $verdict, 'action' => $action, 'issues' => count($issues)]);

		return $evaluation + ['test_provider' => $run->provider === FakeProvider::ID];
	}

	/**
	 * Oceny projektu (opcjonalnie jednego uruchomienia).
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws AccessDenied
	 * @throws AiRunNotFound
	 */
	public function list(ProjectContext $context, ?string $runId = null): array
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$run = $runId === null ? null : ($this->runs->find($context->projectId(), $runId) ?? throw new AiRunNotFound());

		return $this->evaluations->forProject($context->projectId(), $run?->id);
	}

	/**
	 * Raport porównawczy: grupy typ analizy × wersja instrukcji × model — liczba ocen, rozkład i mediana każdego kryterium, werdykty,
	 * działania naprawcze i najczęstsze błędy. Bez łącznego wyniku. `$context === null` — cała instalacja (tylko CLI operatora).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function report(?ProjectContext $context, ?string $task = null, bool $includeTest = false): array
	{
		$context?->assertCan(Capabilities::MANAGE_AI);
		$groups = [];

		foreach ($this->evaluations->forReport($context?->projectId(), $task) as $evaluation) {
			if (! $includeTest && $evaluation['provider'] === FakeProvider::ID) {
				continue;
			}

			$key = $evaluation['task'] . '|' . $evaluation['prompt_version'] . '|' . $evaluation['model'] . '|' . $evaluation['rubric_version'];
			$groups[$key] ??= [
				'task' => $evaluation['task'],
				'prompt_version' => $evaluation['prompt_version'],
				'model' => $evaluation['model'],
				'provider' => $evaluation['provider'],
				'rubric_version' => $evaluation['rubric_version'],
				'evaluations' => 0,
				'scores' => array_fill_keys(array_keys(QualityRubric::CRITERIA), []),
				'verdicts' => array_fill_keys(array_keys(QualityRubric::VERDICTS), 0),
				'actions' => [],
				'issues' => [],
			];
			$group = &$groups[$key];
			$group['evaluations']++;
			$group['verdicts'][$evaluation['verdict']] = ($group['verdicts'][$evaluation['verdict']] ?? 0) + 1;
			$group['actions'][$evaluation['action']] = ($group['actions'][$evaluation['action']] ?? 0) + 1;

			foreach (array_keys(QualityRubric::CRITERIA) as $criterion) {
				$value = $evaluation['scores'][$criterion] ?? null;
				$group['scores'][$criterion][] = is_int($value) ? $value : null;
			}

			foreach ($evaluation['issues'] as $issue) {
				$code = (string) ($issue['code'] ?? 'other');
				$group['issues'][$code] = ($group['issues'][$code] ?? 0) + 1;
			}

			unset($group);
		}

		$report = [];

		foreach ($groups as $group) {
			$group['scores'] = array_map(QualityRubric::distribution(...), $group['scores']);
			arsort($group['issues']);
			ksort($group['actions']);
			$report[] = $group;
		}

		usort($report, static fn (array $a, array $b): int => [$a['task'], $a['prompt_version'], $a['model']] <=> [$b['task'], $b['prompt_version'], $b['model']]);

		return $report;
	}

	/**
	 * Identyfikatory elementów wyniku, które ocena może wskazać: rekomendacje (`R…`), ustalenia (`F…`) i inne listy z `id`.
	 *
	 * @return list<string>
	 */
	private static function items(mixed $result): array
	{
		if (! is_array($result)) {
			return [];
		}

		$ids = [];

		foreach ($result as $value) {
			if (! is_array($value)) {
				continue;
			}

			foreach ($value as $item) {
				if (is_array($item) && is_string($item['id'] ?? null) && $item['id'] !== '') {
					$ids[] = strtoupper($item['id']);
				}
			}
		}

		return array_values(array_unique($ids));
	}
}
