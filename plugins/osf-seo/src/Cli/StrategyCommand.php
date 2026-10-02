<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Plugin;
use OsfSeo\Strategy\CandidateFilters;
use OsfSeo\Strategy\CandidateRow;
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
			'Refreshed in %d ms: %d selected (%d new, %d updated, %d unchanged, %d deactivated); over the limit %d. No API request was made.',
			$report['duration_ms'],
			$report['stats']['selected'],
			$report['inserted'],
			$report['updated'],
			$report['unchanged'],
			$report['deactivated'],
			$report['stats']['overflow'],
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
