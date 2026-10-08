<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Ai;

use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\AiRunNotFound;
use OsfSeo\Ai\Evaluation\AiEvaluationRepository;
use OsfSeo\Ai\Evaluation\AiEvaluationService;
use OsfSeo\Ai\Evaluation\QualityRubric;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\Roles;
use OsfSeo\Projects\ProjectRole;

/**
 * Quality Evaluation Framework (STEP 17, faza E) na prawdziwym WordPressie i bazie: ocenia wyłącznie człowiek z uprawnieniem AI (nie proces
 * systemowy), odrzucenie wymaga wskazania błędu przy konkretnym elemencie wyniku, ocena nie zmienia wyniku ani decyzji, odczyt w obrębie
 * projektu (IDOR), raport porównuje wersje instrukcji bez łącznego wyniku i domyślnie pomija dostawcę testowego. Zero żądań HTTP.
 */
final class AiEvaluationTest extends AiTestCase
{
	private const TOPIC = 'pozycjonowanie stron';

	private AiEvaluationService $evaluations;

	protected function setUp(): void
	{
		parent::setUp();
		$this->evaluations = new AiEvaluationService($this->aiRuns, new AiEvaluationRepository(self::db(), $this->clock), $this->captureLogger());
	}

	public function test_expert_evaluation_is_stored_per_run_and_evaluator_without_touching_the_result(): void
	{
		$context = $this->aiProject();
		$run = $this->ai->run($context, self::TOPIC);
		$expert = $this->expert($context);

		$saved = $this->evaluations->record($expert, $run->publicId, self::scores(['specificity' => 2]), [['code' => 'generic_advice', 'item' => 'r1', 'note' => 'Ogólna porada, bez związku z danymi strony.']], 'rejected', 'fix_prompt', 'Zażółć gęślą jaźń — zbyt ogólne.', 'a');
		self::assertSame([$run->publicId, 'rejected', 'fix_prompt', 'A', 'R1', QualityRubric::VERSION, true], [$saved['run'], $saved['verdict'], $saved['action'], $saved['case'], $saved['issues'][0]['item'], $saved['rubric_version'], $saved['test_provider']]);
		self::assertSame('Zażółć gęślą jaźń — zbyt ogólne.', $saved['notes']);
		self::assertNull($saved['scores']['serp_interpretation']);

		// Ponowna ocena tego samego eksperta aktualizuje wpis (jedna ocena na osobę), decyzja o wyniku i wynik bez zmian.
		$this->evaluations->record($expert, $run->publicId, self::scores(['specificity' => 4]), [], 'accepted_with_edits');
		$list = $this->evaluations->list($expert, $run->publicId);
		self::assertCount(1, $list);
		self::assertSame([4, 'accepted_with_edits'], [$list[0]['scores']['specificity'], $list[0]['verdict']]);
		$after = $this->aiRuns->find($context->projectId(), $run->publicId);
		self::assertSame([AiRun::STATUS_SUCCEEDED, null], [$after?->status, $after?->decision]);
		self::assertSame([], $this->openAiRequests());
	}

	public function test_only_a_person_with_ai_permission_evaluates_and_rejections_point_to_errors(): void
	{
		$context = $this->aiProject();
		$run = $this->ai->run($context, self::TOPIC);
		$expert = $this->expert($context);

		// Proces systemowy (krok w tle, WP-CLI bez --user) nigdy nie zapisuje ocen.
		add_filter('wp_doing_cron', '__return_true');

		try {
			$system = $this->guard->authorizeSystem($context->publicId());
		} finally {
			remove_filter('wp_doing_cron', '__return_true');
		}

		$this->assertRefused('evaluator_required', fn () => $this->evaluations->record($system, $run->publicId, self::scores(), [], 'accepted'));

		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);

		try {
			$this->evaluations->record($this->guard->authorize($context->publicId(), $client), $run->publicId, self::scores(), [], 'accepted');
			self::fail('Klient nie ocenia analiz.');
		} catch (AccessDenied) {
			self::assertSame([], $this->evaluations->list($expert));
		}

		$this->assertRefused('evaluation_invalid', fn () => $this->evaluations->record($expert, $run->publicId, self::scores(), [], 'rejected'));
		$this->assertRefused('evaluation_invalid', fn () => $this->evaluations->record($expert, $run->publicId, self::scores(), [['code' => 'hallucination', 'item' => 'R9']], 'rejected'));
		$this->assertRefused('evaluation_invalid', fn () => $this->evaluations->record($expert, $run->publicId, array_slice(self::scores(), 1), [], 'accepted'));
		$this->assertRefused('evaluation_invalid', fn () => $this->evaluations->record($expert, $run->publicId, self::scores() + ['seo_score' => 5], [], 'accepted'));
		$this->assertRefused('evaluation_invalid', fn () => $this->evaluations->record($expert, $run->publicId, self::scores(), [], 'accepted', 'none', null, 'Z'));
		self::assertSame([], $this->evaluations->list($expert));

		// Wynik niegotowy (np. w kolejce) nie podlega ocenie; uruchomienie innego projektu — jak nieistniejące (IDOR).
		$other = $this->aiProject('drugi-projekt.pl');
		$foreign = $this->ai->run($other, self::TOPIC);
		$this->expectException(AiRunNotFound::class);
		$this->evaluations->record($expert, $foreign->publicId, self::scores(), [], 'accepted');
	}

	public function test_report_compares_prompt_versions_without_an_overall_score_and_skips_the_test_provider(): void
	{
		$context = $this->aiProject();
		$this->configureOpenAi();
		$this->mockOpenAiSuccess();
		$this->evaluations = new AiEvaluationService($this->aiRuns, new AiEvaluationRepository(self::db(), $this->clock), $this->captureLogger());
		$expert = $this->expert($context);
		$second = $this->expert($context);

		$current = $this->ai->run($context, self::TOPIC, 'openai', null, true);
		$older = $this->ai->run($context, self::TOPIC, 'openai', null, true);
		$fake = $this->ai->run($context, self::TOPIC);
		// Starsza wersja instrukcji (porównanie wersji) — symulowana w historii.
		$db = self::db();
		$db->execute("UPDATE `{$db->table('ai_runs')}` SET prompt_version = 'topic-analysis.v0' WHERE public_id = %s", [$older->publicId]);

		$this->evaluations->record($expert, $current->publicId, self::scores(['specificity' => 4]), [], 'accepted');
		$this->evaluations->record($second, $current->publicId, self::scores(['specificity' => 5]), [], 'accepted_with_edits', 'fix_validator');
		$this->evaluations->record($expert, $older->publicId, self::scores(['specificity' => 2]), [['code' => 'generic_advice', 'item' => 'R1']], 'rejected', 'fix_prompt');
		$this->evaluations->record($expert, $fake->publicId, self::scores(['specificity' => 1]), [['code' => 'other']], 'rejected');

		$report = $this->evaluations->report($expert);
		self::assertSame(['topic-analysis.v0', $current->promptVersion], array_column($report, 'prompt_version'));
		self::assertSame([1, 2], array_column($report, 'evaluations'), 'Dostawca testowy pominięty.');
		self::assertSame(['count' => 2, 'not_applicable' => 0, 'median' => 4.5, 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1]], $report[1]['scores']['specificity']);
		self::assertSame(['generic_advice' => 1], $report[0]['issues']);
		self::assertSame(['accepted' => 1, 'accepted_with_edits' => 1, 'rejected' => 0], $report[1]['verdicts']);

		foreach ($report as $group) {
			self::assertSame(array_keys(QualityRubric::CRITERIA), array_keys($group['scores']));
			self::assertArrayNotHasKey('score', $group);
			self::assertArrayNotHasKey('total', $group);
		}

		self::assertCount(3, $this->evaluations->report($expert, null, true), 'Z dostawcą testowym na żądanie.');
		self::assertSame([], $this->evaluations->report($expert, 'content_gap'));
		self::assertCount(2, $this->openAiRequests(), 'Tylko dwa jawne wywołania z testu — ocena i raport bez żądań.');
	}

	private function expert(ProjectContext $context): ProjectContext
	{
		return $this->guard->authorize($context->publicId(), $this->createUser(Roles::ADMIN));
	}

	/**
	 * @param array<string, int|string|null> $overrides
	 * @return array<string, int|string|null>
	 */
	private static function scores(array $overrides = []): array
	{
		return $overrides + ['serp_interpretation' => 'na'] + array_fill_keys(array_keys(QualityRubric::CRITERIA), 3);
	}

	private function assertRefused(string $code, \Closure $call): void
	{
		try {
			$call();
			self::fail('Oczekiwana odmowa: ' . $code);
		} catch (AiRefused $refused) {
			self::assertSame($code, $refused->code(), implode(', ', $refused->blockers()));
		}
	}
}
