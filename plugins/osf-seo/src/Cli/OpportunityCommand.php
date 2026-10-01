<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Opportunities\AnalysisResult;
use OsfSeo\Opportunities\Opportunity;
use OsfSeo\Opportunities\OpportunityAnalyzer;
use OsfSeo\Opportunities\OpportunityConfig;
use OsfSeo\Opportunities\OpportunityFilters;
use OsfSeo\Opportunities\OpportunityService;
use OsfSeo\Plugin;
use WP_CLI;

/**
 * `wp osf-seo opportunities:*` — szanse SEO projektu (analiza danych GSC już zapisanych w bazie; bez wywołań Google).
 */
final class OpportunityCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];

		WP_CLI::add_command('osf-seo opportunities:analyze', [$command, 'analyze'], [
			'shortdesc' => 'Detect SEO opportunities from imported GSC data (all periods or one).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'days', 'description' => 'Period: 7, 28 or 90 (default: all three).', 'optional' => true, 'options' => ['7', '28', '90']],
				['type' => 'flag', 'name' => 'force', 'description' => 'Recalculate even if the imported data did not change.', 'optional' => true],
				['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json']],
			],
		]);

		WP_CLI::add_command('osf-seo opportunities:list', [$command, 'list'], [
			'shortdesc' => 'List detected SEO opportunities ordered by priority.',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'days', 'description' => 'Period: 7, 28 (default) or 90.', 'optional' => true, 'options' => ['7', '28', '90']],
				['type' => 'assoc', 'name' => 'type', 'description' => 'low_ctr, near_top, weak_position, decline, cannibalization.', 'optional' => true],
				['type' => 'assoc', 'name' => 'status', 'description' => 'open (default), all, new, review, planned, in_progress, completed, dismissed.', 'optional' => true],
				['type' => 'assoc', 'name' => 'state', 'description' => 'active (default), inactive, archived.', 'optional' => true],
				['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json', 'csv']],
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function analyze(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_OPPORTUNITIES);
		$periods = isset($assocArgs['days']) ? [(int) $assocArgs['days']] : OpportunityConfig::PERIODS;
		$results = $this->plugin->get(OpportunityAnalyzer::class)->analyzeAll($context, OpportunityAnalyzer::TRIGGER_CLI, isset($assocArgs['force']), $periods, 30);

		\WP_CLI\Utils\format_items($assocArgs['format'] ?? 'table', array_map(static fn (AnalysisResult $result): array => $result->toArray(), $results), ['days', 'status', 'opportunities', 'inserted', 'deactivated', 'reason', 'duration_ms']);

		if (array_filter($results, static fn (AnalysisResult $result): bool => $result->status === AnalysisResult::FAILED) !== []) {
			WP_CLI::halt(1);
		}
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function list(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::ACCESS);
		$filters = OpportunityFilters::fromInput($assocArgs)->with(['perPage' => 100]);
		$page = $this->plugin->get(OpportunityService::class)->list($context, $filters);

		\WP_CLI\Utils\format_items($assocArgs['format'] ?? 'table', array_map(static fn (Opportunity $opportunity): array => [
			'id' => $opportunity->publicId,
			'type' => $opportunity->type->value,
			'priority' => $opportunity->priority,
			'confidence' => strtolower($opportunity->confidence->name),
			'status' => $opportunity->status->value,
			'state' => $opportunity->state->value,
			'title' => $opportunity->title(),
			'impressions' => $opportunity->current()->impressions,
			'clicks' => $opportunity->current()->clicks,
			'keywords' => (int) ($opportunity->evidence['keywords_total'] ?? 0),
			'data_until' => $opportunity->latestDate,
		], $page->rows), ['id', 'type', 'priority', 'confidence', 'status', 'state', 'title', 'impressions', 'clicks', 'keywords', 'data_until']);
	}
}
