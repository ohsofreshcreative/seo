<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\Capabilities;
use OsfSeo\DataForSeo\DataForSeoProvider;
use OsfSeo\Market\KeywordMetricsProvider;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Market\MarketSyncResult;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\PlannedTask;
use OsfSeo\Market\ProviderException;
use OsfSeo\Market\SyncPlan;
use OsfSeo\Plugin;
use WP_CLI;

/**
 * `wp osf-seo dataforseo:*` — dane rynkowe fraz (DataForSEO). Wyjście nigdy nie zawiera danych logowania.
 * Płatne żądania wysyła wyłącznie `dataforseo:sync` bez `--dry-run` (oraz `dataforseo:run` dla projektów po pierwszej
 * jawnej synchronizacji); `dataforseo:status`, `dataforseo:keyword` i `--dry-run` nie wywołują API.
 */
final class MarketCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];

		WP_CLI::add_command('osf-seo dataforseo:status', [$command, 'status'], [
			'shortdesc' => 'DataForSEO market data status without secrets: configuration, market, metrics, tasks, cost and safety budget.',
			'synopsis' => [
				['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID); without it — installation-wide status.', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo dataforseo:sync', [$command, 'sync'], [
			'shortdesc' => 'Enrich project keywords with DataForSEO market data (paid API; use --dry-run first).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'limit', 'description' => 'Maximum keywords in this run (default: OSF_SEO_DATAFORSEO_SYNC_LIMIT, 1000).', 'optional' => true],
				['type' => 'flag', 'name' => 'dry-run', 'description' => 'Show the plan (keywords, tasks, endpoints, estimated cost, budget) without calling the API.', 'optional' => true],
				['type' => 'flag', 'name' => 'force', 'description' => 'Refresh metrics even if still fresh (budgets and task limits still apply).', 'optional' => true],
				['type' => 'assoc', 'name' => 'wait', 'description' => 'Seconds to wait for Standard search volume results (default 0 — collected in the background).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo dataforseo:keyword', [$command, 'keyword'], [
			'shortdesc' => 'Show stored market metrics of a keyword in the project market (no API call).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'keyword', 'description' => 'Keyword text.', 'optional' => false],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo dataforseo:run', [$command, 'run'], [
			'shortdesc' => 'Run the background step once: collect Standard results and refresh stale metrics of enabled projects.',
			'synopsis' => [
				['type' => 'flag', 'name' => 'collect-only', 'description' => 'Only collect results of already submitted tasks (free).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo dataforseo:locations', [$command, 'locations'], [
			'shortdesc' => 'List DataForSEO Labs locations and languages (free endpoint) to verify market codes.',
			'synopsis' => [
				['type' => 'assoc', 'name' => 'country', 'description' => 'Filter by ISO code or name, e.g. PL or Poland.', 'optional' => true],
				$format,
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function status(array $args, array $assocArgs): void
	{
		$context = isset($assocArgs['project']) ? CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS) : null;
		$status = $this->service()->status($context);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			WP_CLI::line((string) wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			return;
		}

		$metrics = $status['metrics'];
		$state = $status['state'];
		$rows = [
			['check' => 'provider', 'value' => $status['provider']],
			['check' => 'configured', 'value' => $status['configured'] ? 'yes' : 'no (missing: ' . implode(', ', $status['missing']) . ')'],
			['check' => 'market', 'value' => $status['market'] === null ? ($context === null ? '(use --project)' : 'unsupported') : sprintf('%s (location_code %d, language_code %s)', $status['market'], $status['location_code'], $status['language_code'])],
			['check' => 'metrics_total', 'value' => $metrics === null ? '-' : (string) $metrics['total']],
			['check' => 'volume_fresh / stale / pending', 'value' => $metrics === null ? '-' : sprintf('%d / %d / %d', $metrics['volume_fresh'], $metrics['volume_stale'], $metrics['volume_pending'])],
			['check' => 'difficulty_fresh / stale', 'value' => $metrics === null ? '-' : sprintf('%d / %d', $metrics['difficulty_fresh'], $metrics['difficulty_stale'])],
			['check' => 'pending_tasks', 'value' => (string) $status['pending_tasks']],
			['check' => 'tasks_24h', 'value' => sprintf('%d (failed %d)', $status['usage_24h']['tasks'], $status['usage_24h']['failed'])],
			['check' => 'cost_24h_usd', 'value' => sprintf('%.4f (reported %.4f)', $status['usage_24h']['cost'], $status['usage_24h']['reported_cost'])],
			['check' => 'cost_30d_usd', 'value' => sprintf('%.4f (reported %.4f)', $status['usage_30d']['cost'], $status['usage_30d']['reported_cost'])],
			['check' => 'budget', 'value' => sprintf('%s — today %.4f / %.2f, month %.4f / %.2f, max %d tasks per run', $status['budget']['status'], $status['budget']['spent_today'], $status['budget']['daily_limit'], $status['budget']['spent_month'], $status['budget']['monthly_limit'], $status['budget']['max_tasks_per_run'])],
			['check' => 'auto_refresh', 'value' => ($status['auto_refresh'] ? 'on' : 'off') . ($status['paused'] === null ? '' : sprintf(' (paused until %s UTC: %s)', $status['paused']['until'], $status['paused']['reason']))],
			['check' => 'last_success', 'value' => (string) ($state['last_success_at'] ?? '-')],
			['check' => 'last_error', 'value' => ($state['last_error'] ?? null) === null ? '-' : $state['last_error'] . ' at ' . $state['last_error_at']],
		];

		if ($context !== null) {
			$rows[] = ['check' => 'enabled_for_auto_refresh', 'value' => $state['enabled_at'] === null ? 'no (run dataforseo:sync once)' : 'since ' . $state['enabled_at']];
		}

		\WP_CLI\Utils\format_items('table', $rows, ['check', 'value']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function sync(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_MARKET_DATA);
		$service = $this->service();
		$limit = isset($assocArgs['limit']) ? max(1, (int) $assocArgs['limit']) : null;
		$force = isset($assocArgs['force']);
		$json = ($assocArgs['format'] ?? 'table') === 'json';

		if (isset($assocArgs['dry-run'])) {
			$plan = $service->plan($context, $limit, $force);
			$json ? WP_CLI::line((string) wp_json_encode(['dry_run' => true, 'api_requests' => 0] + $plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : $this->printPlan($plan, true);

			return;
		}

		$result = $service->sync($context, MarketSyncService::TRIGGER_CLI, $limit, $force);
		$collected = null;
		$wait = min(600, max(0, (int) ($assocArgs['wait'] ?? 0)));

		if ($wait > 0 && $result->volumeTasks > 0) {
			$deadline = time() + $wait;

			do {
				sleep(min(30, max(1, $deadline - time())));
				$collected = $service->collect();
			} while ($collected['pending'] > 0 && time() < $deadline);
		}

		if ($json) {
			WP_CLI::line((string) wp_json_encode($result->toArray() + ['collected' => $collected, 'plan' => $result->plan?->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		} else {
			if ($result->plan !== null) {
				$this->printPlan($result->plan, false);
			}

			\WP_CLI\Utils\format_items('table', array_map(
				static fn (string $key, mixed $value): array => ['result' => $key, 'value' => is_scalar($value) || $value === null ? (string) $value : (string) wp_json_encode($value)],
				array_keys($result->toArray() + ['collected' => $collected]),
				array_values($result->toArray() + ['collected' => $collected]),
			), ['result', 'value']);
		}

		if (in_array($result->status, [MarketSyncResult::FAILED, MarketSyncResult::NOT_CONFIGURED], true)) {
			WP_CLI::halt(1);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function keyword(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$market = $this->service()->market($context);

		if ($market === null) {
			WP_CLI::error('The project market is not supported by the market data provider.');
		}

		$text = (string) ($assocArgs['keyword'] ?? '');
		$repository = $this->plugin->get(MarketMetricsRepository::class);
		$metrics = $repository->findByKeys($market, [MarketKeyword::key($text)])[bin2hex(MarketKeyword::key($text))] ?? null;
		$data = [
			'keyword' => $text,
			'normalized' => MarketKeyword::normalize($text),
			'market' => $market->label(),
			'accepted_by_provider' => $this->plugin->get(KeywordMetricsProvider::class)->acceptsKeyword(MarketKeyword::normalize($text)),
			'search_volume' => $metrics?->searchVolume,
			'keyword_difficulty' => $metrics?->keywordDifficulty,
			'cpc_usd' => $metrics?->cpc,
			'ads_competition' => $metrics?->competitionLevel,
			'ads_competition_index' => $metrics?->competitionIndex,
			'volume_fetched_at' => $metrics?->volumeFetchedAt,
			'volume_stale_after' => $metrics?->volumeStaleAfter,
			'difficulty_fetched_at' => $metrics?->difficultyFetchedAt,
			'monthly' => $metrics === null ? [] : ($repository->history([$metrics->id])[$metrics->id] ?? []),
		];

		if (($assocArgs['format'] ?? 'table') === 'json') {
			WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			return;
		}

		if ($metrics === null) {
			WP_CLI::warning('No stored market metrics for this keyword in the project market.');
		}

		$monthly = $data['monthly'];
		$data['monthly'] = implode(', ', array_map(static fn (array $m): string => substr($m['month'], 0, 7) . ': ' . ($m['search_volume'] ?? '-'), $monthly));
		$data['accepted_by_provider'] = $data['accepted_by_provider'] ? 'yes' : 'no';
		\WP_CLI\Utils\format_items('table', array_map(
			static fn (string $key, mixed $value): array => ['field' => $key, 'value' => $value === null ? '-' : (string) $value],
			array_keys($data),
			array_values($data),
		), ['field', 'value']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function run(array $args, array $assocArgs): void
	{
		$service = $this->service();
		$report = isset($assocArgs['collect-only']) ? ['collected' => $service->collect()] : $service->runBackground(120.0, true);
		WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function locations(array $args, array $assocArgs): void
	{
		$provider = $this->plugin->get(KeywordMetricsProvider::class);

		if (! $provider instanceof DataForSeoProvider || ! $provider->isConfigured()) {
			WP_CLI::error('DataForSEO is not configured (missing: ' . implode(', ', $provider->configurationProblems()) . ').');
		}

		try {
			$locations = $provider->locations();
		} catch (ProviderException $exception) {
			WP_CLI::error('DataForSEO request failed: ' . $exception->category()->value . ' — ' . $exception->getMessage());
		}

		$filter = strtolower(trim((string) ($assocArgs['country'] ?? '')));
		$rows = [];

		foreach ($locations as $location) {
			if ($filter !== '' && strtolower((string) $location['country_iso_code']) !== $filter && ! str_contains(strtolower($location['location_name']), $filter)) {
				continue;
			}

			$rows[] = [
				'location_code' => $location['location_code'],
				'location_name' => $location['location_name'],
				'country_iso_code' => $location['country_iso_code'] ?? '',
				'languages' => implode(', ', array_map(static fn (array $l): string => $l['language_name'] . ' (' . $l['language_code'] . ')', $location['languages'])),
			];
		}

		\WP_CLI\Utils\format_items($assocArgs['format'] ?? 'table', $rows, ['location_code', 'location_name', 'country_iso_code', 'languages']);
	}

	private function printPlan(SyncPlan $plan, bool $dryRun): void
	{
		$data = $plan->toArray();

		if ($plan->skipReason !== null) {
			WP_CLI::warning('Nothing to plan: ' . $plan->skipReason . '.');

			return;
		}

		WP_CLI::line(sprintf(
			'%sMarket: %s (location_code %d, language_code %s). Window: %s. Keywords selected: %d (sent in this run: %d; rejected by provider rules: %d; merged variants: %d).',
			$dryRun ? 'DRY RUN — no API requests. ' : '',
			$data['market'],
			$data['location_code'],
			$data['language_code'],
			$data['window'] === null ? '-' : implode(' – ', $data['window']),
			$data['keywords'],
			$data['keywords_to_send'],
			$data['rejected_by_provider_rules'],
			$data['merged_variants'],
		));

		\WP_CLI\Utils\format_items('table', array_map(static fn (PlannedTask $task): array => [
			'type' => $task->type,
			'endpoint' => $task->endpoint->path,
			'mode' => $task->endpoint->mode,
			'keywords' => count($task->keywords),
			'estimated_cost_usd' => sprintf('%.4f', $task->estimatedCost),
			'status' => $task->isAllowed() ? 'will be sent' : 'blocked: ' . $task->blockedBy,
		], $plan->tasks), ['type', 'endpoint', 'mode', 'keywords', 'estimated_cost_usd', 'status']);

		WP_CLI::line(sprintf(
			'Paid tasks in this run: %d of %d. Estimated cost: %.4f USD. Budget: today %.4f / %.2f, month %.4f / %.2f USD.',
			$data['tasks_allowed'],
			$data['tasks_total'],
			$data['estimated_cost'],
			$data['budget']['spent_today'],
			$data['budget']['daily_limit'],
			$data['budget']['spent_month'],
			$data['budget']['monthly_limit'],
		));
	}

	private function service(): MarketSyncService
	{
		return $this->plugin->get(MarketSyncService::class);
	}
}
