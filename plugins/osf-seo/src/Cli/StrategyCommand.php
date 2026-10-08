<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Plugin;
use OsfSeo\Serp\SerpRun;
use OsfSeo\Serp\SerpStartResult;
use OsfSeo\Serp\SerpTrackingService;
use OsfSeo\Strategy\CandidateFilters;
use OsfSeo\Strategy\CandidateRow;
use OsfSeo\Strategy\Serp\SerpAnalysisPlan;
use OsfSeo\Strategy\Serp\SerpAnalysisService;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\StrategySource;
use OsfSeo\Support\ValidationException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp osf-seo strategy:*` — Strategia (STEP 16, faza A): stan, podgląd i lista kandydatów, fakty i dowody frazy, przeliczenie,
 * wpisy ręczne. Żadna komenda nie wysyła żądań do API. Wyjście po angielsku; `--format=json` wyłącznie JSON.
 */
final class StrategyCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];
		$keywords = ['type' => 'assoc', 'name' => 'keywords', 'description' => 'Keywords (comma, semicolon or new line separated) or candidate IDs.', 'optional' => false];

		WP_CLI::add_command('osf-seo strategy:status', [$command, 'status'], [
			'shortdesc' => 'Strategy status: limits, market, last refresh, whether the data key is up to date, GSC window, candidate counts (no API call).',
			'synopsis' => [$project, $format],
		]);
		WP_CLI::add_command('osf-seo strategy:preview', [$command, 'preview'], [
			'shortdesc' => 'Preview strategy candidates from all sources WITHOUT writing anything: sources, filters, limit, sample (no API call).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'limit', 'description' => 'Sample size (default 20).', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo strategy:refresh', [$command, 'refresh'], [
			'shortdesc' => 'Refresh strategy candidates, facts and evidence from stored data (no API call; only when the data key changed).',
			'synopsis' => [$project, ['type' => 'flag', 'name' => 'force', 'description' => 'Refresh even if the data key did not change.', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo strategy:candidates', [$command, 'candidates'], [
			'shortdesc' => 'List strategy candidates (no API call). SERP position and GSC average position are different metrics.',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'source', 'description' => implode(', ', StrategySource::values()) . '.', 'optional' => true],
				['type' => 'assoc', 'name' => 'status', 'description' => 'active, inactive, all.', 'optional' => true, 'default' => 'active'],
				['type' => 'assoc', 'name' => 'search', 'description' => 'Keyword contains.', 'optional' => true],
				['type' => 'assoc', 'name' => 'sort', 'description' => implode(', ', CandidateFilters::SORTS) . '.', 'optional' => true, 'default' => 'tier'],
				['type' => 'assoc', 'name' => 'page', 'description' => 'Page.', 'optional' => true],
				['type' => 'assoc', 'name' => 'per-page', 'description' => 'Rows per page (default 50).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo strategy:keyword', [$command, 'keyword'], [
			'shortdesc' => 'Strategy candidate detail: facts and evidence from every source (no API call).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'keyword', 'description' => 'Candidate ID (ULID) or keyword text.', 'optional' => false], $format],
		]);
		WP_CLI::add_command('osf-seo strategy:add', [$command, 'add'], [
			'shortdesc' => 'Add keywords to the strategy manually (no API call, no enrichment).',
			'synopsis' => [$project, $keywords],
		]);
		WP_CLI::add_command('osf-seo strategy:remove', [$command, 'remove'], [
			'shortdesc' => 'Remove the manual flag of strategy candidates (they stay if other sources support them).',
			'synopsis' => [$project, $keywords],
		]);
		$selection = ['type' => 'assoc', 'name' => 'keywords', 'description' => 'Candidates (IDs or keywords, comma separated). Default: strategy priority order.', 'optional' => true];
		WP_CLI::add_command('osf-seo strategy:serp-plan', [$command, 'serpPlan'], [
			'shortdesc' => 'Plan a one-off strategy SERP analysis WITHOUT any request: reused measurements, new tasks, estimated maximum cost, budget.',
			'synopsis' => [$project, $selection, $format],
		]);
		WP_CLI::add_command('osf-seo strategy:serp-run', [$command, 'serpRun'], [
			'shortdesc' => 'PAID: queue a one-off strategy SERP analysis (Google Organic SERP via the SERP tracking submitter, shared DataForSEO limits) and submit it.',
			'synopsis' => [
				$project,
				$selection,
				['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation.', 'optional' => true],
				['type' => 'flag', 'name' => 'queue-only', 'description' => 'Only queue (the background step submits the tasks).', 'optional' => true],
				['type' => 'assoc', 'name' => 'wait', 'description' => 'Wait up to N seconds and collect results.', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo strategy:serp-status', [$command, 'serpStatus'], [
			'shortdesc' => 'Strategy SERP analysis status: analysis keywords, measured, recent analysis runs (no API call).',
			'synopsis' => [$project, $format],
		]);
		WP_CLI::add_command('osf-seo strategy:serp', [$command, 'serp'], [
			'shortdesc' => 'SERP intelligence of a candidate: latest compatible measurement, freshness, shape, composition, TOP20 shapes (no API call).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'keyword', 'description' => 'Candidate ID (ULID) or keyword text.', 'optional' => false], $format],
		]);
		WP_CLI::add_command('osf-seo strategy:serp-overlap', [$command, 'serpOverlap'], [
			'shortdesc' => 'SERP overlap of two candidates (shared TOP10 URLs and domains, safeguards, no API call).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'keywords', 'description' => 'Two candidates (IDs or keywords), comma separated.', 'optional' => false], $format],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function status(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$status = $this->service()->status($context);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($status);

			return;
		}

		$counts = $status['counts'];
		$gsc = $status['gsc'];
		WP_CLI::log(sprintf('Market: %s. Limit: %d candidates (SERP analysis: %d keywords per run, from phase B).', $status['market'] ?? 'unsupported', $status['config']['max_keywords'], $status['config']['serp_max_per_run']));
		WP_CLI::log(sprintf(
			'Candidates: %d active (%d manual), inactive: %s.',
			$counts['active'],
			$counts['manual'],
			$counts['inactive'] === [] ? 'none' : implode(', ', array_map(static fn (string $reason, int $count): string => $reason . ' ' . $count, array_keys($counts['inactive']), $counts['inactive'])),
		));
		WP_CLI::log(sprintf(
			'GSC window: %s (complete: query %s, query_page %s; keywords without market key: %d).',
			$gsc === null || $gsc['window'] === null ? 'no GSC data' : implode(' – ', $gsc['window']),
			$gsc !== null && $gsc['complete']['query'] ? 'yes' : 'no',
			$gsc !== null && $gsc['complete']['query_page'] ? 'yes' : 'no',
			$gsc === null ? 0 : $gsc['unkeyed'],
		));
		WP_CLI::log(sprintf(
			'Topics: %d active (%s), inactive %d.',
			$status['topics']['active'],
			$status['topics']['actions'] === [] ? 'none' : self::pairs($status['topics']['actions']),
			$status['topics']['inactive'],
		));
		WP_CLI::log(sprintf('Last refresh: %s%s. Data key: %s.', $status['refreshed_at'] ?? 'never', $status['refresh_ms'] === null ? '' : ' (' . $status['refresh_ms'] . ' ms)', $status['up_to_date'] ? 'up to date' : 'changed — run strategy:refresh'));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function preview(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$preview = $this->service()->preview($context, max(0, min(200, (int) ($assocArgs['limit'] ?? 20))));

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($preview);

			return;
		}

		if ($preview['skipped'] !== null) {
			WP_CLI::error('Preview not available: ' . $preview['skipped'] . '.');
		}

		$stats = $preview['stats'];
		WP_CLI::log(sprintf('Preview (no data written, no API request). Market: %s.', $preview['market']));
		WP_CLI::log(sprintf(
			'Selected: %d of limit %d; over the limit: %d; filtered: %s; new market keywords: %d.',
			$stats['selected'],
			$stats['limit'],
			$stats['overflow'],
			$stats['filtered'] === [] ? 'none' : self::pairs($stats['filtered']),
			$stats['new_market_keywords'],
		));
		WP_CLI::log('Signals by source: ' . ($stats['signals'] === [] ? 'none' : self::pairs($stats['signals'])) . '.');
		WP_CLI::log('Selected by source: ' . self::pairs($stats['selected_by_source']) . '.');

		if ($preview['gsc']['unkeyed'] > 0) {
			WP_CLI::warning(sprintf('%d GSC keywords have no market key yet and are not in this preview (computed in the background or by strategy:refresh).', $preview['gsc']['unkeyed']));
		}

		if ($preview['sample'] !== []) {
			Utils\format_items('table', array_map(static fn (array $row): array => [
				'keyword' => $row['keyword'],
				'sources' => implode(', ', $row['sources']),
				'tier' => $row['tier'],
				'weight' => $row['weight'],
			], $preview['sample']), ['keyword', 'sources', 'tier', 'weight']);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function refresh(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_STRATEGY);

		try {
			$report = $this->service()->refresh($context, isset($assocArgs['force']));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($report);

			return;
		}

		if ($report['skipped'] !== null) {
			WP_CLI::success(sprintf('Nothing refreshed: %s. No API request was made.', $report['skipped']));

			return;
		}

		WP_CLI::success(sprintf(
			'Refreshed in %d ms: %d selected (%d new, %d updated, %d unchanged, %d deactivated); over the limit %d; topics %d (%d new, %d updated, %d deactivated, %d events). No API request was made.',
			$report['duration_ms'],
			$report['stats']['selected'],
			$report['inserted'],
			$report['updated'],
			$report['unchanged'],
			$report['deactivated'],
			$report['stats']['overflow'],
			$report['topics']['topics'],
			$report['topics']['inserted'],
			$report['topics']['updated'],
			$report['topics']['deactivated'],
			$report['topics']['events'],
		));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function candidates(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$filters = CandidateFilters::fromInput([
			'source' => $assocArgs['source'] ?? '',
			'status' => $assocArgs['status'] ?? 'active',
			'q' => $assocArgs['search'] ?? '',
			'sort' => $assocArgs['sort'] ?? 'tier',
			'page' => $assocArgs['page'] ?? '1',
			'per_page' => $assocArgs['per-page'] ?? (string) CandidateFilters::PER_PAGE,
		]);
		$result = $this->service()->candidates($context, $filters);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json(['total' => $result['total'], 'page' => $filters->page, 'rows' => array_map(static fn (CandidateRow $row): array => $row->toArray(), $result['rows'])]);

			return;
		}

		WP_CLI::log(sprintf('Candidates: %d (page %d). GSC position = average position (GSC); SERP = our SERP check.', $result['total'], $filters->page));

		if ($result['rows'] !== []) {
			Utils\format_items('table', array_map(static fn (CandidateRow $row): array => [
				'id' => $row->publicId,
				'keyword' => $row->keyword,
				'sources' => $row->sources === [] ? '—' : implode(', ', array_map(static fn (StrategySource $source): string => $source->value, $row->sources)),
				'tier' => $row->tier ?? '—',
				'gsc_impressions' => $row->gscImpressions ?? '—',
				'gsc_position' => $row->gscPosition ?? '—',
				'serp' => $row->serpCheckedAt === null ? '—' : ($row->serpFound ? '#' . $row->serpRank : 'out'),
				'volume' => $row->searchVolume ?? '—',
				'kd' => $row->keywordDifficulty ?? '—',
				'opportunities' => $row->opportunities,
				'status' => $row->active ? 'active' : 'inactive: ' . $row->inactiveReason,
			], $result['rows']), ['id', 'keyword', 'sources', 'tier', 'gsc_impressions', 'gsc_position', 'serp', 'volume', 'kd', 'opportunities', 'status']);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function keyword(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);

		try {
			$row = $this->service()->keyword($context, (string) $assocArgs['keyword']);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy candidate not found.');
		}

		$data = $row->toArray(true);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($data);

			return;
		}

		WP_CLI::log(sprintf('%s — %s; sources: %s.', $row->keyword, $row->active ? 'active' : 'inactive (' . $row->inactiveReason . ')', $data['sources'] === [] ? 'none' : implode(', ', $data['sources'])));
		WP_CLI::log((string) wp_json_encode($row->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function add(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_STRATEGY);

		try {
			$result = $this->service()->addKeywords($context, (string) $assocArgs['keywords']);
		} catch (ValidationException $exception) {
			WP_CLI::error(implode(' ', $exception->errors()));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		foreach ($result['rejected'] as $rejected) {
			WP_CLI::warning(sprintf('Rejected "%s": %s.', $rejected['keyword'], $rejected['reason']));
		}

		WP_CLI::success(sprintf('Added %d, already manual %d. Run strategy:refresh to build facts. No API request was made.', $result['added'], $result['existing']));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function remove(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_STRATEGY);
		$values = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/u', (string) $assocArgs['keywords']) ?: []), static fn (string $value): bool => $value !== ''));

		try {
			$removed = $this->service()->removeKeywords($context, $values);
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		WP_CLI::success(sprintf('Manual flag removed from %d candidates.', $removed));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function serpPlan(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_STRATEGY);

		try {
			$plan = $this->analysis()->plan($context, self::values($assocArgs));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied (requires osf_seo_manage_strategy and osf_seo_manage_serp_tracking).');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($plan->toArray());

			return;
		}

		self::printAnalysisPlan($plan);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function serpRun(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_STRATEGY);
		$json = ($assocArgs['format'] ?? 'table') === 'json';
		$values = self::values($assocArgs);

		try {
			$plan = $this->analysis()->plan($context, $values);
		} catch (AccessDenied) {
			WP_CLI::error('Access denied (requires osf_seo_manage_strategy and osf_seo_manage_serp_tracking).');
		}

		if (! $json) {
			self::printAnalysisPlan($plan);
		}

		if ($plan->skipReason === null && $plan->tasks() > 0 && ! isset($assocArgs['yes'])) {
			WP_CLI::confirm(sprintf('Submit %d paid SERP task(s) (TOP%d, Standard queue), estimated maximum cost %.4f USD?', $plan->tasks(), (int) $plan->context?->depth, $plan->estimatedCost()));
		}

		$result = $this->analysis()->start($context, $values, $plan->tasks(), $plan->estimatedCost(), SerpAnalysisService::TRIGGER_CLI);

		if ($result['status'] !== SerpStartResult::QUEUED || $result['run'] === null) {
			if ($json) {
				self::json(['status' => $result['status'], 'reason' => $result['reason']]);

				return;
			}

			$message = match ($result['status']) {
				SerpStartResult::NOTHING_TO_DO, SerpAnalysisPlan::NOTHING_TO_DO => 'Nothing to measure — fresh measurements are reused or checks are pending.',
				SerpAnalysisPlan::OVER_RUN_LIMIT => sprintf('The selection needs more new measurements than the per-run limit (%d). Select fewer keywords.', $plan->limit),
				SerpStartResult::PLAN_CHANGED => 'The plan changed (more tasks or a higher cost than shown). Run strategy:serp-plan again.',
				SerpStartResult::NOT_CONFIGURED => 'DataForSEO is not configured.',
				SerpStartResult::UNSUPPORTED_MARKET => 'The project market is not supported.',
				SerpStartResult::NO_KEYWORDS => 'No strategy candidates to analyse (run strategy:refresh first).',
				SerpStartResult::OVER_BUDGET => 'The analysis exceeds the shared DataForSEO budget (' . $result['reason'] . ').',
				SerpStartResult::PAUSED => 'Paid DataForSEO calls are paused after an account error.',
				SerpStartResult::LOCKED => 'Another SERP check is being planned. Try again in a moment.',
				default => $result['status'],
			};
			in_array($result['status'], [SerpStartResult::NOTHING_TO_DO, SerpAnalysisPlan::NOTHING_TO_DO], true) ? WP_CLI::success($message) : WP_CLI::error($message);

			return;
		}

		$run = $result['run'];

		if (isset($assocArgs['queue-only'])) {
			$json ? self::json($run->toArray()) : WP_CLI::success(sprintf('Analysis %s queued — tasks are submitted by the background step.', $run->publicId));

			return;
		}

		$serp = $this->plugin->get(SerpTrackingService::class);
		$report = $serp->execute($context, $run, 300.0);
		$wait = max(0, (int) ($assocArgs['wait'] ?? 0));
		$started = time();

		while ($wait > 0 && time() - $started < $wait) {
			if (! in_array($serp->run($context, $run->publicId)->status, SerpRun::ACTIVE, true)) {
				break;
			}

			sleep(15);
			$serp->collect(30.0);
		}

		$current = $serp->run($context, $run->publicId);

		if ($json) {
			self::json(['submitted' => $report, 'run' => $current->toArray()]);

			return;
		}

		WP_CLI::log(sprintf('Submitted %d task(s) in %d request(s); reported cost %.4f USD.', $report['tasks'] ?? 0, $report['posts'] ?? 0, $report['cost'] ?? 0));
		WP_CLI::log(sprintf('Analysis %s: %s — completed %d, failed %d.', $current->publicId, $current->status, $current->tasksCompleted, $current->tasksFailed));

		if (($report['stopped'] ?? null) !== null) {
			WP_CLI::warning('Stopped: ' . $report['stopped'] . '.');
		} elseif ($current->isActive()) {
			WP_CLI::success('Tasks submitted — results are collected in the background (or run: wp osf-seo serp:collect), then run strategy:refresh.');
		} else {
			WP_CLI::success('SERP analysis finished — run strategy:refresh to update the evidence.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function serpStatus(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$status = $this->analysis()->status($context, $context->can(Capabilities::MANAGE_SERP_TRACKING));

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($status);

			return;
		}

		WP_CLI::log(sprintf('Analysis keywords: %d (measured %d, last check %s). New measurements per run: up to %d.', $status['analysis_keywords'], $status['measured'], $status['last_checked_at'] ?? 'never', $status['limit_per_run']));

		if ($status['runs'] !== []) {
			Utils\format_items('table', array_map(static fn (array $run): array => [
				'id' => $run['id'] ?? '',
				'status' => $run['status'] ?? '',
				'planned' => $run['keywords_planned'] ?? '',
				'completed' => $run['tasks_completed'] ?? '',
				'created_at' => $run['created_at'] ?? '',
			], $status['runs']), ['id', 'status', 'planned', 'completed', 'created_at']);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function serp(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);

		try {
			$detail = $this->service()->serp($context, (string) $assocArgs['keyword']);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy candidate not found.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json(['serp' => $detail]);

			return;
		}

		if ($detail === null) {
			WP_CLI::log('No compatible SERP measurement in the project measurement context — the keyword qualifies for analysis (strategy:serp-plan).');

			return;
		}

		$profile = $detail['profile'];
		WP_CLI::log(sprintf('Measurement %s at %s (%s), %s TOP%d, tracking: %s.', $detail['snapshot'], $detail['checked_at'], $detail['freshness'], $detail['context']['device'], $detail['context']['depth'], $detail['tracking']));

		if ($profile !== null) {
			WP_CLI::log(sprintf(
				'Shape: %s (share %s, confidence %s). SERP intent signal: %s (confidence %s; provider intent is not changed). Features: %s.',
				$profile['shape'],
				$profile['shape_share'] ?? '—',
				$profile['shape_confidence'] ?? '—',
				$profile['intent_signal'],
				$profile['intent_confidence'] ?? '—',
				$profile['features'] === [] ? 'none' : implode(', ', $profile['features']),
			));
		}

		WP_CLI::log($detail['project'] === null ? 'Project SERP position: not used (measurement older than 30 days).' : sprintf('Project SERP position: %s.', $detail['project']['found'] ? '#' . $detail['project']['rank'] : 'not in TOP' . $detail['context']['depth']));
		Utils\format_items('table', array_map(static fn (array $row): array => [
			'rank' => $row['rank'],
			'host' => $row['host'] . ($row['project'] ? ' (project)' : ($row['competitor'] !== null ? ' (competitor)' : '')),
			'shape' => $row['shape'] ?? '—',
			'confidence' => $row['confidence'] ?? '—',
			'reason' => $row['reason'] ?? '—',
			'url' => $row['url'],
		], $detail['results']), ['rank', 'host', 'shape', 'confidence', 'reason', 'url']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function serpOverlap(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$values = self::values($assocArgs) ?? [];

		if (count($values) !== 2) {
			WP_CLI::error('Provide exactly two candidates: --keywords="first, second".');
		}

		try {
			$overlap = $this->service()->serpOverlap($context, $values[0], $values[1]);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy candidate not found.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($overlap);

			return;
		}

		WP_CLI::log(sprintf(
			'Overlap: %s%s — shared TOP10 URLs %d (counted %d, discounted %d: ubiquitous domains and home pages), shared domains %d. Reasons: %s.',
			$overlap['level'],
			$overlap['mergeable'] ? ' (auto-merge allowed)' : '',
			$overlap['shared_urls'],
			$overlap['counted_urls'],
			$overlap['discounted_urls'],
			$overlap['shared_domains'],
			$overlap['reasons'] === [] ? 'none' : implode(', ', $overlap['reasons']),
		));

		if ($overlap['urls'] !== []) {
			Utils\format_items('table', $overlap['urls'], ['url', 'rank_a', 'rank_b', 'counted']);
		}
	}

	private static function printAnalysisPlan(SerpAnalysisPlan $plan): void
	{
		$data = $plan->toArray();
		WP_CLI::log(sprintf(
			'Strategy SERP analysis (plan, no request): %s; %s, %s TOP%s; selection: %s.',
			$data['market'] ?? 'unsupported market',
			$data['context']['location_code'] ?? '—',
			$data['context']['device'] ?? '—',
			$data['context']['depth'] ?? '—',
			$data['selection'],
		));
		WP_CLI::log(sprintf(
			'Keywords: %d — reuse %d (fresh measurement, no cost), pending %d, new %d (limit %d per run), rejected %d.',
			$data['keywords'],
			$data['reuse'],
			$data['pending'],
			$data['measure'],
			$data['limit'],
			$data['rejected'],
		));
		WP_CLI::log(sprintf(
			'Estimated MAXIMUM cost: %.4f USD (%d task(s) × %.5f; the cost reported by the provider is binding). Remaining today %s, this month %s.%s',
			$data['estimated_max_cost'],
			$data['tasks'],
			$data['cost_per_task'],
			$data['remaining_today'] === null ? '—' : sprintf('%.4f', $data['remaining_today']),
			$data['remaining_month'] === null ? '—' : sprintf('%.4f', $data['remaining_month']),
			$data['blocked_by'] === null ? '' : ' Blocked by: ' . $data['blocked_by'] . '.',
		));

		if ($data['skip_reason'] !== null) {
			WP_CLI::warning('Nothing will be queued: ' . $data['skip_reason'] . '.');
		}

		if ($data['items'] !== []) {
			Utils\format_items('table', array_map(static fn (array $item): array => [
				'keyword' => $item['keyword'],
				'action' => $item['action'],
				'reason' => $item['reason'],
				'measurement' => $item['checked_at'] ?? '—',
				'freshness' => $item['freshness'] ?? '—',
				'tracking' => $item['tracking'] ?? '—',
			], $data['items']), ['keyword', 'action', 'reason', 'measurement', 'freshness', 'tracking']);
		}
	}

	/**
	 * @param array<string, string> $assocArgs
	 * @return list<string>|null
	 */
	private static function values(array $assocArgs): ?array
	{
		if (! isset($assocArgs['keywords'])) {
			return null;
		}

		return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/u', (string) $assocArgs['keywords']) ?: []), static fn (string $value): bool => $value !== ''));
	}

	private function analysis(): SerpAnalysisService
	{
		return $this->plugin->get(SerpAnalysisService::class);
	}

	/**
	 * @param array<string, int> $values
	 */
	private static function pairs(array $values): string
	{
		return implode(', ', array_map(static fn (string $key, int $value): string => $key . ' ' . $value, array_keys($values), $values));
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function json(array $data): void
	{
		WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function service(): StrategyService
	{
		return $this->plugin->get(StrategyService::class);
	}
}
