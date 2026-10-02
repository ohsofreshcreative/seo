<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Gap\GapFilters;
use OsfSeo\Gap\GapNotFound;
use OsfSeo\Gap\GapPlan;
use OsfSeo\Gap\GapRun;
use OsfSeo\Gap\GapService;
use OsfSeo\Gap\GapStartResult;
use OsfSeo\Gap\GapStatus;
use OsfSeo\Gap\PlannedTarget;
use OsfSeo\Plugin;
use OsfSeo\Support\ValidationException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp osf-seo gap:*` — Luki SEO (DataForSEO Labs Ranked Keywords). Płatne żądania wysyłają wyłącznie `gap:run`
 * (po pokazaniu planu i potwierdzeniu) oraz tło; wszystkie pozostałe komendy działają bez API.
 * Wyjście nigdy nie zawiera danych logowania.
 */
final class GapCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];
		$planArgs = [
			$project,
			['type' => 'assoc', 'name' => 'competitors', 'description' => 'Only these competitors (public IDs, comma separated). Default: all active competitors.', 'optional' => true],
			['type' => 'assoc', 'name' => 'preset', 'description' => 'Coverage preset: quick (TOP10, volume >= 50, 2000), standard (TOP30, volume >= 10, 10000), full (TOP100, volume >= 0, 10000).', 'optional' => true, 'options' => ['quick', 'standard', 'full']],
			['type' => 'assoc', 'name' => 'max-rank', 'description' => 'Worst competitor position to import (10, 20, 30, 50, 100).', 'optional' => true],
			['type' => 'assoc', 'name' => 'min-volume', 'description' => 'Minimum search volume (provider-side filter).', 'optional' => true],
			['type' => 'assoc', 'name' => 'max-rows', 'description' => 'Maximum keywords per domain (100–10000).', 'optional' => true],
			['type' => 'flag', 'name' => 'no-baseline', 'description' => 'Skip the project domain baseline.', 'optional' => true],
			['type' => 'flag', 'name' => 'force', 'description' => 'Import again even if the shared domain dataset is fresh.', 'optional' => true],
		];

		WP_CLI::add_command('osf-seo gap:plan', [$command, 'plan'], [
			'shortdesc' => 'Plan a competitor keyword import without any API request: domains, cached datasets, requests, maximum cost and budget.',
			'synopsis' => [...$planArgs, $format],
		]);
		WP_CLI::add_command('osf-seo gap:run', [$command, 'run'], [
			'shortdesc' => 'Run a competitor keyword import (paid DataForSEO Labs Ranked Keywords): shows the plan, asks for confirmation, imports page by page.',
			'synopsis' => [
				...$planArgs,
				['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation.', 'optional' => true],
				['type' => 'flag', 'name' => 'queue-only', 'description' => 'Only queue the import — pages are fetched by the background step.', 'optional' => true],
				['type' => 'assoc', 'name' => 'max-seconds', 'description' => 'Time budget for this process (default 300).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo gap:status', [$command, 'status'], [
			'shortdesc' => 'Keyword gap status: datasets, last imports, active import, costs (no API call).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'run', 'description' => 'Import (run) public ID.', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo gap:cancel', [$command, 'cancel'], [
			'shortdesc' => 'Cancel an import (pages already fetched are kept).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'run', 'description' => 'Import (run) public ID.', 'optional' => false]],
		]);
		WP_CLI::add_command('osf-seo gap:recalculate', [$command, 'recalculate'], [
			'shortdesc' => 'Recalculate keyword gaps, clusters, content gaps and competitor pages from stored data (free, no API call).',
			'synopsis' => [$project, $format],
		]);
		WP_CLI::add_command('osf-seo gap:list', [$command, 'list'], [
			'shortdesc' => 'List keyword gaps (no API call). Competitor position is from the DataForSEO Labs database, not our SERP check.',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'type', 'description' => 'gaps (missing, weak, unknown), all, missing, weak, competitive, stronger, unknown.', 'optional' => true, 'default' => 'gaps'],
				['type' => 'assoc', 'name' => 'content', 'description' => 'Content gap: improve, new_page, unclear, covered.', 'optional' => true],
				['type' => 'assoc', 'name' => 'competitor', 'description' => 'Competitor public ID.', 'optional' => true],
				['type' => 'assoc', 'name' => 'intent', 'description' => 'informational, navigational, commercial, transactional.', 'optional' => true],
				['type' => 'assoc', 'name' => 'status', 'description' => 'all, new, review, accepted, dismissed (default: all except dismissed).', 'optional' => true],
				['type' => 'assoc', 'name' => 'min-volume', 'description' => 'Minimum search volume.', 'optional' => true],
				['type' => 'assoc', 'name' => 'max-kd', 'description' => 'Maximum keyword difficulty.', 'optional' => true],
				['type' => 'assoc', 'name' => 'min-priority', 'description' => 'Minimum gap priority.', 'optional' => true],
				['type' => 'flag', 'name' => 'filtered', 'description' => 'Show filtered keywords (brand, exclusions, relevance) instead.', 'optional' => true],
				['type' => 'assoc', 'name' => 'sort', 'description' => implode(', ', GapFilters::SORTS) . '.', 'optional' => true, 'default' => 'priority'],
				['type' => 'assoc', 'name' => 'search', 'description' => 'Keyword contains.', 'optional' => true],
				['type' => 'assoc', 'name' => 'page', 'description' => 'Page (50 per page).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo gap:keyword', [$command, 'keyword'], [
			'shortdesc' => 'Keyword gap detail: priority breakdown, project evidence (SERP, GSC, Labs), every competitor, content gap (no API call).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'keyword', 'description' => 'Gap public ID or keyword text.', 'optional' => false], $format],
		]);
		WP_CLI::add_command('osf-seo gap:content', [$command, 'content'], [
			'shortdesc' => 'Content gaps: keyword clusters with a deterministic content-gap heuristic (no API call).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'cluster', 'description' => 'Cluster public ID (detail).', 'optional' => true],
				['type' => 'assoc', 'name' => 'type', 'description' => 'improve, new_page, unclear, covered (default: improve, new_page, unclear).', 'optional' => true],
				['type' => 'assoc', 'name' => 'page', 'description' => 'Page (50 per page).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo gap:pages', [$command, 'pages'], [
			'shortdesc' => 'Competitor pages from imported keywords (no API call, no crawling).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'competitor', 'description' => 'Competitor public ID.', 'optional' => true],
				['type' => 'assoc', 'name' => 'url-key', 'description' => 'Page key (detail, requires --competitor).', 'optional' => true],
				['type' => 'assoc', 'name' => 'sort', 'description' => 'gap, keywords, volume, top10.', 'optional' => true, 'default' => 'gap'],
				['type' => 'assoc', 'name' => 'page', 'description' => 'Page (50 per page).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo gap:set-status', [$command, 'setStatus'], [
			'shortdesc' => 'Set the workflow status of keyword gaps or clusters (no API call).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'ids', 'description' => 'Gap or cluster public IDs (comma separated).', 'optional' => false],
				['type' => 'assoc', 'name' => 'status', 'description' => 'new, review, accepted, dismissed.', 'optional' => false],
				['type' => 'assoc', 'name' => 'kind', 'description' => 'keyword or cluster.', 'optional' => true, 'default' => 'keyword', 'options' => ['keyword', 'cluster']],
				['type' => 'assoc', 'name' => 'note', 'description' => 'Note.', 'optional' => true],
			],
		]);
		WP_CLI::add_command('osf-seo gap:settings', [$command, 'settings'], [
			'shortdesc' => 'Show or change keyword gap settings (no API call; enabling the schedule means future paid imports and asks for confirmation).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'competitor-max-rank', 'description' => 'Meaningful competitor position (10, 20, 30, 50).', 'optional' => true],
				['type' => 'assoc', 'name' => 'min-volume', 'description' => 'Minimum search volume of a gap.', 'optional' => true],
				['type' => 'assoc', 'name' => 'max-kd', 'description' => 'Maximum keyword difficulty (empty = none).', 'optional' => true],
				['type' => 'assoc', 'name' => 'fetch-max-rank', 'description' => 'Default import coverage: worst position.', 'optional' => true],
				['type' => 'assoc', 'name' => 'fetch-min-volume', 'description' => 'Default import coverage: minimum volume.', 'optional' => true],
				['type' => 'assoc', 'name' => 'max-rows', 'description' => 'Default import coverage: keywords per domain.', 'optional' => true],
				['type' => 'assoc', 'name' => 'include', 'description' => 'Topic words (exclusion syntax; empty = all).', 'optional' => true],
				['type' => 'assoc', 'name' => 'brand', 'description' => 'Project brand variants.', 'optional' => true],
				['type' => 'assoc', 'name' => 'refresh-days', 'description' => 'Refresh interval (7–180 days).', 'optional' => true],
				['type' => 'flag', 'name' => 'enable-schedule', 'description' => 'Enable scheduled paid refreshes.', 'optional' => true],
				['type' => 'flag', 'name' => 'disable-schedule', 'description' => 'Disable scheduled refreshes.', 'optional' => true],
				['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation.', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo gap:brand', [$command, 'brand'], [
			'shortdesc' => 'Set brand variants of a competitor (brand keywords are not gaps; no API call).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'competitor', 'description' => 'Competitor public ID.', 'optional' => false],
				['type' => 'assoc', 'name' => 'terms', 'description' => 'Brand variants (comma or newline separated, `*` suffix allowed).', 'optional' => false],
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function plan(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_GAP);
		$service = $this->service();
		$plan = $service->plan($context, $service->request($context, self::requestInput($assocArgs)));

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($plan->toArray());

			return;
		}

		self::printPlan($plan);
		WP_CLI::log('No API request was made.');
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function run(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_GAP);
		$service = $this->service();
		$json = ($assocArgs['format'] ?? 'table') === 'json';
		$request = $service->request($context, self::requestInput($assocArgs));
		$plan = $service->plan($context, $request);

		if (! $json) {
			self::printPlan($plan);
		}

		if ($plan->skipReason === null && $plan->requests() > 0 && ! isset($assocArgs['yes'])) {
			WP_CLI::confirm(sprintf('Import up to %d paid DataForSEO Labs request(s), estimated maximum cost %.4f USD?', $plan->requests(), $plan->estimatedCost()));
		}

		$result = $service->start($context, $request, GapService::TRIGGER_CLI, $plan->requests(), $plan->estimatedCost());

		if (! $result->isQueued()) {
			if ($json) {
				self::json($result->toArray());
			}

			$message = match ($result->status) {
				GapStartResult::NOTHING_TO_DO => 'Nothing to import — all domain datasets are fresh. Gaps were recalculated.',
				GapStartResult::PLAN_CHANGED => 'The plan changed (more requests or a higher cost than shown). Run gap:plan again.',
				GapStartResult::ALREADY_RUNNING => 'An import of this project is already running.',
				GapStartResult::NOT_CONFIGURED => 'DataForSEO is not configured.',
				GapStartResult::UNSUPPORTED_MARKET => 'The project market is not supported.',
				GapStartResult::NO_COMPETITORS => 'The project has no active competitors.',
				GapStartResult::OVER_BUDGET => 'The import exceeds the shared DataForSEO budget (' . $result->reason . ').',
				GapStartResult::PAUSED => 'Paid DataForSEO calls are paused after an account error.',
				default => $result->status,
			};
			$result->status === GapStartResult::NOTHING_TO_DO ? ($json ? null : WP_CLI::success($message)) : WP_CLI::error($message);

			return;
		}

		$run = $result->run ?? throw new \LogicException('Queued result without run.');

		if (isset($assocArgs['queue-only'])) {
			$json ? self::json($run->toArray()) : WP_CLI::success(sprintf('Import %s queued — pages are fetched by the background step.', $run->publicId));

			return;
		}

		$report = $service->execute($context, $run, max(10.0, (float) ($assocArgs['max-seconds'] ?? 300)));
		$current = $service->run($context, $run->publicId);

		if ($json) {
			self::json(['report' => $report, 'run' => $current->toArray(), 'targets' => $service->runTargets($context, $current)]);

			return;
		}

		WP_CLI::log(sprintf('Requests: %d; reported cost %.4f USD (estimated maximum %.4f USD).', $current->requestsDone, $current->cost, $current->estimatedCost));
		$targets = $service->runTargets($context, $current);

		if ($targets !== []) {
			Utils\format_items('table', array_map(static fn (array $target): array => self::targetRow($target), $targets), array_keys(self::targetRow($targets[0])));
		}

		match (true) {
			$current->status === GapRun::PAUSED => WP_CLI::warning('Import paused: ' . (string) $current->blockedBy . '. It resumes in the background when the budget allows.'),
			$current->isActive() => WP_CLI::success('Import continues in the background (or run gap:run again later).'),
			default => WP_CLI::success(sprintf('Import %s: %s.', $current->publicId, $current->status)),
		};
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function status(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$service = $this->service();
		$withCost = $context->can(Capabilities::MANAGE_KEYWORD_GAP);
		$json = ($assocArgs['format'] ?? 'table') === 'json';

		if (isset($assocArgs['run'])) {
			try {
				$run = $service->run($context, (string) $assocArgs['run']);
			} catch (GapNotFound) {
				WP_CLI::error('Import not found.');
			}

			$targets = $service->runTargets($context, $run);
			$data = $run->toArray();

			if (! $withCost) {
				unset($data['estimated_cost'], $data['cost']);
			}

			if ($json) {
				self::json(['run' => $data, 'targets' => $targets]);

				return;
			}

			WP_CLI::log(sprintf('Import %s: %s; requests %d/%d; rows %d.', $run->publicId, $run->status, $run->requestsDone, $run->requestsPlanned, $run->rowsReceived));

			if ($withCost) {
				WP_CLI::log(sprintf('Reported cost %.4f USD (estimated maximum %.4f USD).', $run->cost, $run->estimatedCost));
			}

			if ($targets !== []) {
				Utils\format_items('table', array_map(static fn (array $target): array => self::targetRow($target), $targets), array_keys(self::targetRow($targets[0])));
			}

			return;
		}

		$datasets = $service->datasets($context);
		$counts = $service->counts($context);
		$runs = array_map(static fn (GapRun $run): array => $run->toArray(), $service->runs($context, 5));

		if ($json) {
			self::json(['market' => $service->market($context)?->label(), 'configured' => $service->provider()->isConfigured(), 'settings' => $service->settings($context)->toArray(), 'datasets' => $datasets, 'counts' => $counts, 'recent_imports' => $runs]);

			return;
		}

		WP_CLI::log(sprintf('Market: %s. Provider configured: %s.', $service->market($context)?->label() ?? 'unsupported', $service->provider()->isConfigured() ? 'yes' : 'no'));
		WP_CLI::log(sprintf(
			'Gaps: missing %d, weak %d, unknown %d; competitive %d, project stronger %d; high priority %d; filtered %d; content gaps (possible new page) %d; competitor pages %d.',
			$counts['types']['missing'] ?? 0,
			$counts['types']['weak'] ?? 0,
			$counts['types']['unknown'] ?? 0,
			$counts['types']['competitive'] ?? 0,
			$counts['types']['stronger'] ?? 0,
			$counts['high'],
			$counts['filtered'],
			$counts['content']['new_page'] ?? 0,
			$counts['pages'],
		));

		if ($datasets !== []) {
			Utils\format_items('table', array_map(static fn (array $dataset): array => [
				'domain' => $dataset['domain'],
				'role' => $dataset['role'],
				'state' => $dataset['dataset']['status'] ?? 'not imported',
				'fresh' => $dataset['fresh'] ? 'yes' : 'no',
				'complete' => ($dataset['dataset']['complete'] ?? false) ? 'yes' : 'no',
				'keywords' => $dataset['dataset']['rows_present'] ?? 0,
				'imported_at' => $dataset['dataset']['imported_at'] ?? '—',
				'stale_after' => $dataset['dataset']['stale_after'] ?? '—',
			], $datasets), ['domain', 'role', 'state', 'fresh', 'complete', 'keywords', 'imported_at', 'stale_after']);
		}

		if ($runs !== []) {
			Utils\format_items('table', array_map(static fn (array $run): array => [
				'import' => $run['id'],
				'status' => $run['status'],
				'trigger' => $run['trigger'],
				'requests' => $run['requests_done'] . '/' . $run['requests_planned'],
				'cost' => $withCost ? sprintf('%.4f', $run['cost']) : '—',
				'created' => $run['created_at'],
			], $runs), ['import', 'status', 'trigger', 'requests', 'cost', 'created']);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function cancel(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_GAP);

		try {
			$this->service()->cancel($context, (string) $assocArgs['run']) ? WP_CLI::success('Import cancelled. Fetched pages are kept.') : WP_CLI::warning('Import is not active.');
		} catch (GapNotFound) {
			WP_CLI::error('Import not found.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function recalculate(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_GAP);
		$report = $this->service()->recalculate($context);

		(($assocArgs['format'] ?? 'table') === 'json')
			? self::json($report)
			: WP_CLI::success(sprintf('Recalculated: %d competitor keywords, %d listed gaps, %d clusters, %d competitor pages. No API request was made.', $report['keywords'], $report['listed'], $report['clusters'], $report['pages']));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function list(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$filters = GapFilters::fromInput([
			'type' => $assocArgs['type'] ?? 'gaps',
			'content' => $assocArgs['content'] ?? '',
			'competitor' => $assocArgs['competitor'] ?? '',
			'intent' => $assocArgs['intent'] ?? '',
			'status' => $assocArgs['status'] ?? '',
			'min_volume' => $assocArgs['min-volume'] ?? '',
			'max_kd' => $assocArgs['max-kd'] ?? '',
			'min_priority' => $assocArgs['min-priority'] ?? '',
			'filtered' => isset($assocArgs['filtered']) ? '1' : '',
			'sort' => $assocArgs['sort'] ?? 'priority',
			'q' => $assocArgs['search'] ?? '',
			'page' => $assocArgs['page'] ?? '1',
		]);
		$result = $this->service()->keywords($context, $filters);
		$rows = array_map(static fn (array $row): array => [
			'id' => (string) $row['public_id'],
			'keyword' => (string) $row['keyword'],
			'priority' => (int) $row['priority'],
			'gap' => (string) $row['gap_type'],
			'volume' => $row['search_volume'] ?? '—',
			'kd' => $row['keyword_difficulty'] ?? '—',
			'intent' => $row['intent'] ?? '—',
			'best_competitor' => ($row['competitor_name'] ?? '—') . ($row['best_competitor_rank'] === null ? '' : ' #' . $row['best_competitor_rank'] . ' (Labs)'),
			'competitors' => (int) $row['competitors_count'],
			'project' => self::projectVisibility($row),
			'content_gap' => $row['content_gap'] ?? '—',
			'status' => (string) $row['status'],
			'filter' => $row['filter_reason'] ?? '',
		], $result['rows']);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json(['total' => $result['total'], 'page' => $filters->page, 'rows' => $rows]);

			return;
		}

		WP_CLI::log(sprintf('Gaps: %d (page %d). Competitor position = DataForSEO Labs database, not our SERP check.', $result['total'], $filters->page));

		if ($rows !== []) {
			$columns = array_keys($rows[0]);

			if (! $filters->filtered) {
				$columns = array_values(array_diff($columns, ['filter']));
			}

			Utils\format_items('table', $rows, $columns);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function keyword(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$service = $this->service();
		$id = $service->keywordId($context, (string) $assocArgs['keyword']);

		try {
			$detail = $id === null ? throw new GapNotFound() : $service->keyword($context, $id);
		} catch (GapNotFound) {
			WP_CLI::error('Keyword gap not found.');
		}

		$row = $detail['row'];
		$competitors = array_map(static fn (array $entry): array => [
			'competitor' => $entry['competitor']->name,
			'domain' => $entry['competitor']->domain,
			'labs_position' => match (true) {
				$entry['row'] === null => '—',
				(int) $entry['row']['present'] === 2 => 'unconfirmed (was ' . $entry['row']['rank_group'] . ')',
				(int) $entry['row']['present'] !== 1 => '—',
				default => (string) $entry['row']['rank_group'],
			},
			'url' => $entry['row']['url'] ?? '—',
			'labs_date' => $entry['row']['serp_on'] ?? '—',
			'first_seen' => $entry['row']['first_seen'] ?? '—',
		], $detail['competitors']);
		$data = [
			'id' => $row['public_id'],
			'keyword' => $row['keyword'],
			'gap' => $row['gap_type'],
			'priority' => (int) $row['priority'],
			'score' => $detail['score'],
			'volume' => $row['search_volume'],
			'kd' => $row['keyword_difficulty'],
			'cpc_usd' => $row['cpc'],
			'intent' => $row['intent'],
			'project_visibility' => self::projectVisibility($row),
			'gsc' => ['impressions' => $row['gsc_impressions'], 'clicks' => $row['gsc_clicks'], 'average_position' => $row['gsc_position']],
			'serp_position' => $row['serp_rank'],
			'labs_project_position' => $row['project_labs_rank'],
			'content_gap' => $row['content_gap'],
			'cluster' => $row['cluster_label'],
			'target_url' => $row['target_url'],
			'status' => $row['status'],
			'competitors' => $competitors,
			'events' => $detail['events'],
		];

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($data);

			return;
		}

		WP_CLI::log(sprintf('%s — %s, priority %d, volume %s, KD %s, intent %s.', $row['keyword'], $row['gap_type'], (int) $row['priority'], $row['search_volume'] ?? '—', $row['keyword_difficulty'] ?? '—', $row['intent'] ?? '—'));
		WP_CLI::log(sprintf('Project: %s. Content gap: %s; cluster: %s; target page: %s.', self::projectVisibility($row), $row['content_gap'] ?? '—', $row['cluster_label'] ?? '—', $row['target_url'] ?? 'none'));
		Utils\format_items('table', $competitors, ['competitor', 'domain', 'labs_position', 'url', 'labs_date', 'first_seen']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function content(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$service = $this->service();
		$json = ($assocArgs['format'] ?? 'table') === 'json';

		if (isset($assocArgs['cluster'])) {
			try {
				$detail = $service->cluster($context, (string) $assocArgs['cluster']);
			} catch (GapNotFound) {
				WP_CLI::error('Cluster not found.');
			}

			if ($json) {
				self::json($detail);

				return;
			}

			$cluster = $detail['cluster'];
			WP_CLI::log(sprintf('%s — %s (%s confidence, %s); %d keywords, gap volume %d; target page: %s.', $cluster['label'], $cluster['content_gap'], $cluster['confidence'], $cluster['content_reason'], (int) $cluster['keywords_count'], (int) $cluster['gap_volume'], $cluster['target_url'] ?? 'none'));
			Utils\format_items('table', array_map(static fn (array $row): array => ['keyword' => $row['keyword'], 'gap' => $row['gap_type'], 'volume' => $row['search_volume'] ?? '—', 'competitor_labs' => $row['best_competitor_rank'] ?? '—', 'priority' => $row['priority']], $detail['keywords']), ['keyword', 'gap', 'volume', 'competitor_labs', 'priority']);

			return;
		}

		$result = $service->clusters($context, $assocArgs['type'] ?? null, '', '', 'priority', max(1, (int) ($assocArgs['page'] ?? 1)));
		$rows = array_map(static fn (array $row): array => [
			'id' => $row['public_id'],
			'cluster' => $row['label'],
			'content_gap' => $row['content_gap'],
			'confidence' => $row['confidence'],
			'keywords' => (int) $row['keywords_count'],
			'gap_volume' => (int) $row['gap_volume'],
			'competitors' => (int) $row['competitors_count'],
			'best_labs' => $row['best_competitor_rank'] ?? '—',
			'target' => $row['target_url'] ?? '—',
			'priority' => (int) $row['priority'],
		], $result['rows']);

		if ($json) {
			self::json(['total' => $result['total'], 'rows' => $rows]);

			return;
		}

		WP_CLI::log(sprintf('Clusters: %d. Content gaps are heuristics to check, not a verdict.', $result['total']));

		if ($rows !== []) {
			Utils\format_items('table', $rows, array_keys($rows[0]));
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function pages(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$service = $this->service();
		$json = ($assocArgs['format'] ?? 'table') === 'json';

		if (isset($assocArgs['url-key'])) {
			try {
				$detail = $service->page($context, (string) ($assocArgs['competitor'] ?? ''), (string) $assocArgs['url-key']);
			} catch (GapNotFound) {
				WP_CLI::error('Competitor page not found.');
			}

			if ($json) {
				self::json(['page' => $detail['page'], 'keywords' => $detail['keywords']]);

				return;
			}

			WP_CLI::log(sprintf('%s — %s: %d keywords, TOP10 %d, gap keywords %d.', $detail['competitor']->name, $detail['page']['url'], (int) $detail['page']['keywords'], (int) $detail['page']['top10'], (int) $detail['page']['gap_keywords']));
			Utils\format_items('table', array_map(static fn (array $row): array => ['keyword' => $row['keyword'], 'labs_position' => $row['rank_group'], 'volume' => $row['search_volume'] ?? '—', 'gap' => $row['gap_type'] ?? '—'], $detail['keywords']), ['keyword', 'labs_position', 'volume', 'gap']);

			return;
		}

		$result = $service->pages($context, $assocArgs['competitor'] ?? null, '', (string) ($assocArgs['sort'] ?? 'gap'), max(1, (int) ($assocArgs['page'] ?? 1)));
		$rows = array_map(static fn (array $row): array => [
			'competitor' => $row['competitor_name'],
			'url' => $row['url'],
			'url_key' => $row['url_key_hex'],
			'keywords' => (int) $row['keywords'],
			'top3' => (int) $row['top3'],
			'top10' => (int) $row['top10'],
			'top20' => (int) $row['top20'],
			'volume' => (int) $row['total_volume'],
			'gap_keywords' => (int) $row['gap_keywords'],
			'intent' => $row['main_intent'] ?? '—',
		], $result['rows']);

		if ($json) {
			self::json(['total' => $result['total'], 'rows' => $rows]);

			return;
		}

		WP_CLI::log(sprintf('Competitor pages: %d (from imported keywords).', $result['total']));

		if ($rows !== []) {
			Utils\format_items('table', $rows, array_keys($rows[0]));
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function setStatus(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_GAP);
		$status = GapStatus::fromInput($assocArgs['status'] ?? null) ?? WP_CLI::error('Invalid status.');
		$ids = array_values(array_filter(array_map('trim', explode(',', (string) $assocArgs['ids']))));
		$changed = $this->service()->setStatus($context, (string) ($assocArgs['kind'] ?? 'keyword'), $ids, $status, isset($assocArgs['note']) ? (string) $assocArgs['note'] : null);

		WP_CLI::success(sprintf('Updated: %d.', $changed));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function settings(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_GAP);
		$service = $this->service();
		$map = [
			'competitor-max-rank' => 'competitor_max_rank', 'min-volume' => 'min_volume', 'max-kd' => 'max_difficulty', 'fetch-max-rank' => 'fetch_max_rank',
			'fetch-min-volume' => 'fetch_min_volume', 'max-rows' => 'max_rows', 'include' => 'include_terms', 'brand' => 'brand_terms', 'refresh-days' => 'refresh_days',
		];
		$input = [];

		foreach ($map as $option => $field) {
			if (array_key_exists($option, $assocArgs)) {
				$input[$field] = (string) $assocArgs[$option];
			}
		}

		try {
			$settings = $input === [] ? $service->settings($context) : $service->saveSettings($context, $input);

			if (isset($assocArgs['enable-schedule'])) {
				$monthly = $service->monthlyEstimate($context);

				if (! isset($assocArgs['yes'])) {
					WP_CLI::confirm(sprintf('Enable scheduled paid refreshes (every %d days, estimated maximum %.2f USD per month)?', $settings->refreshDays, $monthly));
				}

				$settings = $service->setSchedule($context, true, true);
			} elseif (isset($assocArgs['disable-schedule'])) {
				$settings = $service->setSchedule($context, false, false);
			}
		} catch (ValidationException $exception) {
			WP_CLI::error(implode(' ', $exception->errors()));
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($settings->toArray());

			return;
		}

		Utils\format_items('table', [array_map(static fn (mixed $value): string => is_bool($value) ? ($value ? 'yes' : 'no') : str_replace("\n", ', ', (string) $value), $settings->toArray())], array_keys($settings->toArray()));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function brand(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_KEYWORD_GAP);

		try {
			$competitor = $this->service()->saveBrandTerms($context, (string) $assocArgs['competitor'], (string) $assocArgs['terms']);
		} catch (GapNotFound) {
			WP_CLI::error('Competitor not found.');
		}

		WP_CLI::success(sprintf('Brand variants of %s saved: %s.', $competitor->domain, $competitor->brandTerms === '' ? '—' : str_replace("\n", ', ', $competitor->brandTerms)));
	}

	private static function printPlan(GapPlan $plan): void
	{
		if ($plan->skipReason !== null) {
			WP_CLI::log('Nothing to import: ' . $plan->skipReason . '.');
		}

		WP_CLI::log(sprintf(
			'Market: %s; coverage: TOP%d, volume >= %d, up to %d keywords per domain; organic only (DataForSEO Labs Ranked Keywords, Live).',
			$plan->market?->label() ?? 'unsupported',
			$plan->request->coverage->maxRank,
			$plan->request->coverage->minVolume,
			$plan->request->coverage->maxRows,
		));

		if ($plan->targets !== []) {
			Utils\format_items('table', array_map(static fn (PlannedTarget $target): array => [
				'domain' => $target->domain,
				'role' => $target->role,
				'state' => $target->state,
				'coverage' => sprintf('TOP%d, vol>=%d, max %d', $target->coverage->maxRank, $target->coverage->minVolume, $target->coverage->maxRows),
				'known_total' => $target->knownTotal ?? '—',
				'max_requests' => $target->maxRequests,
				'max_cost' => sprintf('%.4f', $target->maxCost),
				'expected_cost' => sprintf('%.4f', $target->expectedCost),
			], $plan->targets), ['domain', 'role', 'state', 'coverage', 'known_total', 'max_requests', 'max_cost', 'expected_cost']);
		}

		foreach ($plan->skipped as $skipped) {
			WP_CLI::log(sprintf('Skipped %s: %s.', $skipped['domain'], $skipped['reason']));
		}

		WP_CLI::log(sprintf('Requests: up to %d; estimated maximum cost: %.4f USD (expected %.4f USD). Provider-reported cost is authoritative.', $plan->requests(), $plan->estimatedCost(), $plan->expectedCost()));
		WP_CLI::log(sprintf('Shared DataForSEO budget remaining: %.4f USD today, %.4f USD this month.', $plan->remainingToday(), $plan->remainingMonth()));

		if ($plan->spansDays()) {
			WP_CLI::warning(sprintf('The import exceeds today\'s remaining budget — it pauses and resumes on following days (about %d day(s)).', (int) $plan->daysEstimate()));
		}

		if ($plan->blockedBy() !== null) {
			WP_CLI::warning('The import exceeds the shared budget: ' . $plan->blockedBy() . '.');
		}
	}

	/**
	 * @param array<string, string> $assocArgs
	 * @return array<string, string>
	 */
	private static function requestInput(array $assocArgs): array
	{
		return array_filter([
			'competitors' => $assocArgs['competitors'] ?? null,
			'preset' => $assocArgs['preset'] ?? null,
			'max_rank' => $assocArgs['max-rank'] ?? null,
			'min_volume' => $assocArgs['min-volume'] ?? null,
			'max_rows' => $assocArgs['max-rows'] ?? null,
			'baseline' => isset($assocArgs['no-baseline']) ? '0' : '1',
			'force' => isset($assocArgs['force']) ? '1' : null,
		], static fn (?string $value): bool => $value !== null);
	}

	/**
	 * @param array<string, mixed> $target
	 * @return array<string, string|int>
	 */
	private static function targetRow(array $target): array
	{
		return [
			'domain' => (string) $target['domain'],
			'role' => (string) $target['role'],
			'status' => (string) $target['status'],
			'pages' => (int) $target['pages_done'],
			'keywords' => (int) $target['rows_unique'],
			'total' => $target['total_count'] ?? '—',
			'new' => (int) $target['rows_new'],
			'lost' => (int) $target['rows_lost'],
			'cost' => sprintf('%.4f', $target['cost']),
			'error' => (string) ($target['error_code'] ?? ''),
			'unreliable' => (string) ($target['unreliable'] ?? ''),
		];
	}

	/**
	 * Widoczność projektu w wyjściu CLI (po angielsku) — zawsze ze źródłem.
	 *
	 * @param array<string, string|null> $row
	 */
	private static function projectVisibility(array $row): string
	{
		$source = match ($row['visibility_source']) {
			'serp' => 'SERP',
			'gsc' => 'GSC avg.',
			'labs' => 'Labs',
			default => null,
		};
		$label = match ($row['visibility']) {
			'none' => 'none',
			'low' => (int) $row['sporadic'] === 1 ? 'low (sporadic)' : 'low',
			'visible' => 'visible',
			default => 'unknown',
		};

		return $source === null ? $label : sprintf('%s · %s%s', $label, $source, $row['project_position'] === null ? '' : ' ' . rtrim(rtrim((string) $row['project_position'], '0'), '.'));
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function json(array $data): void
	{
		WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function service(): GapService
	{
		return $this->plugin->get(GapService::class);
	}
}
