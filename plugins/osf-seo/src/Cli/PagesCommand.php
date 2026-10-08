<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\PageIntelligence\PageIntelligenceService;
use OsfSeo\PageIntelligence\PageNotFound;
use OsfSeo\PageIntelligence\PageRefused;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\Plugin;
use OsfSeo\Strategy\StrategyNotFound;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp osf-seo pages:*` — Page Intelligence (STEP 17, faza B): stan, plan, diagnostyka adresu (bez HTTP), pobranie wybranych stron (jawnie,
 * z potwierdzeniem), lista, szczegóły i snapshoty, usunięcie, retencja. Wyjście po angielsku; `--format=json` wyłącznie JSON.
 */
final class PagesCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];
		$selection = [
			['type' => 'assoc', 'name' => 'page-url', 'description' => 'One URL (project page, competitor domain of the project, or an organic result of a stored SERP measurement). Not --url: that is a global WP-CLI parameter.', 'optional' => true],
			['type' => 'assoc', 'name' => 'urls', 'description' => 'Several URLs separated by spaces or new lines (max OSF_SEO_PAGES_MAX_URLS).', 'optional' => true],
			['type' => 'assoc', 'name' => 'topic', 'description' => 'Strategy topic (ULID or keyword) — its target page.', 'optional' => true],
			['type' => 'assoc', 'name' => 'keyword', 'description' => 'Strategy candidate keyword with a stored SERP measurement (use with --ranks).', 'optional' => true],
			['type' => 'assoc', 'name' => 'ranks', 'description' => 'Organic positions of the stored SERP (TOP20), comma separated.', 'optional' => true],
			['type' => 'flag', 'name' => 'force', 'description' => 'Fetch even if the snapshot is fresh.', 'optional' => true],
		];

		WP_CLI::add_command('osf-seo pages:status', [$command, 'status'], [
			'shortdesc' => 'Page Intelligence status of a project: configuration, transport, counts (no HTTP).',
			'synopsis' => [$project, $format],
		]);
		WP_CLI::add_command('osf-seo pages:plan', [$command, 'plan'], [
			'shortdesc' => 'Plan a page fetch: scope, kind, cache state, host limits (no HTTP, no DNS).',
			'synopsis' => [$project, ...$selection, $format],
		]);
		WP_CLI::add_command('osf-seo pages:fetch', [$command, 'fetch'], [
			'shortdesc' => 'Fetch selected public pages (explicit action): robots.txt, host limits (pages of one host one after another, waiting at most 120 s in total), pinned-IP transport, extraction, snapshot only on content change.',
			'synopsis' => [$project, ...$selection, ['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation.', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo pages:check-url', [$command, 'checkUrl'], [
			'shortdesc' => 'Security diagnostics of a URL: scope, syntax, DNS and IP policy (DNS lookup only, no HTTP).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'page-url', 'description' => 'URL (not --url: global WP-CLI parameter).', 'optional' => false], $format],
		]);
		WP_CLI::add_command('osf-seo pages:list', [$command, 'list'], [
			'shortdesc' => 'Pages of a project with cache state and latest snapshot summary.',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'kind', 'description' => 'project or competitor.', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo pages:show', [$command, 'show'], [
			'shortdesc' => 'Page detail (JSON): latest snapshot with metadata, headings, content, links, technical signals and quality; snapshots; fetch attempts; SERP links.',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'page', 'description' => 'Page ID (ULID).', 'optional' => false]],
		]);
		WP_CLI::add_command('osf-seo pages:snapshot', [$command, 'snapshot'], [
			'shortdesc' => 'One stored snapshot (JSON).',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'snapshot', 'description' => 'Snapshot ID (ULID).', 'optional' => false]],
		]);
		WP_CLI::add_command('osf-seo pages:delete', [$command, 'delete'], [
			'shortdesc' => 'Delete a page with its snapshots and fetch history.',
			'synopsis' => [$project, ['type' => 'assoc', 'name' => 'page', 'description' => 'Page ID (ULID).', 'optional' => false], ['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation.', 'optional' => true]],
		]);
		WP_CLI::add_command('osf-seo pages:purge', [$command, 'purge'], [
			'shortdesc' => 'Retention: delete snapshots and fetch records older than OSF_SEO_PAGES_RETENTION_DAYS (no HTTP).',
			'synopsis' => [$format],
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

		$config = $status['config'];
		WP_CLI::log(sprintf('Transport: %s. Project pages: %s, competitor pages: %s.', $status['transport'], $config['project_enabled'] ? 'enabled' : 'disabled', $config['competitors_enabled'] ? 'enabled' : 'disabled'));
		WP_CLI::log(sprintf('Limits: %d URLs per request, %d s timeout, %d bytes, %d redirects, %d s between requests to a host, %d requests per host per day. TTL %d h, retention %d days.', $config['max_urls'], $config['timeout'], $config['max_bytes'], $config['max_redirects'], $config['domain_interval'], $config['domain_daily_limit'], $config['ttl_hours'], $config['retention_days']));
		WP_CLI::log('User-Agent: ' . $config['user_agent']);
		WP_CLI::log(sprintf('Snapshots: %d, network fetches in 24 h: %d. Pages: %s.', $status['counts']['snapshots'], $status['counts']['fetches_24h'], (string) wp_json_encode($status['counts']['targets'])));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function plan(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_PAGE_INTELLIGENCE);

		try {
			$plan = $this->service()->plan($context, self::selection($assocArgs), isset($assocArgs['force']));
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic or keyword not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($plan);

			return;
		}

		self::table($plan['items']);
		WP_CLI::log(sprintf('Would fetch %d page(s) (max %d per request).%s', $plan['fetches'], $plan['max_urls'], $plan['refused'] === null ? '' : ' Request refused: ' . $plan['refused'] . '.'));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function fetch(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_PAGE_INTELLIGENCE);
		$selection = self::selection($assocArgs);
		$json = ($assocArgs['format'] ?? 'table') === 'json';

		try {
			$plan = $this->service()->plan($context, $selection, isset($assocArgs['force']));

			if ($plan['refused'] !== null) {
				WP_CLI::error('Page fetch refused: ' . $plan['refused'] . '.');
			}

			if ($plan['fetches'] > 0 && ! isset($assocArgs['yes'])) {
				$hosts = array_values(array_unique(array_filter(array_map(static fn (array $item): ?string => $item['would_fetch'] && $item['allowed'] ? $item['host'] : null, $plan['items']))));
				WP_CLI::confirm(sprintf('Fetch %d public page(s) from %s (external HTTP requests)?', $plan['fetches'], implode(', ', $hosts)));
			}

			$results = $this->service()->fetch($context, $selection, isset($assocArgs['force']), PageIntelligenceService::TRIGGER_CLI, true);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic or keyword not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (PageRefused $refused) {
			WP_CLI::error('Page fetch refused: ' . $refused->reason() . '.');
		}

		if ($json) {
			self::json($results);

			return;
		}

		self::table($results, true);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function checkUrl(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_PAGE_INTELLIGENCE);

		try {
			$check = $this->service()->checkUrl($context, (string) ($assocArgs['page-url'] ?? ''));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($check);

			return;
		}

		WP_CLI::log(sprintf('Host %s (%s): %s%s. Addresses: %s.', $check['host'] ?? '—', $check['kind'] ?? '—', $check['allowed'] ? 'allowed' : 'refused', $check['reason'] === null ? '' : ' — ' . $check['reason'], $check['ips'] === [] ? '—' : implode(', ', $check['ips'])));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function list(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$pages = $this->service()->pages($context, $assocArgs['kind'] ?? null);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($pages);

			return;
		}

		if ($pages === []) {
			WP_CLI::log('No pages.');

			return;
		}

		Utils\format_items('table', array_map(static fn (array $page): array => [
			'id' => $page['id'],
			'kind' => $page['kind'],
			'url' => $page['url'],
			'cache' => $page['cache'],
			'status' => $page['status'] . ($page['last_error'] !== null ? ' (' . $page['last_error'] . ')' : ''),
			'fetched' => $page['snapshot']['last_seen_at'] ?? '—',
			'quality' => $page['snapshot']['content_quality'] ?? '—',
			'words' => $page['snapshot']['word_count'] ?? '—',
			'serp' => $page['serp'] === null ? '—' : '#' . $page['serp']['rank_group'] . ' ' . $page['serp']['keyword'] . ' (' . $page['serp']['serp_checked_at'] . ')',
		], $pages), ['id', 'kind', 'url', 'cache', 'status', 'fetched', 'quality', 'words', 'serp']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function show(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);

		try {
			self::json($this->service()->page($context, (string) $assocArgs['page']));
		} catch (PageNotFound) {
			WP_CLI::error('Page not found.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function snapshot(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);

		try {
			self::json($this->service()->snapshot($context, (string) $assocArgs['snapshot']));
		} catch (PageNotFound) {
			WP_CLI::error('Snapshot not found.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function delete(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_PAGE_INTELLIGENCE);

		if (! isset($assocArgs['yes'])) {
			WP_CLI::confirm('Delete the page with its snapshots and fetch history?');
		}

		try {
			$this->service()->delete($context, (string) $assocArgs['page']);
		} catch (PageNotFound) {
			WP_CLI::error('Page not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		WP_CLI::success('Page deleted.');
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function purge(array $args, array $assocArgs): void
	{
		$userId = get_current_user_id();

		if ($userId > 0 && ! user_can($userId, Capabilities::MANAGE_PAGE_INTELLIGENCE)) {
			WP_CLI::error('Access denied.');
		}

		$result = $this->service()->maintenance(true) ?? ['snapshots' => 0, 'fetches' => 0];

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($result);

			return;
		}

		WP_CLI::success(sprintf('Removed %d snapshot(s) and %d fetch record(s).', $result['snapshots'], $result['fetches']));
	}

	/**
	 * @param array<string, string> $assocArgs
	 */
	private static function selection(array $assocArgs): PageSelection
	{
		$given = array_filter([isset($assocArgs['page-url']) || isset($assocArgs['urls']), isset($assocArgs['topic']), isset($assocArgs['keyword'])]);

		if (count($given) !== 1) {
			WP_CLI::error('Use exactly one of: --page-url/--urls, --topic, --keyword (with --ranks).');
		}

		if (isset($assocArgs['topic'])) {
			return PageSelection::topic((string) $assocArgs['topic']);
		}

		if (isset($assocArgs['keyword'])) {
			return PageSelection::serp((string) $assocArgs['keyword'], array_map('intval', preg_split('/[\s,;]+/', (string) ($assocArgs['ranks'] ?? '')) ?: []));
		}

		$urls = isset($assocArgs['page-url']) ? [(string) $assocArgs['page-url']] : [];

		return PageSelection::urls([...$urls, ...(preg_split('/\s+/', (string) ($assocArgs['urls'] ?? '')) ?: [])]);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	private static function table(array $items, bool $results = false): void
	{
		if ($items === []) {
			WP_CLI::log('Nothing selected.');

			return;
		}

		Utils\format_items('table', array_map(static fn (array $item): array => [
			'url' => $item['url'] ?? $item['input'] ?? '—',
			'kind' => $item['kind'] ?? '—',
			'source' => $item['source'],
			'allowed' => $item['allowed'] ? 'yes' : 'no (' . $item['reason'] . ')',
			'cache' => $item['cache'] ?? '—',
			'action' => $results ? ($item['outcome'] ?? '—') . (($item['error'] ?? null) !== null ? ' (' . $item['error'] . ')' : '') : ($item['would_fetch'] ? 'fetch' : ($item['allowed'] ? 'use cache' : '—')),
			'host' => ($item['host_limits']['blocked'] ?? null) !== null ? ($item['host'] ?? '—') . ' — ' . $item['host_limits']['blocked'] : ($item['host'] ?? '—'),
			'serp' => is_array($item['serp'] ?? null) ? '#' . $item['serp']['rank_group'] . ' (' . $item['serp']['checked_at'] . ')' : '—',
		], $items), ['url', 'kind', 'source', 'allowed', 'cache', 'action', 'host', 'serp']);
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private static function json(array $data): void
	{
		WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function service(): PageIntelligenceService
	{
		return $this->plugin->get(PageIntelligenceService::class);
	}
}
