<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Discovery\CandidateFilters;
use OsfSeo\Discovery\CandidateRow;
use OsfSeo\Discovery\DiscoveryNotFound;
use OsfSeo\Discovery\DiscoveryPlan;
use OsfSeo\Discovery\DiscoveryRequest;
use OsfSeo\Discovery\DiscoveryRun;
use OsfSeo\Discovery\DiscoveryService;
use OsfSeo\Discovery\DiscoveryStartResult;
use OsfSeo\Discovery\SeedList;
use OsfSeo\Plugin;
use WP_CLI;

/**
 * `wp osf-seo discovery:*` — wyszukiwanie nowych fraz (DataForSEO Labs). Płatne żądania wysyła wyłącznie
 * `discovery:run` (po pokazaniu planu i potwierdzeniu); `discovery:plan`, `discovery:suggest`, `discovery:status`,
 * `discovery:list` i `discovery:refresh` nie wywołują API. Wyjście nigdy nie zawiera danych logowania.
 */
final class DiscoveryCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];
		$request = [
			$project,
			['type' => 'assoc', 'name' => 'seeds', 'description' => 'Seed keywords separated by commas or new lines.', 'optional' => true],
			['type' => 'assoc', 'name' => 'seeds-file', 'description' => 'Local text file with one seed keyword per line.', 'optional' => true],
			['type' => 'assoc', 'name' => 'method', 'description' => 'Discovery method.', 'optional' => true, 'default' => 'related', 'options' => ['related', 'suggestions']],
			['type' => 'assoc', 'name' => 'depth', 'description' => 'Related Keywords depth 1–3 (default 2: up to ~72 keywords per seed).', 'optional' => true],
			['type' => 'assoc', 'name' => 'limit', 'description' => 'Maximum candidates of the run (default 250; caps provider items and cost).', 'optional' => true],
			['type' => 'assoc', 'name' => 'min-volume', 'description' => 'Minimum search volume (default OSF_SEO_DISCOVERY_MIN_VOLUME, 10).', 'optional' => true],
			['type' => 'assoc', 'name' => 'max-kd', 'description' => 'Maximum keyword difficulty 0–99 (default: no limit).', 'optional' => true],
			['type' => 'flag', 'name' => 'include-other-languages', 'description' => 'Keep keywords the provider detected as another language.', 'optional' => true],
			['type' => 'flag', 'name' => 'force', 'description' => 'Fetch seeds again even if fetched within the cache TTL.', 'optional' => true],
		];

		WP_CLI::add_command('osf-seo discovery:plan', [$command, 'plan'], [
			'shortdesc' => 'Plan a keyword discovery run without any API request: seeds, requests, maximum items and maximum cost.',
			'synopsis' => [...$request, $format],
		]);
		WP_CLI::add_command('osf-seo discovery:run', [$command, 'run'], [
			'shortdesc' => 'Run keyword discovery (paid DataForSEO Labs API): shows the plan, asks for confirmation, then executes it.',
			'synopsis' => [
				...$request,
				['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation.', 'optional' => true],
				['type' => 'flag', 'name' => 'queue-only', 'description' => 'Only queue the run — requests are sent by the background step.', 'optional' => true],
				['type' => 'assoc', 'name' => 'max-seconds', 'description' => 'Time budget for executing the run in this process (default 300).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo discovery:suggest', [$command, 'suggest'], [
			'shortdesc' => 'Suggested seed keywords from GSC and SEO opportunities (no API call).',
			'synopsis' => [$project, $format],
		]);
		WP_CLI::add_command('osf-seo discovery:status', [$command, 'status'], [
			'shortdesc' => 'Keyword discovery status: active run progress, recent runs, candidate counts (no API call).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'run', 'description' => 'Run public ID — show its seeds and cost.', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo discovery:list', [$command, 'list'], [
			'shortdesc' => 'List discovered keyword candidates (no API call).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'status', 'description' => 'open (new + review), all, new, review, accepted, dismissed.', 'optional' => true, 'default' => 'open'],
				['type' => 'assoc', 'name' => 'visibility', 'description' => 'gap (none, low, unknown), all, none, low, visible, unknown.', 'optional' => true, 'default' => 'gap'],
				['type' => 'assoc', 'name' => 'sort', 'description' => 'priority, volume, difficulty, position, discovered, keyword.', 'optional' => true, 'default' => 'priority'],
				['type' => 'assoc', 'name' => 'search', 'description' => 'Keyword contains.', 'optional' => true],
				['type' => 'assoc', 'name' => 'page', 'description' => 'Page (50 per page).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo discovery:cancel', [$command, 'cancel'], [
			'shortdesc' => 'Cancel a queued or running discovery run (requests already sent stay in the cost register).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'run', 'description' => 'Run public ID.', 'optional' => false]],
		]);
		WP_CLI::add_command('osf-seo discovery:refresh', [$command, 'refresh'], [
			'shortdesc' => 'Recalculate GSC visibility, target pages, exclusions and priority of candidates (no API call).',
			'synopsis' => [$project],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function plan(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_DISCOVERY);
		$plan = $this->service()->plan($context, $this->request($assocArgs));

		if (($assocArgs['format'] ?? 'table') === 'json') {
			WP_CLI::line((string) wp_json_encode(['dry_run' => true, 'api_requests' => 0] + $plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			return;
		}

		$this->printPlan($plan);
		WP_CLI::log('No API request was made.');
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function run(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_DISCOVERY);
		$service = $this->service();
		$request = $this->request($assocArgs);
		$json = ($assocArgs['format'] ?? 'table') === 'json';
		$plan = $service->plan($context, $request);

		if (! $json) {
			$this->printPlan($plan);
		}

		if ($plan->skipReason === null && $plan->requests() > 0 && ! isset($assocArgs['yes'])) {
			WP_CLI::confirm(sprintf('Send %d paid DataForSEO request(s), maximum cost %.4f USD?', $plan->requests(), $plan->estimatedCost()));
		}

		$result = $service->start($context, $request, DiscoveryService::TRIGGER_CLI, $plan->requests(), $plan->estimatedCost());

		if (! $result->isQueued()) {
			$json ? WP_CLI::line((string) wp_json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null;
			$message = self::startMessage($result);
			$result->status === DiscoveryStartResult::NOTHING_TO_DO ? ($json ? null : WP_CLI::success($message)) : WP_CLI::error($message);

			return;
		}

		$run = $result->run ?? throw new \LogicException('Queued result without run.');

		if (isset($assocArgs['queue-only'])) {
			$json ? WP_CLI::line((string) wp_json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : WP_CLI::success(sprintf('Run %s queued — requests are sent by the background step.', $run->publicId));

			return;
		}

		$report = $service->execute($context, $run, max(10.0, (float) ($assocArgs['max-seconds'] ?? 300)));

		if ($json) {
			WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			return;
		}

		$current = $report['run'] ?? null;
		WP_CLI::log(sprintf('Requests: %d, cost: %.4f USD.', $report['requests'] ?? 0, $report['cost'] ?? 0));

		if (is_array($current)) {
			WP_CLI::log(sprintf('Run %s: %s — new candidates %d, seen again %d, items %d.', $current['id'], $current['status'], $current['candidates_new'], $current['candidates_seen'], $current['items_received']));
		}

		if (($report['error'] ?? null) !== null) {
			WP_CLI::warning('Stopped: ' . $report['error'] . '.');
		} elseif (($report['blocked_by'] ?? null) !== null && $report['blocked_by'] !== 'task_limit') {
			WP_CLI::warning('Stopped by the cost budget: ' . $report['blocked_by'] . ' — the run continues in the background when the budget allows.');
		} elseif (is_array($current) && in_array($current['status'], DiscoveryRun::ACTIVE, true)) {
			WP_CLI::warning('The run is not finished yet — the background step will continue it.');
		} else {
			WP_CLI::success('Discovery run finished.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function suggest(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$suggestions = $this->service()->suggestions($context);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			WP_CLI::line((string) wp_json_encode($suggestions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			return;
		}

		$rows = [];

		foreach ($suggestions['gsc'] as $item) {
			$rows[] = ['source' => 'gsc', 'seed' => $item['seed'], 'details' => sprintf('%d clicks, %d impressions, avg position (GSC) %.1f', $item['clicks'], $item['impressions'], $item['position'])];
		}

		foreach ($suggestions['opportunity'] as $item) {
			$rows[] = ['source' => 'opportunity', 'seed' => $item['seed'], 'details' => sprintf('%s, priority %d', $item['type'], $item['priority'])];
		}

		$rows === [] ? WP_CLI::log('No suggestions (no GSC data or SEO opportunities yet).') : \WP_CLI\Utils\format_items('table', $rows, ['source', 'seed', 'details']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function status(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$service = $this->service();
		$json = ($assocArgs['format'] ?? 'table') === 'json';
		$withCost = $context->can(Capabilities::MANAGE_KEYWORD_DISCOVERY);

		if (isset($assocArgs['run'])) {
			try {
				$run = $service->run($context, (string) $assocArgs['run']);
			} catch (DiscoveryNotFound) {
				WP_CLI::error('Run not found.');
			}

			$seeds = array_map(static fn (array $seed): array => [
				'seed' => (string) $seed['seed'],
				'source' => (string) $seed['source'],
				'status' => (string) $seed['status'],
				'pages' => (int) $seed['pages_done'],
				'items' => (int) $seed['items'],
				'new' => (int) $seed['candidates_new'],
				'cost' => $withCost ? round((float) $seed['cost'], 6) : null,
				'error' => $seed['error_code'],
			], $service->runSeeds($run));
			$data = ['run' => $withCost ? $run->toArray() : array_diff_key($run->toArray(), array_flip(['cost', 'estimated_cost'])), 'seeds' => $seeds];

			if ($json) {
				WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

				return;
			}

			\WP_CLI\Utils\format_items('table', array_map(static fn (string $key, mixed $value): array => ['field' => $key, 'value' => is_array($value) ? (string) wp_json_encode($value) : (string) ($value ?? '-')], array_keys($data['run']), $data['run']), ['field', 'value']);
			\WP_CLI\Utils\format_items('table', $seeds, ['seed', 'source', 'status', 'pages', 'items', 'new', 'cost', 'error']);

			return;
		}

		$status = $service->status($context);
		$status['recent_runs'] = array_map(static fn (DiscoveryRun $run): array => $withCost ? $run->toArray() : array_diff_key($run->toArray(), array_flip(['cost', 'estimated_cost'])), $service->recentRuns($context, 5));

		if ($json) {
			WP_CLI::line((string) wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			return;
		}

		$summary = $status['summary'];
		$rows = [
			['check' => 'provider', 'value' => $status['provider']],
			['check' => 'configured', 'value' => $status['configured'] ? 'yes' : 'no (missing: ' . implode(', ', $status['missing']) . ')'],
			['check' => 'market', 'value' => $status['market'] ?? 'unsupported'],
			['check' => 'active_run', 'value' => $status['active'] === null ? '-' : sprintf('%s %s (%d/%d seeds)', $status['active']['id'], $status['active']['status'], $status['active']['seeds_done'], $status['active']['seeds_total'])],
			['check' => 'candidates', 'value' => $summary === null ? '-' : sprintf('%d (excluded %d)', $summary['total'], $summary['excluded'])],
			['check' => 'by_status', 'value' => $summary === null ? '-' : (string) wp_json_encode($summary['statuses'])],
			['check' => 'by_visibility', 'value' => $summary === null ? '-' : (string) wp_json_encode($summary['visibility'])],
			['check' => 'last_refresh', 'value' => (string) ($status['refresh']['refreshed_at'] ?? '-')],
		];

		if ($status['budget'] !== null) {
			$rows[] = ['check' => 'budget (shared with market data)', 'value' => sprintf('today %.4f / %.2f, month %.4f / %.2f USD', $status['budget']['spent_today'], $status['budget']['daily_limit'], $status['budget']['spent_month'], $status['budget']['monthly_limit'])];
		}

		\WP_CLI\Utils\format_items('table', $rows, ['check', 'value']);

		if ($status['recent_runs'] !== []) {
			\WP_CLI\Utils\format_items('table', $status['recent_runs'], array_values(array_filter(['id', 'status', 'method', 'seeds', 'candidates_new', 'tasks_done', $withCost ? 'cost' : null, 'created_at'])));
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function list(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$page = $this->service()->list($context, CandidateFilters::fromInput([
			'status' => $assocArgs['status'] ?? 'open',
			'visibility' => $assocArgs['visibility'] ?? 'gap',
			'sort' => $assocArgs['sort'] ?? 'priority',
			'q' => $assocArgs['search'] ?? '',
			'page' => $assocArgs['page'] ?? '1',
		]));

		if ($page === null) {
			WP_CLI::error('The project market is not supported by the provider.');
		}

		$rows = array_map(static fn (CandidateRow $row): array => $row->toArray(), $page->rows);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			WP_CLI::line((string) wp_json_encode(['total' => $page->total, 'page' => $page->filters->page, 'items' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			return;
		}

		WP_CLI::log(sprintf('%d candidates (page %d of %d).', $page->total, $page->filters->page, $page->pages()));

		if ($rows !== []) {
			\WP_CLI\Utils\format_items('table', $rows, ['id', 'keyword', 'priority', 'search_volume', 'keyword_difficulty', 'visibility', 'gsc_position', 'seeds', 'status']);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function cancel(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_DISCOVERY);

		try {
			$cancelled = $this->service()->cancel($context, (string) $assocArgs['run']);
		} catch (DiscoveryNotFound) {
			WP_CLI::error('Run not found.');
		}

		$cancelled ? WP_CLI::success('Run cancelled.') : WP_CLI::warning('The run is not active.');
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function refresh(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		WP_CLI::success(sprintf('Recalculated: %d candidates changed (no API request).', $this->service()->refresh($context)));
	}

	/**
	 * @param array<string, string> $assocArgs
	 */
	private function request(array $assocArgs): DiscoveryRequest
	{
		$seeds = (string) ($assocArgs['seeds'] ?? '');

		if (isset($assocArgs['seeds-file'])) {
			$file = (string) $assocArgs['seeds-file'];

			if (! is_readable($file) || ! is_file($file)) {
				WP_CLI::error('Seeds file is not readable.');
			}

			$seeds .= "\n" . (string) file_get_contents($file, false, null, 0, 65536);
		}

		return $this->service()->request([
			'seeds' => $seeds,
			'method' => $assocArgs['method'] ?? 'related',
			'depth' => $assocArgs['depth'] ?? null,
			'max_candidates' => $assocArgs['limit'] ?? null,
			'min_volume' => $assocArgs['min-volume'] ?? null,
			'max_kd' => $assocArgs['max-kd'] ?? null,
			'other_language' => isset($assocArgs['include-other-languages']) ? '1' : '',
			'force' => isset($assocArgs['force']) ? '1' : '',
		]);
	}

	private function printPlan(DiscoveryPlan $plan): void
	{
		$data = $plan->toArray();
		$rows = [
			['field' => 'market', 'value' => $data['market'] === null ? 'unsupported' : sprintf('%s (location_code %d, language_code %s)', $data['market'], $data['location_code'], $data['language_code'])],
			['field' => 'method', 'value' => $data['method'] . ($data['depth'] === null ? '' : ' (depth ' . $data['depth'] . ')')],
			['field' => 'max_candidates', 'value' => (string) $data['max_candidates']],
			['field' => 'items_per_seed', 'value' => (string) $data['seed_limit']],
			['field' => 'filters', 'value' => sprintf('min volume %d, max KD %s, other languages %s', $data['min_volume'], $data['max_difficulty'] ?? '-', $data['skip_other_language'] ? 'skipped' : 'kept')],
			['field' => 'seeds', 'value' => sprintf('%d (cached within %d days: %d)', count($data['seeds']), $data['ttl_days'], $data['cached_seeds'])],
			['field' => 'paid_requests', 'value' => (string) $data['requests']],
			['field' => 'max_items', 'value' => (string) $data['max_items']],
			['field' => 'estimated_max_cost_usd', 'value' => sprintf('%.4f', $data['estimated_max_cost'])],
			['field' => 'remaining_budget_usd', 'value' => sprintf('today %.4f, month %.4f%s', $data['remaining_today'], $data['remaining_month'], $data['blocked_by'] === null ? '' : ' — exceeds ' . $data['blocked_by'])],
		];

		if ($data['skip_reason'] !== null) {
			$rows[] = ['field' => 'cannot_run', 'value' => $data['skip_reason']];
		}

		\WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);

		if ($data['seeds'] !== []) {
			\WP_CLI\Utils\format_items('table', array_map(static fn (array $seed): array => [
				'seed' => $seed['seed'],
				'source' => $seed['source'],
				'requests' => $seed['requests'],
				'max_items' => $seed['max_items'],
				'max_cost' => sprintf('%.4f', $seed['estimated_cost']),
				'cached_at' => $seed['cached_at'] ?? '-',
			], $data['seeds']), ['seed', 'source', 'requests', 'max_items', 'max_cost', 'cached_at']);
		}

		foreach ($data['rejected'] as $rejected) {
			WP_CLI::warning(sprintf('Seed "%s" rejected: %s.', $rejected['seed'], SeedList::reasonLabel($rejected['reason'])));
		}
	}

	private static function startMessage(DiscoveryStartResult $result): string
	{
		return match ($result->status) {
			DiscoveryStartResult::NOTHING_TO_DO => 'All seeds were fetched recently (cache) — nothing to send. Use --force to fetch them again.',
			DiscoveryStartResult::PLAN_CHANGED => 'The plan changed since the preview — check it again.',
			DiscoveryStartResult::ALREADY_RUNNING => 'Another discovery run of this project is active.',
			DiscoveryStartResult::RATE_LIMITED => 'A manual run was started a moment ago.',
			DiscoveryStartResult::NOT_CONFIGURED => 'DataForSEO is not configured' . ($result->reason === null || $result->reason === '' ? '.' : ' (missing: ' . $result->reason . ').'),
			DiscoveryStartResult::UNSUPPORTED_MARKET => 'The project market is not supported by the provider.',
			DiscoveryStartResult::NO_SEEDS => 'No valid seed keywords.',
			DiscoveryStartResult::OVER_BUDGET => 'The maximum cost exceeds the remaining budget (' . $result->reason . ').',
			DiscoveryStartResult::PAUSED => 'Paid DataForSEO requests are paused after an account error.',
			default => 'Run not started: ' . $result->status . '.',
		};
	}

	private function service(): DiscoveryService
	{
		return $this->plugin->get(DiscoveryService::class);
	}
}
