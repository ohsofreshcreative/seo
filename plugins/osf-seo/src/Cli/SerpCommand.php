<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Plugin;
use OsfSeo\Serp\PositionsFilters;
use OsfSeo\Serp\RankChange;
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpDevice;
use OsfSeo\Serp\SerpFrequency;
use OsfSeo\Serp\SerpNotFound;
use OsfSeo\Serp\SerpPlan;
use OsfSeo\Serp\SerpRun;
use OsfSeo\Serp\SerpStartResult;
use OsfSeo\Serp\SerpTrackingService;
use OsfSeo\Serp\TrackedKeywordRow;
use OsfSeo\Support\ValidationException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp osf-seo serp:*` — pozycje SERP (DataForSEO Google Organic, kolejka Standard). Płatne zlecenia wysyła wyłącznie
 * `serp:run` (po pokazaniu planu i potwierdzeniu); pozostałe komendy nie wywołują płatnego API (`serp:collect` odbiera
 * wyniki bezpłatnie). Wyjście nigdy nie zawiera danych logowania.
 */
final class SerpCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];
		$only = ['type' => 'assoc', 'name' => 'keywords', 'description' => 'Only these tracked keywords (ULIDs or keyword texts, comma separated).', 'optional' => true];

		WP_CLI::add_command('osf-seo serp:plan', [$command, 'plan'], [
			'shortdesc' => 'Plan a SERP check without any API request: keywords, tasks, depth, estimated maximum cost and budget.',
			'synopsis' => [$project, $only, $format],
		]);
		WP_CLI::add_command('osf-seo serp:run', [$command, 'run'], [
			'shortdesc' => 'Run a SERP check (paid DataForSEO Google Organic, Standard queue): shows the plan, asks for confirmation, submits tasks.',
			'synopsis' => [
				$project,
				$only,
				['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation.', 'optional' => true],
				['type' => 'flag', 'name' => 'queue-only', 'description' => 'Only queue the check — tasks are submitted by the background step.', 'optional' => true],
				['type' => 'assoc', 'name' => 'wait', 'description' => 'Wait up to N seconds and collect results (free task_get).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo serp:collect', [$command, 'collect'], [
			'shortdesc' => 'Collect ready SERP results (free tasks_ready / task_get) and store full organic snapshots.',
			'synopsis' => [['type' => 'assoc', 'name' => 'max-seconds', 'description' => 'Time budget (default 60).', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo serp:status', [$command, 'status'], [
			'shortdesc' => 'SERP tracking status: settings, counters, active check, costs and budget (no API call).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'run', 'description' => 'Check (run) public ID.', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo serp:list', [$command, 'list'], [
			'shortdesc' => 'List tracked keywords with SERP position (last check) and change; GSC average position separately (no API call).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'band', 'description' => 'all, top3, top10, top20, top50, found, out, unchecked.', 'optional' => true, 'default' => 'all'],
				['type' => 'assoc', 'name' => 'change', 'description' => 'all, up, down, entered, left, new, out, top10_entered, top10_left.', 'optional' => true, 'default' => 'all'],
				['type' => 'assoc', 'name' => 'sort', 'description' => 'rank, change, keyword, volume, difficulty, checked, added.', 'optional' => true, 'default' => 'rank'],
				['type' => 'assoc', 'name' => 'search', 'description' => 'Keyword contains.', 'optional' => true],
				['type' => 'assoc', 'name' => 'page', 'description' => 'Page (50 per page).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo serp:snapshot', [$command, 'snapshot'], [
			'shortdesc' => 'Show the stored SERP snapshot of a tracked keyword (all organic results, all domains; no API call).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'keyword', 'description' => 'Tracked keyword (ULID or text).', 'optional' => false],
				['type' => 'assoc', 'name' => 'snapshot', 'description' => 'Snapshot public ID (default: latest).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo serp:track', [$command, 'track'], [
			'shortdesc' => 'Add keywords to SERP tracking (no API request).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'keywords', 'description' => 'Keywords (comma or newline separated); with --from=discovery or --from=gap: candidate / gap ULIDs.', 'optional' => false],
				['type' => 'assoc', 'name' => 'from', 'description' => 'Source.', 'optional' => true, 'default' => 'manual', 'options' => ['manual', 'gsc', 'discovery', 'gap']],
			],
		]);
		WP_CLI::add_command('osf-seo serp:untrack', [$command, 'untrack'], [
			'shortdesc' => 'Stop tracking keywords (history is kept).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'keywords', 'description' => 'Tracked keywords (ULIDs or texts, comma separated).', 'optional' => false]],
		]);
		WP_CLI::add_command('osf-seo serp:settings', [$command, 'settings'], [
			'shortdesc' => 'Show or change SERP tracking settings with a local cost preview (enabling paid tracking asks for confirmation).',
			'synopsis' => [
				$project,
				['type' => 'flag', 'name' => 'enable', 'description' => 'Enable scheduled paid checks.', 'optional' => true],
				['type' => 'flag', 'name' => 'disable', 'description' => 'Disable scheduled checks.', 'optional' => true],
				['type' => 'assoc', 'name' => 'frequency', 'description' => 'daily, every_3_days, weekly.', 'optional' => true],
				['type' => 'assoc', 'name' => 'device', 'description' => 'desktop, mobile.', 'optional' => true],
				['type' => 'assoc', 'name' => 'depth', 'description' => '10, 20, 50, 100.', 'optional' => true],
				['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation.', 'optional' => true],
				$format,
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function plan(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_SERP_TRACKING);
		$plan = $this->service()->plan($context, $this->only($context, $assocArgs));

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
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_SERP_TRACKING);
		$service = $this->service();
		$json = ($assocArgs['format'] ?? 'table') === 'json';
		$only = $this->only($context, $assocArgs);
		$plan = $service->plan($context, $only);

		if (! $json) {
			self::printPlan($plan);
		}

		if ($plan->skipReason === null && $plan->tasks() > 0 && ! isset($assocArgs['yes'])) {
			WP_CLI::confirm(sprintf('Submit %d paid SERP task(s) (TOP%d, Standard queue), estimated maximum cost %.4f USD?', $plan->tasks(), (int) $plan->context?->depth, $plan->estimatedCost()));
		}

		$result = $service->start($context, $plan->tasks(), $plan->estimatedCost(), $only, SerpTrackingService::TRIGGER_CLI);

		if (! $result->queued()) {
			$json ? self::json(['status' => $result->status, 'reason' => $result->reason]) : null;
			$message = match ($result->status) {
				SerpStartResult::NOTHING_TO_DO => 'Nothing to check — all keywords were checked recently.',
				SerpStartResult::PLAN_CHANGED => 'The plan changed (more tasks or a higher cost than shown). Run serp:plan again.',
				SerpStartResult::NOT_CONFIGURED => 'DataForSEO is not configured.',
				SerpStartResult::UNSUPPORTED_MARKET => 'The project market is not supported.',
				SerpStartResult::NO_KEYWORDS => 'The project has no tracked keywords.',
				SerpStartResult::OVER_BUDGET => 'The check exceeds the shared DataForSEO budget (' . $result->reason . ').',
				SerpStartResult::PAUSED => 'Paid DataForSEO calls are paused after an account error.',
				SerpStartResult::LOCKED => 'Another check is being planned. Try again in a moment.',
				default => $result->status,
			};
			$result->status === SerpStartResult::NOTHING_TO_DO ? ($json ? null : WP_CLI::success($message)) : WP_CLI::error($message);

			return;
		}

		$run = $result->run ?? throw new \LogicException('Queued result without run.');

		if (isset($assocArgs['queue-only'])) {
			$json ? self::json($run->toArray()) : WP_CLI::success(sprintf('Check %s queued — tasks are submitted by the background step.', $run->publicId));

			return;
		}

		$report = $service->execute($context, $run, 300.0);
		$wait = max(0, (int) ($assocArgs['wait'] ?? 0));
		$started = time();

		while ($wait > 0 && time() - $started < $wait) {
			$current = $service->run($context, $run->publicId);

			if (! in_array($current->status, SerpRun::ACTIVE, true)) {
				break;
			}

			sleep(15);
			$service->collect(30.0);
		}

		$current = $service->run($context, $run->publicId);

		if ($json) {
			self::json(['submitted' => $report, 'run' => $current->toArray()]);

			return;
		}

		WP_CLI::log(sprintf('Submitted %d task(s) in %d request(s); reported cost %.4f USD.', $report['tasks'] ?? 0, $report['posts'] ?? 0, $report['cost'] ?? 0));
		WP_CLI::log(sprintf('Check %s: %s — completed %d, failed %d.', $current->publicId, $current->status, $current->tasksCompleted, $current->tasksFailed));

		if (($report['stopped'] ?? null) !== null) {
			WP_CLI::warning('Stopped: ' . $report['stopped'] . '.');
		} elseif ($current->isActive()) {
			WP_CLI::success('Tasks submitted — results are collected in the background (or run: wp osf-seo serp:collect).');
		} else {
			WP_CLI::success('SERP check finished.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function collect(array $args, array $assocArgs): void
	{
		$report = $this->service()->collect(max(5.0, (float) ($assocArgs['max-seconds'] ?? 60)));

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($report);

			return;
		}

		WP_CLI::success(sprintf(
			'Checked %d task(s): completed %d (%d results stored), pending %d, failed %d, expired %d; ready list %d, recovered %d, interrupted %d.',
			$report['checked'],
			$report['completed'],
			$report['results'],
			$report['pending'],
			$report['failed'],
			$report['expired'],
			$report['ready'],
			$report['recovered'],
			$report['interrupted'],
		));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function status(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$withCost = $context->can(Capabilities::MANAGE_SERP_TRACKING);
		$service = $this->service();

		if (isset($assocArgs['run'])) {
			try {
				$run = $service->run($context, (string) $assocArgs['run']);
			} catch (SerpNotFound) {
				WP_CLI::error('Check not found.');
			}

			(($assocArgs['format'] ?? 'table') === 'json') ? self::json($run->toArray($withCost)) : Utils\format_items('table', [self::runRow($run, $withCost)], array_keys(self::runRow($run, $withCost)));

			return;
		}

		$status = $service->status($context, $withCost);
		$runs = $service->recentRuns($context, 5);
		$status['recent_checks'] = array_map(static fn (SerpRun $run): array => $run->toArray($withCost), $runs);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($status);

			return;
		}

		$settings = $status['settings'];
		WP_CLI::log(sprintf('Market: %s. Provider configured: %s.', $status['market'] ?? 'unsupported', $status['configured'] ? 'yes' : 'no'));
		WP_CLI::log(sprintf('Tracking: %s, %s, %s, TOP%d; next check: %s.', $settings['enabled'] ? 'enabled' : 'disabled', $settings['frequency'], $settings['device'], $settings['depth'], $settings['next_run_at'] ?? '—'));

		if ($settings['last_skip_reason'] !== null) {
			WP_CLI::log(sprintf('Last skipped check: %s at %s.', $settings['last_skip_reason'], $settings['last_skip_at']));
		}

		$summary = $status['summary'];
		WP_CLI::log(sprintf(
			'Tracked keywords: %d (soft limit %d); last check: %s; improved %d, declined %d, entered TOP10 %d, left TOP10 %d, outside checked depth %d, never checked %d.',
			$summary['tracked'],
			$status['max_keywords'],
			$summary['last_checked'] ?? '—',
			$summary['improved'],
			$summary['declined'],
			$summary['top10_entered'],
			$summary['top10_left'],
			$summary['outside'],
			$summary['unchecked'],
		));

		if ($withCost) {
			WP_CLI::log(sprintf('Estimated maximum cost: %.5f USD per keyword, %.4f USD per full check, %.2f USD per month.', $status['cost_per_task'], $status['full_measurement_cost'], $status['monthly_cost']));
		}

		if ($runs !== []) {
			$rows = array_map(static fn (SerpRun $run): array => self::runRow($run, $withCost), $runs);
			Utils\format_items('table', $rows, array_keys($rows[0]));
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function list(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$filters = PositionsFilters::fromInput([
			'band' => $assocArgs['band'] ?? 'all',
			'change' => $assocArgs['change'] ?? 'all',
			'sort' => $assocArgs['sort'] ?? 'rank',
			'q' => $assocArgs['search'] ?? '',
			'page' => $assocArgs['page'] ?? '1',
		]);
		$page = $this->service()->positions($context, $filters);
		$rows = array_map(static fn ($row): array => [
			'id' => $row->publicId,
			'keyword' => $row->keyword,
			'serp_position' => self::rank($row),
			'change' => self::change($row),
			'url' => $row->url ?? '',
			'gsc_avg_position' => $row->gscPosition ?? '',
			'volume' => $row->searchVolume ?? '',
			'kd' => $row->difficulty ?? '',
			'last_check' => $row->lastCheckedAt ?? '',
		], $page['rows']);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json(['total' => $page['total'], 'page' => $filters->page, 'keywords' => array_map(static fn ($row): array => $row->toArray(), $page['rows'])]);

			return;
		}

		$rows === [] ? WP_CLI::log('No tracked keywords for these filters.') : Utils\format_items('table', $rows, array_keys($rows[0]));
		WP_CLI::log(sprintf('Total: %d. SERP position = observed organic rank (rank_group); GSC average position is a separate metric.', $page['total']));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function snapshot(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$service = $this->service();
		$id = $service->trackedId($context, (string) $assocArgs['keyword']);

		try {
			$detail = $id === null ? throw new SerpNotFound() : $service->keyword($context, $id, $assocArgs['snapshot'] ?? null);
		} catch (SerpNotFound) {
			WP_CLI::error('Tracked keyword or snapshot not found.');
		}

		$rows = array_map(static fn (array $result): array => [
			'rank' => (int) $result['result_type'] === 2 ? 'featured' : $result['rank_group'],
			'rank_absolute' => $result['rank_absolute'],
			'domain' => $result['host'],
			'url' => $result['url'],
			'title' => (string) $result['title'],
			'marker' => $result['is_project'] ? 'PROJECT' : ($result['competitor'] !== null ? 'competitor: ' . $result['competitor'] : ''),
		], $detail['results']);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json(['keyword' => $detail['row']->keyword, 'snapshot' => $detail['snapshot'], 'results' => $rows]);

			return;
		}

		if ($detail['snapshot'] === null) {
			WP_CLI::log('No completed check for this keyword yet.');

			return;
		}

		WP_CLI::log(sprintf('"%s" checked at %s UTC, TOP%d, %d organic results.', $detail['row']->keyword, $detail['snapshot']['checked_at'], $detail['snapshot']['requested_depth'], $detail['snapshot']['organic_count']));
		Utils\format_items('table', $rows, ['rank', 'rank_absolute', 'domain', 'url', 'title', 'marker']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function track(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_SERP_TRACKING);
		$source = (string) ($assocArgs['from'] ?? 'manual');
		$input = in_array($source, ['discovery', 'gap'], true) ? array_map('trim', explode(',', (string) $assocArgs['keywords'])) : (string) $assocArgs['keywords'];

		try {
			$result = $this->service()->addKeywords($context, $source, $input);
		} catch (ValidationException $exception) {
			WP_CLI::error(implode(' ', $exception->errors()));
		}

		foreach ($result['rejected'] as $rejected) {
			WP_CLI::warning(sprintf('Rejected "%s": %s.', $rejected['keyword'], $rejected['reason']));
		}

		WP_CLI::success(sprintf('Added %d, restored %d, already tracked %d. No API request was made.', $result['added'], $result['restored'], $result['existing']));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function untrack(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_SERP_TRACKING);
		$service = $this->service();
		$ids = array_values(array_filter(array_map(static fn (string $value): ?string => $service->trackedId($context, trim($value)), explode(',', (string) $assocArgs['keywords']))));
		WP_CLI::success(sprintf('Stopped tracking %d keyword(s); history is kept.', $service->removeKeywords($context, $ids)));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function settings(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_SERP_TRACKING);
		$service = $this->service();
		$current = $service->settings($context);
		$enabled = isset($assocArgs['enable']) ? true : (isset($assocArgs['disable']) ? false : $current->enabled);
		$frequency = SerpFrequency::tryFrom((string) ($assocArgs['frequency'] ?? '')) ?? $current->frequency;
		$device = SerpDevice::tryFrom((string) ($assocArgs['device'] ?? '')) ?? $current->device;
		$depth = isset($assocArgs['depth']) ? (int) $assocArgs['depth'] : $current->depth;
		$preview = $service->preview($context, $frequency, $device, $depth);
		$changing = isset($assocArgs['enable']) || isset($assocArgs['disable']) || isset($assocArgs['frequency']) || isset($assocArgs['device']) || isset($assocArgs['depth']);

		if (! $changing) {
			(($assocArgs['format'] ?? 'table') === 'json') ? self::json(['settings' => $current->toArray(), 'preview' => $preview->toArray()]) : self::printPreview($preview, $current->enabled);

			return;
		}

		self::printPreview($preview, $enabled);

		if ($enabled && ! $current->enabled && ! isset($assocArgs['yes'])) {
			WP_CLI::confirm(sprintf('Enable paid scheduled SERP checks (%s, estimated maximum %.2f USD per month)?', $frequency->value, $preview->monthlyCost()));
		}

		try {
			$settings = $service->saveSettings($context, ['enabled' => $enabled ? '1' : '0', 'frequency' => $frequency->value, 'device' => $device->value, 'depth' => $depth, 'confirm' => '1']);
		} catch (ValidationException $exception) {
			WP_CLI::error(implode(' ', $exception->errors()));
		}

		WP_CLI::success(sprintf('Settings saved: %s, %s, %s, TOP%d. Next check: %s.', $settings->enabled ? 'enabled' : 'disabled', $settings->frequency->value, $settings->device->value, $settings->depth, $settings->nextRunAt ?? '—'));
	}

	private static function printPlan(SerpPlan $plan): void
	{
		if ($plan->skipReason !== null) {
			WP_CLI::log('Nothing to submit: ' . $plan->skipReason . '.');
		}

		WP_CLI::log(sprintf('Market: %s; device: %s; depth: TOP%d (Google Organic, Standard queue).', $plan->market?->label() ?? 'unsupported', $plan->context?->device->value ?? '—', (int) $plan->context?->depth));
		WP_CLI::log(sprintf('Keywords: %d of %d tracked (skipped as checked recently: %d); tasks: %d in %d request(s).', $plan->tasks(), $plan->tracked, $plan->recent, $plan->tasks(), $plan->posts()));
		WP_CLI::log(sprintf('Estimated maximum cost: %.4f USD (%.5f USD per keyword). Provider-reported cost is authoritative after execution.', $plan->estimatedCost(), $plan->costPerTask));
		WP_CLI::log(sprintf('Shared DataForSEO budget remaining: %.4f USD today, %.4f USD this month.', $plan->remainingToday(), $plan->remainingMonth()));

		if ($plan->blockedBy() !== null) {
			WP_CLI::warning('The check exceeds the shared budget: ' . $plan->blockedBy() . '.');
		}
	}

	private static function printPreview(SerpPlan $preview, bool $enabled): void
	{
		WP_CLI::log(sprintf('Tracked keywords: %d; device: %s; depth: TOP%d; frequency: %s; tracking: %s.', $preview->tracked, $preview->context?->device->value ?? '—', (int) $preview->context?->depth, $preview->frequency->value, $enabled ? 'enabled' : 'disabled'));
		WP_CLI::log(sprintf('Estimated maximum cost: %.4f USD per check, %.2f USD per month. Remaining budget: %.4f USD today, %.4f USD this month.', $preview->fullMeasurementCost(), $preview->monthlyCost(), $preview->remainingToday(), $preview->remainingMonth()));
	}

	/**
	 * @param array<string, string> $assocArgs
	 * @return list<string>|null
	 */
	private function only(\OsfSeo\Auth\ProjectContext $context, array $assocArgs): ?array
	{
		if (! isset($assocArgs['keywords'])) {
			return null;
		}

		$service = $this->service();

		return array_values(array_filter(array_map(static fn (string $value): ?string => $service->trackedId($context, trim($value)), explode(',', (string) $assocArgs['keywords']))));
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function json(array $data): void
	{
		WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function service(): SerpTrackingService
	{
		return $this->plugin->get(SerpTrackingService::class);
	}

	/** Pozycja w wyjściu CLI (po angielsku): „7”, „out of TOP100”, „—” (jeszcze nie sprawdzono). */
	private static function rank(TrackedKeywordRow $row): string
	{
		return match (true) {
			$row->found === null => '—',
			$row->rank === null => 'out of TOP' . ($row->depth ?? SerpConfig::DEFAULT_DEPTH),
			default => (string) $row->rank,
		};
	}

	private static function change(TrackedKeywordRow $row): string
	{
		return match ($row->changeType) {
			null => '—',
			RankChange::UP => '+' . $row->changeValue,
			RankChange::DOWN => (string) $row->changeValue,
			RankChange::SAME => '0',
			default => $row->changeType,
		} . ($row->top10Change === null ? '' : ' (TOP10 ' . $row->top10Change . ')');
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function runRow(SerpRun $run, bool $withCost): array
	{
		$row = $run->toArray($withCost);
		unset($row['status_label']);

		return $row;
	}
}
