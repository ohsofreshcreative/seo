<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Plugin;
use OsfSeo\Strategy\Decision\StrategyAction;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\Topics\TopicFilters;
use OsfSeo\Strategy\Topics\TopicRow;
use OsfSeo\Strategy\Topics\TopicStatus;
use OsfSeo\Support\ValidationException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp osf-seo strategy:*` — tematy Strategii (STEP 16, faza C): lista backlogu, szczegóły tematu, pakiet kontekstu, status pracy, ręczna
 * strona docelowa, przypięcia fraz. Żadna komenda nie wysyła żądań do API. Wyjście po angielsku; `--format=json` wyłącznie JSON.
 */
final class StrategyTopicCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];
		$topic = ['type' => 'assoc', 'name' => 'topic', 'description' => 'Topic ID (ULID), or a candidate ID / keyword of the topic.', 'optional' => false];
		$keywords = ['type' => 'assoc', 'name' => 'keywords', 'description' => 'Candidates (IDs or keywords, comma, semicolon or new line separated).', 'optional' => false];

		WP_CLI::add_command('osf-seo strategy:list', [$command, 'list'], [
			'shortdesc' => 'List strategy topics (backlog) by strategy priority (no API call). Monitor topics are hidden unless --include-monitor or --action=monitor.',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'action', 'description' => implode(', ', StrategyAction::values()) . '.', 'optional' => true],
				['type' => 'assoc', 'name' => 'status', 'description' => implode(', ', TopicFilters::STATUSES) . ' (default: open).', 'optional' => true, 'default' => 'open'],
				['type' => 'assoc', 'name' => 'confidence', 'description' => implode(', ', TopicFilters::LEVELS) . '.', 'optional' => true],
				['type' => 'assoc', 'name' => 'state', 'description' => implode(', ', TopicFilters::STATES) . ' (default: active).', 'optional' => true, 'default' => 'active'],
				['type' => 'assoc', 'name' => 'search', 'description' => 'Topic label contains.', 'optional' => true],
				['type' => 'assoc', 'name' => 'sort', 'description' => implode(', ', TopicFilters::SORTS) . '.', 'optional' => true, 'default' => 'priority'],
				['type' => 'flag', 'name' => 'include-monitor', 'description' => 'Include monitor topics.', 'optional' => true],
				['type' => 'assoc', 'name' => 'page', 'description' => 'Page.', 'optional' => true],
				['type' => 'assoc', 'name' => 'per-page', 'description' => 'Rows per page (default 50).', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo strategy:topic', [$command, 'topic'], [
			'shortdesc' => 'Strategy topic detail: keywords, target page resolution, action and reason, confidence factors, priority breakdown, URL conflicts, suggestions, events (no API call).',
			'synopsis' => [$project, $topic, $format],
		]);
		WP_CLI::add_command('osf-seo strategy:context', [$command, 'context'], [
			'shortdesc' => 'Deterministic context package of a topic (JSON, evidence_hash; for future AI steps — no AI and no API call).',
			'synopsis' => [$project, $topic],
		]);
		WP_CLI::add_command('osf-seo strategy:set-status', [$command, 'setStatus'], [
			'shortdesc' => 'Set the work status of a topic (refresh never changes it).',
			'synopsis' => [
				$project,
				$topic,
				['type' => 'assoc', 'name' => 'status', 'description' => implode(', ', TopicStatus::values()) . '.', 'optional' => false],
				['type' => 'assoc', 'name' => 'note', 'description' => 'Internal note.', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo strategy:set-target', [$command, 'setTarget'], [
			'shortdesc' => 'Set the target page of a topic manually (a URL of the project domain), confirm that no page exists, or clear the manual target.',
			'synopsis' => [
				$project,
				$topic,
				// Nie `--url` — to globalny parametr WP-CLI.
				['type' => 'assoc', 'name' => 'target-url', 'description' => 'Target page URL (project domain).', 'optional' => true],
				// Nie `--no-page` — WP-CLI traktuje `--no-*` jako negację flagi.
				['type' => 'flag', 'name' => 'confirm-missing', 'description' => 'Confirm manually that the project has no page for this topic.', 'optional' => true],
				['type' => 'flag', 'name' => 'clear', 'description' => 'Remove the manual target.', 'optional' => true],
				$format,
			],
		]);
		WP_CLI::add_command('osf-seo strategy:pin', [$command, 'pin'], [
			'shortdesc' => 'Pin candidates to a topic (or to a new topic) — pins take precedence over automatic grouping on the next refresh.',
			'synopsis' => [
				$project,
				$keywords,
				['type' => 'assoc', 'name' => 'topic', 'description' => 'Target topic (ULID or a keyword of the topic).', 'optional' => true],
				['type' => 'flag', 'name' => 'new', 'description' => 'Pin to a new topic.', 'optional' => true],
			],
		]);
		WP_CLI::add_command('osf-seo strategy:unpin', [$command, 'unpin'], [
			'shortdesc' => 'Unpin candidates — they return to automatic grouping on the next refresh.',
			'synopsis' => [$project, $keywords],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function list(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);

		if (isset($assocArgs['action']) && StrategyAction::fromInput($assocArgs['action']) === null) {
			WP_CLI::error('Unknown action. Use: ' . implode(', ', StrategyAction::values()) . '.');
		}

		$filters = TopicFilters::fromInput([
			'action' => $assocArgs['action'] ?? null,
			'status' => $assocArgs['status'] ?? 'open',
			'confidence' => $assocArgs['confidence'] ?? null,
			'state' => $assocArgs['state'] ?? 'active',
			'q' => $assocArgs['search'] ?? '',
			'sort' => $assocArgs['sort'] ?? 'priority',
			'page' => $assocArgs['page'] ?? '1',
			'per_page' => $assocArgs['per-page'] ?? '50',
			'include_monitor' => isset($assocArgs['include-monitor']),
		]);
		$result = $this->service()->topics($context, $filters);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json(['total' => $result['total'], 'page' => $filters->page, 'rows' => array_map(static fn (TopicRow $row): array => $row->toArray(), $result['rows'])]);

			return;
		}

		WP_CLI::log(sprintf('Topics: %d (page %d). Priority = strategy priority 0–100 (a sorting tool, not a traffic forecast).', $result['total'], $filters->page));

		if ($result['rows'] !== []) {
			Utils\format_items('table', array_map(static fn (TopicRow $row): array => [
				'id' => $row->publicId,
				'priority' => $row->priority ?? '—',
				'action' => $row->action ?? '—',
				'reason' => $row->actionReason ?? '—',
				'confidence' => $row->confidenceLevel === null ? '—' : $row->confidenceLevel . ' (' . $row->confidence . ')',
				'target' => $row->targetState ?? '—',
				'keywords' => $row->keywordsCount,
				'status' => $row->status . ($row->decisionChanged ? ' *' : ''),
				'topic' => $row->label,
			], $result['rows']), ['id', 'priority', 'action', 'reason', 'confidence', 'target', 'keywords', 'status', 'topic']);
			WP_CLI::log('* = the action or target page changed after the status decision.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function topic(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);

		try {
			$detail = $this->service()->topic($context, (string) $assocArgs['topic']);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic not found.');
		}

		$topic = $detail['topic'];

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json(['topic' => $topic->toArray(true), 'keywords' => $detail['members'], 'events' => $detail['events']]);

			return;
		}

		$analysis = $topic->analysis ?? [];
		$target = (array) ($analysis['target'] ?? []);
		$confidence = (array) ($analysis['confidence'] ?? []);
		$priority = (array) ($analysis['priority'] ?? []);
		WP_CLI::log(sprintf('%s — %s, status %s%s.', $topic->label, $topic->active ? 'active' : 'inactive (' . $topic->inactiveReason . ')', $topic->status, $topic->decisionChanged ? ' (decision basis changed)' : ''));
		WP_CLI::log(sprintf('Action: %s (%s).', $topic->action ?? '—', $topic->actionReason ?? '—'));
		WP_CLI::log(sprintf(
			'Target page: %s%s%s.',
			$topic->targetState ?? '—',
			$topic->targetUrl === null ? '' : ' ' . $topic->targetUrl,
			($target['families'] ?? []) === [] ? '' : ' [' . implode(', ', (array) $target['families']) . ']',
		));
		WP_CLI::log(sprintf('Confidence: %s (%s points)%s.', $topic->confidenceLevel ?? '—', $topic->confidence ?? '—', ($confidence['caps'] ?? []) === [] ? '' : '; capped: ' . implode(', ', (array) $confidence['caps'])));

		foreach (['positive', 'negative'] as $side) {
			if (($confidence[$side] ?? []) !== []) {
				WP_CLI::log(sprintf('  %s: %s', $side, implode(', ', array_map(static fn (array $factor): string => sprintf('%s %+d', $factor['code'], $factor['points']), (array) $confidence[$side]))));
			}
		}

		WP_CLI::log(sprintf('Strategy priority: %s (raw %s × confidence multiplier %s × action factor %s).', $topic->priority ?? '—', $priority['raw'] ?? '—', $priority['confidence_multiplier'] ?? '—', $priority['action_factor'] ?? '—'));

		foreach ((array) ($priority['components'] ?? []) as $name => $component) {
			WP_CLI::log(sprintf('  %s: %s / %s', $name, $component['value'] ?? '—', $component['max'] ?? '—'));
		}

		foreach ((array) ($analysis['conflicts'] ?? []) as $conflict) {
			WP_CLI::log(sprintf('URL conflict: %s (%s) — %s.', $conflict['type'], $conflict['strength'], implode(' vs ', (array) $conflict['urls'])));
		}

		foreach ((array) ($analysis['suggestions'] ?? []) as $suggestion) {
			WP_CLI::log(sprintf('Possible group (not merged): %s "%s" — %s.', $suggestion['topic'], $suggestion['label'], implode(', ', (array) $suggestion['signals'])));
		}

		if ($detail['members'] !== []) {
			$basis = [];

			foreach ((array) ($analysis['members'] ?? []) as $member) {
				$basis[(string) ($member['id'] ?? '')] = (string) ($member['basis'] ?? '');
			}

			Utils\format_items('table', array_map(static fn (array $member): array => [
				'id' => $member['id'],
				'keyword' => $member['keyword'],
				'basis' => ($basis[$member['id']] ?? '—') . ($member['pinned'] ? ' (pinned)' : ''),
				'volume' => $member['volume'] ?? '—',
				'kd' => $member['difficulty'] ?? '—',
				'gsc_impressions' => $member['gsc_impressions'] ?? '—',
				'gsc_position' => $member['gsc_position'] ?? '—',
				'serp' => $member['serp_rank'] === null ? '—' : '#' . $member['serp_rank'],
				'target' => $member['target_state'] ?? '—',
			], $detail['members']), ['id', 'keyword', 'basis', 'volume', 'kd', 'gsc_impressions', 'gsc_position', 'serp', 'target']);
		}

		foreach (array_slice($detail['events'], 0, 10) as $event) {
			WP_CLI::log(sprintf('%s  %s%s', $event['at'], $event['type'], $event['from'] !== null || $event['to'] !== null ? ': ' . ($event['from'] ?? '—') . ' → ' . ($event['to'] ?? '—') : ''));
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function context(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);

		try {
			self::json($this->service()->context($context, (string) $assocArgs['topic']));
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic not found.');
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function setStatus(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_STRATEGY);
		$status = TopicStatus::fromInput($assocArgs['status'] ?? null);

		if ($status === null) {
			WP_CLI::error('Unknown status. Use: ' . implode(', ', TopicStatus::values()) . '.');
		}

		try {
			$topic = $this->service()->setStatus($context, (string) $assocArgs['topic'], $status, isset($assocArgs['note']) ? (string) $assocArgs['note'] : null);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($topic->toArray());

			return;
		}

		WP_CLI::success(sprintf('Topic %s status: %s.', $topic->publicId, $topic->status));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function setTarget(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_STRATEGY);
		$url = isset($assocArgs['target-url']) ? (string) $assocArgs['target-url'] : null;
		$noPage = isset($assocArgs['confirm-missing']);

		if ($url === null && ! $noPage && ! isset($assocArgs['clear'])) {
			WP_CLI::error('Use --target-url=<url>, --confirm-missing or --clear.');
		}

		try {
			$topic = $this->service()->setTarget($context, (string) $assocArgs['topic'], $url, $noPage);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic not found.');
		} catch (ValidationException $exception) {
			WP_CLI::error(implode(' ', $exception->errors()));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		if (($assocArgs['format'] ?? 'table') === 'json') {
			self::json($topic->toArray());

			return;
		}

		WP_CLI::success(sprintf(
			'Topic %s manual target: %s. Run strategy:refresh to recalculate. No API request was made.',
			$topic->publicId,
			$topic->manualTargetUrl ?? ($topic->manualNoPage ? 'no page (confirmed manually)' : 'none'),
		));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function pin(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_STRATEGY);

		if (isset($assocArgs['topic']) === isset($assocArgs['new'])) {
			WP_CLI::error('Use either --topic=<topic> or --new.');
		}

		try {
			$result = $this->service()->pin($context, self::values((string) $assocArgs['keywords']), isset($assocArgs['new']) ? null : (string) $assocArgs['topic']);
		} catch (StrategyNotFound) {
			WP_CLI::error('Strategy topic not found.');
		} catch (ValidationException $exception) {
			WP_CLI::error(implode(' ', $exception->errors()));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		foreach ($result['missing'] as $missing) {
			WP_CLI::warning(sprintf('Not a strategy candidate: "%s".', $missing));
		}

		WP_CLI::success(sprintf('Pinned %d to topic %s. Run strategy:refresh to regroup topics. No API request was made.', $result['pinned'], $result['topic']));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function unpin(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_STRATEGY);

		try {
			$unpinned = $this->service()->unpin($context, self::values((string) $assocArgs['keywords']));
		} catch (ValidationException $exception) {
			WP_CLI::error(implode(' ', $exception->errors()));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		}

		WP_CLI::success(sprintf('Unpinned %d. Run strategy:refresh to regroup topics. No API request was made.', $unpinned));
	}

	/**
	 * @return list<string>
	 */
	private static function values(string $input): array
	{
		return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/u', $input) ?: []), static fn (string $value): bool => $value !== ''));
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
