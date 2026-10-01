<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Plugin;
use OsfSeo\Serp\CompetitorService;
use OsfSeo\Serp\SerpNotFound;
use OsfSeo\Support\ValidationException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp osf-seo competitors:*` — konkurenci projektu (dodani ręcznie i organiczni z pełnych SERP-ów). Bez wywołań API.
 */
final class CompetitorCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']];

		WP_CLI::add_command('osf-seo competitors:list', [$command, 'list'], [
			'shortdesc' => 'List configured competitors with factual SERP counts from the latest checks.',
			'synopsis' => [$project, ['type' => 'flag', 'name' => 'archived', 'description' => 'Include archived competitors.', 'optional' => true], $format],
		]);
		WP_CLI::add_command('osf-seo competitors:add', [$command, 'add'], [
			'shortdesc' => 'Add a competitor domain (no API request; history is reconstructed from stored SERPs).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'domain', 'description' => 'Competitor domain (subdomains included).', 'optional' => false],
				['type' => 'assoc', 'name' => 'name', 'description' => 'Display name.', 'optional' => true],
			],
		]);
		WP_CLI::add_command('osf-seo competitors:update', [$command, 'update'], [
			'shortdesc' => 'Edit, deactivate or archive a competitor (history is kept).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'competitor', 'description' => 'Competitor public ID.', 'optional' => false],
				['type' => 'assoc', 'name' => 'name', 'description' => 'Display name.', 'optional' => true],
				['type' => 'assoc', 'name' => 'domain', 'description' => 'Domain.', 'optional' => true],
				['type' => 'assoc', 'name' => 'status', 'description' => 'Status.', 'optional' => true, 'options' => ['active', 'inactive', 'archived']],
			],
		]);
		WP_CLI::add_command('osf-seo competitors:organic', [$command, 'organic'], [
			'shortdesc' => 'Domains that most often appear in the latest SERPs of tracked keywords (facts, no scores).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'sort', 'description' => 'keywords, top10, top3, overlap, avg_rank.', 'optional' => true, 'default' => 'keywords'],
				['type' => 'assoc', 'name' => 'page', 'description' => 'Page (50 per page).', 'optional' => true],
				$format,
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function list(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$rows = array_map(static fn (array $item): array => $item['competitor']->toArray() + $item['stats'], $this->service()->list($context, isset($assocArgs['archived'])));

		if (($assocArgs['format'] ?? 'table') === 'json') {
			WP_CLI::line((string) wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			return;
		}

		$rows === [] ? WP_CLI::log('No competitors.') : Utils\format_items('table', $rows, ['id', 'name', 'domain', 'status', 'found', 'top3', 'top10', 'top20', 'avg_rank', 'checked']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function add(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_SERP_TRACKING);

		try {
			$competitor = $this->service()->create($context, ['domain' => $assocArgs['domain'] ?? '', 'name' => $assocArgs['name'] ?? '']);
		} catch (ValidationException $exception) {
			WP_CLI::error(implode(' ', $exception->errors()));
		}

		WP_CLI::success(sprintf('Competitor %s (%s) added: %s.', $competitor->name, $competitor->domain, $competitor->publicId));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function update(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_SERP_TRACKING);
		$input = array_intersect_key($assocArgs, array_flip(['name', 'domain', 'status']));

		try {
			$competitor = $this->service()->update($context, (string) $assocArgs['competitor'], $input);
		} catch (SerpNotFound) {
			WP_CLI::error('Competitor not found.');
		} catch (ValidationException $exception) {
			WP_CLI::error(implode(' ', $exception->errors()));
		}

		WP_CLI::success(sprintf('Competitor %s (%s) saved: %s.', $competitor->name, $competitor->domain, $competitor->status));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function organic(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$data = $this->service()->organic($context, max(1, (int) ($assocArgs['page'] ?? 1)), (string) ($assocArgs['sort'] ?? 'keywords'));
		$rows = array_map(static fn (array $row): array => [
			'domain' => $row['host'],
			'keywords' => $row['keywords'],
			'top3' => $row['top3'],
			'top10' => $row['top10'],
			'top20' => $row['top20'],
			'avg_rank' => $row['avg_rank'],
			'with_project' => $row['overlap'],
			'urls' => $row['urls'],
			'configured' => $row['competitor'] !== null ? 'yes' : '',
		], $data['rows']);

		if (($assocArgs['format'] ?? 'table') === 'json') {
			WP_CLI::line((string) wp_json_encode(['keywords_checked' => $data['keywords'], 'total' => $data['total'], 'domains' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			return;
		}

		$rows === [] ? WP_CLI::log('No stored SERPs yet.') : Utils\format_items('table', $rows, array_keys($rows[0]));
		WP_CLI::log(sprintf('Domains: %d, based on the latest checks of %d tracked keyword(s). Facts only — no scores.', $data['total'], $data['keywords']));
	}

	private function service(): CompetitorService
	{
		return $this->plugin->get(CompetitorService::class);
	}
}
