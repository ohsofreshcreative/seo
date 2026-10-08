<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Ai\AiAnalysisService;
use OsfSeo\Ai\AiPlan;
use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\AiRunNotFound;
use OsfSeo\Ai\Context\AiContext;
use OsfSeo\Ai\Context\TopicContextAssembler;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Plugin;
use OsfSeo\Strategy\StrategyNotFound;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp osf-seo ai:*` — analizy AI tematów Strategii (STEP 17, faza A): diagnostyka konfiguracji (bez sekretów), podgląd i walidacja
 * kontekstu, plan (zero żądań), uruchomienie (domyślnie dostawca testowy `fake` — koszt 0; płatny dostawca tylko po świadomej konfiguracji,
 * bez powodów blokady i z potwierdzeniem kosztu), historia, decyzje, budżet AI i porządki. Wyjście po angielsku; `--format=json` — tylko JSON.
 */
final class AiCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$topic = ['type' => 'assoc', 'name' => 'topic', 'description' => 'Strategy topic ID (ULID), or a candidate ID / keyword of the topic.', 'optional' => false];
		$provider = ['type' => 'assoc', 'name' => 'provider', 'description' => 'AI provider (default: fake — test provider, zero cost).', 'optional' => true, 'default' => FakeProvider::ID];
		$focus = ['type' => 'assoc', 'name' => 'focus', 'description' => 'Optional analysis focus (short text, max 300 characters; treated as untrusted).', 'optional' => true];
		$run = ['type' => 'assoc', 'name' => 'run', 'description' => 'AI run ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];

		WP_CLI::add_command('osf-seo ai:status', [$command, 'status'], [
			'shortdesc' => 'AI configuration check without secrets: kill switch, provider, model, key presence, prices, limits, versions, run counts.',
			'synopsis' => [$format],
		]);
		WP_CLI::add_command('osf-seo ai:context', [$command, 'context'], [
			'shortdesc' => 'Preview the deterministic AI context of a Strategy topic (JSON; no AI and no API call).',
			'synopsis' => [$project, $topic],
		]);
		WP_CLI::add_command('osf-seo ai:validate-context', [$command, 'validateContext'], [
			'shortdesc' => 'Validate the AI context of a topic: determinism (fingerprint), size budget, refs, data gaps (exit code 1 on problems).',
			'synopsis' => [$project, $topic, $format],
		]);
		WP_CLI::add_command('osf-seo ai:plan', [$command, 'plan'], [
			'shortdesc' => 'Plan an AI analysis of a topic: provider, model, token estimate, maximum cost, budget and blockers (no request).',
			'synopsis' => [$project, $topic, $provider, $focus, $format],
		]);
		WP_CLI::add_command('osf-seo ai:run', [$command, 'run'], [
			'shortdesc' => 'Run an AI analysis of a topic. Default provider "fake" (zero cost, no network). A paid provider runs only when enabled, configured, priced, within limits and confirmed.',
			'synopsis' => [
				$project,
				$topic,
				$provider,
				$focus,
				['type' => 'flag', 'name' => 'yes', 'description' => 'Confirm the maximum cost of a paid run without asking.', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo ai:runs', [$command, 'runs'], [
			'shortdesc' => 'AI run history of a project (metadata only).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'topic', 'description' => 'Only runs of this topic.', 'optional' => true],
				['type' => 'assoc', 'name' => 'limit', 'description' => 'Rows (default 20, max 200).', 'optional' => true, 'default' => '20'],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo ai:show', [$command, 'show'], [
			'shortdesc' => 'AI run detail: metadata, validated result and validation report; --payload adds the stored input and raw model output.',
			'synopsis' => [$project, $run, ['type' => 'flag', 'name' => 'payload', 'description' => 'Include stored input and raw output.', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo ai:decide', [$command, 'decide'], [
			'shortdesc' => 'Record the user decision on a successful AI result (accepted, rejected, clear). Never changes the Strategy or the topic work status.',
			'synopsis' => [$project, $run, ['type' => 'assoc', 'name' => 'decision', 'description' => 'accepted, rejected or clear.', 'optional' => false]],
		]);
		WP_CLI::add_command('osf-seo ai:delete', [$command, 'delete'], [
			'shortdesc' => 'Delete an AI run with its stored input and output (not while in progress).',
			'synopsis' => [$project, $run, ['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation.', 'optional' => true]],
		]);
		WP_CLI::add_command('osf-seo ai:budget', [$command, 'budget'], [
			'shortdesc' => 'AI budget (separate from DataForSEO): limits, spent today / this month (UTC), per project with --project.',
			'synopsis' => [['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo ai:purge', [$command, 'purge'], [
			'shortdesc' => 'Maintenance without AI calls: recover abandoned runs and delete runs older than the retention period (OSF_SEO_AI_RETENTION_DAYS).',
			'synopsis' => [$format],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function status(array $args, array $assocArgs): void
	{
		$this->requireOperator();
		$status = $this->service()->status();

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($status);

			return;
		}

		$config = $status['config'];
		WP_CLI::log(sprintf('Real AI calls: %s (OSF_SEO_AI_ENABLED). Configured provider: %s%s. Model: %s.', $config['enabled'] ? 'enabled' : 'disabled', $config['provider'] ?? '—', $status['provider_known'] ? '' : ' (unknown)', $config['model'] ?? '—'));
		WP_CLI::log(sprintf('Prices per 1M tokens (USD): input %s, cached input %s, output %s.', self::money($config['prices_per_mtok']['input']), self::money($config['prices_per_mtok']['cached_input']), self::money($config['prices_per_mtok']['output'])));
		WP_CLI::log(sprintf('AI limits (USD, separate from DataForSEO): daily %s, monthly %s, per project monthly %s, max per analysis %s.', self::money($config['limits']['daily']), self::money($config['limits']['monthly']), self::money($config['limits']['project_monthly']), self::money($config['limits']['max_run_cost'])));
		WP_CLI::log(sprintf('Max output tokens %d, timeout %d s, retention %d days. Versions: prompt %s, context %d, contract %d.', $config['max_output_tokens'], $config['timeout'], $config['retention_days'], $status['versions']['prompt'], $status['versions']['context'], $status['versions']['contract']));
		Utils\format_items('table', array_map(static fn (array $provider): array => [
			'provider' => $provider['id'],
			'paid' => $provider['paid'] ? 'yes' : 'no',
			'model' => $provider['model'] ?? '—',
			'selected' => $provider['configured'] ? 'yes' : 'no',
			'problems' => $provider['problems'] === [] ? '—' : implode(', ', $provider['problems']),
		], $status['providers']), ['provider', 'paid', 'model', 'selected', 'problems']);
		WP_CLI::log('Runs: ' . ($status['runs'] === [] ? 'none' : implode(', ', array_map(static fn (string $key, int $count): string => $key . ' ' . $count, array_keys($status['runs']), $status['runs']))) . '.');
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function context(array $args, array $assocArgs): void
	{
		WP_CLI::line($this->buildContext($assocArgs)->json());
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function validateContext(array $args, array $assocArgs): void
	{
		$first = $this->buildContext($assocArgs);
		$second = $this->buildContext($assocArgs);
		$problems = [];

		if ($first->fingerprint() !== $second->fingerprint() || $first->json() !== $second->json()) {
			$problems[] = 'not_deterministic';
		}

		if (! $first->withinBudget()) {
			$problems[] = 'over_size_budget';
		}

		if (json_decode($first->json(), true) === null) {
			$problems[] = 'invalid_json';
		}

		foreach (['topic', 'decision', 'target'] as $ref) {
			if (! in_array($ref, $first->refs(), true)) {
				$problems[] = 'missing_ref_' . $ref;
			}
		}

		$report = [
			'valid' => $problems === [],
			'problems' => $problems,
			'topic' => $first->topicPublicId,
			'fingerprint' => $first->fingerprint(),
			'evidence_hash' => $first->evidenceHash(),
			'bytes' => $first->bytes(),
			'max_bytes' => TopicContextAssembler::MAX_BYTES,
			'refs' => count($first->refs()),
			'keywords' => count((array) ($first->body['keywords'] ?? [])),
			'external_texts' => count((array) ($first->body['external_texts'] ?? [])),
			'data_gaps' => $first->dataGaps(),
			'omitted' => $first->body['limits']['omitted'] ?? [],
			'reductions' => $first->body['limits']['reductions'] ?? [],
			'truncated_texts' => $first->body['limits']['truncated_texts'] ?? 0,
		];

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($report);
		} else {
			WP_CLI::log(sprintf('Context of topic %s: %d bytes (max %d), %d refs, %d keywords, fingerprint %s.', $report['topic'], $report['bytes'], $report['max_bytes'], $report['refs'], $report['keywords'], $report['fingerprint']));
			WP_CLI::log('Data gaps: ' . ($report['data_gaps'] === [] ? 'none' : implode(', ', $report['data_gaps'])) . '.');
			WP_CLI::log('Omitted: ' . ($report['omitted'] === [] ? 'none' : implode(', ', array_map(static fn (string $key, int $count): string => $key . ' ' . $count, array_keys($report['omitted']), $report['omitted']))) . '.');
		}

		if ($problems !== []) {
			WP_CLI::error('Context validation failed: ' . implode(', ', $problems) . '.', false);
			WP_CLI::halt(1);
		}

		if (($assocArgs['format'] ?? 'table') !== 'json') {
			WP_CLI::success('Context is valid and deterministic.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function plan(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);

		try {
			$plan = $this->service()->plan($context, (string) $assocArgs['topic'], (string) ($assocArgs['provider'] ?? FakeProvider::ID), $assocArgs['focus'] ?? null);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($plan->toArray());

			return;
		}

		$this->printPlan($plan);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function run(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);
		$provider = (string) ($assocArgs['provider'] ?? FakeProvider::ID);
		$json = ($assocArgs['format'] ?? 'table') === 'json';

		try {
			$plan = $this->service()->plan($context, (string) $assocArgs['topic'], $provider, $assocArgs['focus'] ?? null);

			if (! $plan->runnable()) {
				if (! $json) {
					$this->printPlan($plan);
				}

				WP_CLI::error('AI analysis refused: ' . implode(', ', $plan->blockers) . '.');
			}

			if ($plan->paid && ! isset($assocArgs['yes'])) {
				WP_CLI::confirm(sprintf('Run a PAID AI analysis with %s / %s, maximum cost %.6f USD (AI budget, separate from DataForSEO)?', $plan->provider, (string) $plan->model, (float) $plan->maxCost));
			}

			$run = $this->service()->run($context, (string) $assocArgs['topic'], $provider, $assocArgs['focus'] ?? null, true);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (AiRefused $refused) {
			WP_CLI::error('AI analysis refused: ' . implode(', ', $refused->blockers()) . '.');
		}

		if ($json) {
			self::json($run->toArray());

			return;
		}

		WP_CLI::log(sprintf('Run %s: %s (provider %s, model %s), tokens %s/%s, charged %.6f USD (%s).', $run->publicId, $run->status, $run->provider, $run->model, $run->inputTokens ?? '—', $run->outputTokens ?? '—', $run->chargedCost(), $run->costBasis ?? '—'));

		if ($run->status === AiRun::STATUS_SUCCEEDED) {
			WP_CLI::success('Analysis stored. Show it with: wp osf-seo ai:show --project=' . $context->publicId() . ' --run=' . $run->publicId);
		} else {
			WP_CLI::warning('Analysis not usable: ' . ($run->errorCode ?? $run->status) . ($run->validationErrors > 0 ? ' (' . $run->validationErrors . ' validation errors)' : '') . '.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function runs(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);

		try {
			$runs = $this->service()->runs($context, isset($assocArgs['topic']) ? (string) $assocArgs['topic'] : null, (int) ($assocArgs['limit'] ?? 20));
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json(array_map(static fn (AiRun $run): array => $run->toArray(), $runs));

			return;
		}

		if ($runs === []) {
			WP_CLI::log('No AI runs.');

			return;
		}

		Utils\format_items('table', array_map(static fn (AiRun $run): array => [
			'id' => $run->publicId,
			'created' => $run->createdAt,
			'topic' => $run->topicPublicId ?? '—',
			'provider' => $run->provider . ' / ' . $run->model,
			'status' => $run->status,
			'tokens' => ($run->inputTokens ?? '—') . '/' . ($run->outputTokens ?? '—'),
			'charged_usd' => sprintf('%.6f', $run->chargedCost()),
			'error' => $run->errorCode ?? '—',
			'decision' => $run->decision ?? '—',
		], $runs), ['id', 'created', 'topic', 'provider', 'status', 'tokens', 'charged_usd', 'error', 'decision']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function show(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);

		try {
			$detail = $this->service()->show($context, (string) $assocArgs['run']);
		} catch (AiRunNotFound) {
			WP_CLI::error('AI run not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		$payload = $detail['payload'] ?? [];
		$data = [
			'run' => $detail['run']->toArray(),
			'result' => $payload['result'] ?? null,
			'validation' => $payload['validation'] ?? [],
		];

		if (isset($assocArgs['payload'])) {
			$input = json_decode((string) ($payload['input'] ?? ''), true);
			$data['input'] = is_array($input) ? $input : null;
			$data['input_hash'] = $payload['input_hash'] ?? null;
			$data['output_raw'] = $payload['output_raw'] ?? null;
		}

		self::json($data);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function decide(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);
		$decision = strtolower(trim((string) $assocArgs['decision']));

		try {
			$run = $this->service()->decide($context, (string) $assocArgs['run'], $decision === 'clear' ? null : $decision);
		} catch (AiRunNotFound) {
			WP_CLI::error('AI run not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (AiRefused $refused) {
			WP_CLI::error('Decision refused: ' . $refused->code() . '.');
		}

		WP_CLI::success(sprintf('Decision of run %s: %s.', $run->publicId, $run->decision ?? 'none'));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function delete(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);

		if (! isset($assocArgs['yes'])) {
			WP_CLI::confirm('Delete the AI run with its stored input and output?');
		}

		try {
			$this->service()->delete($context, (string) $assocArgs['run']);
		} catch (AiRunNotFound) {
			WP_CLI::error('AI run not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (AiRefused $refused) {
			WP_CLI::error('Delete refused: ' . $refused->code() . '.');
		}

		WP_CLI::success('AI run deleted.');
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function budget(array $args, array $assocArgs): void
	{
		$context = null;

		if (isset($assocArgs['project'])) {
			$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);
		} else {
			$this->requireOperator();
		}

		try {
			$budget = $this->service()->budget($context);
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($budget);

			return;
		}

		WP_CLI::log(sprintf('AI calls %s; prices %s. AI budget is separate from DataForSEO limits.', $budget['enabled'] ? 'enabled' : 'disabled', $budget['prices_configured'] ? 'configured' : 'NOT configured (paid runs refused)'));
		WP_CLI::log(sprintf('Today (UTC): %.6f / %.6f USD. This month: %.6f / %.6f USD.', $budget['spent']['today'], $budget['limits']['daily'], $budget['spent']['month'], $budget['limits']['monthly']));

		if ($budget['spent']['project_month'] !== null) {
			WP_CLI::log(sprintf('Project this month: %.6f / %.6f USD.', $budget['spent']['project_month'], $budget['limits']['project_monthly']));
		}

		WP_CLI::log(sprintf('Max cost per analysis: %.6f USD.', $budget['limits']['max_run_cost']));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function purge(array $args, array $assocArgs): void
	{
		$this->requireOperator();
		$result = $this->service()->maintenance(true);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($result);

			return;
		}

		WP_CLI::success(sprintf('Recovered %d abandoned run(s), purged %d expired run(s).', $result['recovered'], (int) $result['purged']));
	}

	/**
	 * @param array<string, string> $assocArgs
	 */
	private function buildContext(array $assocArgs): AiContext
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_AI);

		try {
			return $this->service()->context($context, (string) $assocArgs['topic']);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}
	}

	private function printPlan(AiPlan $plan): void
	{
		$data = $plan->toArray();
		WP_CLI::log(sprintf('Provider %s (%s), model %s. Task %s, prompt %s, contract %d.', $plan->provider, $plan->paid ? 'PAID' : 'free', $plan->model ?? '—', $data['task'], $data['prompt_version'], $data['contract_version']));
		WP_CLI::log(sprintf('Context: %d bytes, %d refs, fingerprint %s.', $data['context']['bytes'], $data['context']['refs'], $data['context']['fingerprint']));
		WP_CLI::log('Data gaps: ' . ($data['context']['data_gaps'] === [] ? 'none' : implode(', ', $data['context']['data_gaps'])) . '.');
		WP_CLI::log(sprintf('Tokens: ~%d input (cautious estimate), up to %d output. Maximum cost: %s.', $plan->inputTokensEstimate, $plan->maxOutputTokens, $plan->paid ? ($plan->maxCost === null ? 'unknown (no prices)' : sprintf('%.6f USD', $plan->maxCost)) : '0 (free provider)'));

		if ($plan->budget !== null) {
			WP_CLI::log(sprintf('AI budget: today %.6f / %.6f USD, month %.6f / %.6f USD, project month %.6f / %.6f USD, max per analysis %.6f USD.', $plan->budget['spent']['today'], $plan->budget['limits']['daily'], $plan->budget['spent']['month'], $plan->budget['limits']['monthly'], (float) $plan->budget['spent']['project_month'], $plan->budget['limits']['project_monthly'], $plan->budget['limits']['max_run_cost']));
		}

		WP_CLI::log($plan->runnable() ? 'Runnable' . ($plan->paid ? ' (requires confirmation).' : '.') : 'Blocked: ' . implode(', ', $plan->blockers) . '.');
	}

	/** Diagnostyka globalna: operator systemu (bez `--user`) albo użytkownik z `osf_seo_manage_ai`. */
	private function requireOperator(): void
	{
		$userId = get_current_user_id();

		if ($userId > 0 && ! user_can($userId, Capabilities::MANAGE_AI)) {
			WP_CLI::error('Access denied.');
		}
	}

	private static function money(?float $value): string
	{
		return $value === null ? '—' : rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private static function json(array $data): void
	{
		WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function service(): AiAnalysisService
	{
		return $this->plugin->get(AiAnalysisService::class);
	}
}
