<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\AiRunNotFound;
use OsfSeo\Ai\Evaluation\AiEvaluationService;
use OsfSeo\Ai\Evaluation\EvaluationCases;
use OsfSeo\Ai\Evaluation\QualityRubric;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Plugin;
use WP_CLI;

/**
 * `wp osf-seo ai:eval*` — Quality Evaluation Framework (STEP 17, faza E): rubryka, katalog przypadków A–L, ręczna ocena ekspercka zapisanego
 * wyniku (wymaga `--user=<administrator>`; nigdy proces systemowy ani model) i raport porównawczy wersji instrukcji bez łącznego wyniku.
 * Zero wywołań modelu i zero żądań HTTP. Wyjście po angielsku; `--format=json` — tylko JSON.
 */
final class AiEvalCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];

		WP_CLI::add_command('osf-seo ai:eval-criteria', [$command, 'criteria'], [
			'shortdesc' => 'Quality rubric of AI analyses: 10 criteria (1–5 or n/a), issue codes, verdicts and fix actions. No overall score.',
			'synopsis' => [$format],
		]);
		WP_CLI::add_command('osf-seo ai:eval-cases', [$command, 'cases'], [
			'shortdesc' => 'Quality test cases A–L: input evidence, expected scope, pitfalls, success criteria and forbidden conclusions.',
			'synopsis' => [['type' => 'assoc', 'name' => 'case', 'description' => 'One case (A–L).', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo ai:eval', [$command, 'evaluate'], [
			'shortdesc' => 'Record a manual expert evaluation of a stored AI result (requires --user; never changes the result, its decision or the Strategy).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'run', 'description' => 'AI run ID (ULID) with a stored result.', 'optional' => false],
				['type' => 'assoc', 'name' => 'scores', 'description' => 'All 10 criteria: "specificity=4,gsc_consistency=na,..." (1–5 or na).', 'optional' => false],
				['type' => 'assoc', 'name' => 'verdict', 'description' => 'accepted, accepted_with_edits or rejected.', 'optional' => false],
				['type' => 'assoc', 'name' => 'issues', 'description' => 'Issues: "code[@item][:note];..." e.g. "hallucination@R2:invented volume;generic_advice@R3" (required when rejected).', 'optional' => true],
				['type' => 'assoc', 'name' => 'action', 'description' => 'Fix action: none, fix_prompt, fix_validator, fix_context, fix_data, model_config (default none).', 'optional' => true, 'default' => 'none'],
				['type' => 'assoc', 'name' => 'case', 'description' => 'Quality test case (A–L).', 'optional' => true],
				['type' => 'assoc', 'name' => 'notes', 'description' => 'Free-text notes of the evaluator.', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo ai:eval-list', [$command, 'list'], [
			'shortdesc' => 'Manual evaluations of a project (optionally one run).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'run', 'description' => 'Only evaluations of this run.', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo ai:eval-report', [$command, 'report'], [
			'shortdesc' => 'Compare prompt versions: per analysis type × prompt version × model — criterion distributions and medians, verdicts, fix actions, top issues. No overall score.',
			'synopsis' => [
				['type' => 'assoc', 'name' => 'project', 'description' => 'Only this project (default: all projects).', 'optional' => true],
				['type' => 'assoc', 'name' => 'type', 'description' => 'Only this analysis type (page_optimization, new_page_brief, content_gap, topic_analysis).', 'optional' => true],
				['type' => 'flag', 'name' => 'include-test', 'description' => 'Include results of the test provider (excluded by default — they are not model answers).', 'optional' => true],
				$format,
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function criteria(array $args, array $assocArgs): void
	{
		$data = ['version' => QualityRubric::VERSION, 'criteria' => QualityRubric::CRITERIA, 'issues' => QualityRubric::ISSUES, 'verdicts' => QualityRubric::VERDICTS, 'actions' => QualityRubric::ACTIONS];

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($data);

			return;
		}

		WP_CLI::log(sprintf('Quality rubric v%d — each criterion 1–5 or n/a; no overall score.', QualityRubric::VERSION));

		foreach (QualityRubric::CRITERIA as $key => $criterion) {
			WP_CLI::log(sprintf('- %s: %s', $key, $criterion['question']));
		}

		WP_CLI::log('Issues: ' . implode(', ', array_keys(QualityRubric::ISSUES)));
		WP_CLI::log('Verdicts: ' . implode(', ', array_keys(QualityRubric::VERDICTS)) . '. Actions: ' . implode(', ', array_keys(QualityRubric::ACTIONS)) . '.');
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function cases(array $args, array $assocArgs): void
	{
		$cases = isset($assocArgs['case']) ? array_filter([strtoupper($assocArgs['case']) => EvaluationCases::find($assocArgs['case'])]) : EvaluationCases::all();

		if ($cases === []) {
			WP_CLI::error('Unknown case.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($cases);

			return;
		}

		foreach ($cases as $id => $case) {
			WP_CLI::log(sprintf('%s — %s (%s); focus: %s', $id, $case['title'], $case['type'], implode(', ', $case['focus'])));
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function evaluate(array $args, array $assocArgs): void
	{
		if (get_current_user_id() <= 0) {
			WP_CLI::error('An evaluation is recorded by a person: run with --user=<administrator login>.');
		}

		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);

		try {
			$evaluation = $this->service()->record(
				$context,
				(string) ($assocArgs['run'] ?? ''),
				self::scores((string) ($assocArgs['scores'] ?? '')),
				self::issues((string) ($assocArgs['issues'] ?? '')),
				(string) ($assocArgs['verdict'] ?? ''),
				(string) ($assocArgs['action'] ?? 'none'),
				$assocArgs['notes'] ?? null,
				$assocArgs['case'] ?? null,
			);
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (AiRunNotFound) {
			WP_CLI::error('AI run not found.');
		} catch (AiRefused $refused) {
			WP_CLI::error('Evaluation refused: ' . implode(', ', $refused->blockers()) . '.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($evaluation);

			return;
		}

		WP_CLI::success(sprintf('Evaluation %s saved: verdict %s, action %s, %d issue(s)%s.', $evaluation['id'], $evaluation['verdict'], $evaluation['action'], count($evaluation['issues']), $evaluation['test_provider'] ? ' (test provider result)' : ''));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function list(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);

		try {
			$rows = $this->service()->list($context, $assocArgs['run'] ?? null);
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (AiRunNotFound) {
			WP_CLI::error('AI run not found.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($rows);

			return;
		}

		if ($rows === []) {
			WP_CLI::log('No evaluations.');

			return;
		}

		\WP_CLI\Utils\format_items('table', array_map(static fn (array $row): array => [
			'id' => $row['id'],
			'run' => $row['run'] ?? '(deleted)',
			'task' => $row['task'],
			'prompt' => $row['prompt_version'],
			'model' => $row['model'],
			'case' => $row['case'] ?? '-',
			'verdict' => $row['verdict'],
			'action' => $row['action'],
			'issues' => implode(', ', array_map(static fn (array $issue): string => $issue['code'] . ($issue['item'] !== null ? '@' . $issue['item'] : ''), $row['issues'])),
			'updated_at' => $row['updated_at'],
		], $rows), ['id', 'run', 'task', 'prompt', 'model', 'case', 'verdict', 'action', 'issues', 'updated_at']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function report(array $args, array $assocArgs): void
	{
		$userId = get_current_user_id();

		if ($userId > 0 && ! user_can($userId, Capabilities::MANAGE_AI)) {
			WP_CLI::error('Access denied.');
		}

		$context = isset($assocArgs['project']) ? CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI) : null;
		$type = isset($assocArgs['type']) ? str_replace('-', '_', strtolower(trim($assocArgs['type']))) : null;
		$report = $this->service()->report($context, $type, isset($assocArgs['include-test']));

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($report);

			return;
		}

		if ($report === []) {
			WP_CLI::log('No evaluations to report.');

			return;
		}

		foreach ($report as $group) {
			WP_CLI::log(sprintf('%s · prompt %s · model %s · rubric v%d — %d evaluation(s); verdicts: %s', $group['task'], $group['prompt_version'], $group['model'], $group['rubric_version'], $group['evaluations'], self::pairs($group['verdicts'])));

			foreach ($group['scores'] as $criterion => $stats) {
				WP_CLI::log(sprintf('  %-22s median %-4s n=%d n/a=%d [1:%d 2:%d 3:%d 4:%d 5:%d]', $criterion, $stats['median'] ?? '-', $stats['count'], $stats['not_applicable'], ...array_values($stats['distribution'])));
			}

			WP_CLI::log('  top issues: ' . ($group['issues'] === [] ? '-' : self::pairs(array_slice($group['issues'], 0, 5, true))) . '; fix actions: ' . ($group['actions'] === [] ? '-' : self::pairs($group['actions'])));
		}
	}

	/**
	 * `specificity=4,gsc_consistency=na` → tablica ocen (walidacja w rubryce).
	 *
	 * @return array<string, string>
	 */
	private static function scores(string $value): array
	{
		$scores = [];

		foreach (preg_split('/\s*,\s*/', trim($value)) ?: [] as $pair) {
			if ($pair === '') {
				continue;
			}

			[$key, $score] = array_pad(explode('=', $pair, 2), 2, '');
			$scores[strtolower(trim($key))] = strtolower(trim($score));
		}

		return $scores;
	}

	/**
	 * `code@R2:note;code2` → lista błędów.
	 *
	 * @return list<array{code: string, item: ?string, note: ?string}>
	 */
	private static function issues(string $value): array
	{
		$issues = [];

		foreach (preg_split('/\s*;\s*/', trim($value)) ?: [] as $entry) {
			if ($entry === '') {
				continue;
			}

			[$head, $note] = array_pad(explode(':', $entry, 2), 2, null);
			[$code, $item] = array_pad(explode('@', (string) $head, 2), 2, null);
			$issues[] = ['code' => trim((string) $code), 'item' => $item === null ? null : trim($item), 'note' => $note === null ? null : trim($note)];
		}

		return $issues;
	}

	/**
	 * @param array<string, int> $counts
	 */
	private static function pairs(array $counts): string
	{
		return implode(', ', array_map(static fn (string $key, int $count): string => $key . ' ' . $count, array_keys($counts), $counts));
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function json(array $data): void
	{
		WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function service(): AiEvaluationService
	{
		return $this->plugin->get(AiEvaluationService::class);
	}
}
