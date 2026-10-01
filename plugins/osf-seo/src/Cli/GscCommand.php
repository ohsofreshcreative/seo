<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Gsc\GscProperty;
use OsfSeo\Gsc\PropertySelectionException;
use OsfSeo\Gsc\PropertyService;
use OsfSeo\Plugin;
use WP_CLI;

/**
 * `wp osf-seo gsc:*` — Google Search Console dla projektu. Żadna komenda nie wypisuje tokenów ani sekretów.
 */
final class GscCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$project = ['type' => 'assoc', 'name' => 'project', 'description' => 'Project public ID (ULID).', 'optional' => false];
		$format = ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json', 'csv']];

		WP_CLI::add_command('osf-seo gsc:properties', [$command, 'properties'], [
			'shortdesc' => 'List Search Console properties available to the project\'s Google connection.',
			'synopsis' => [$project, $format],
		]);

		WP_CLI::add_command('osf-seo gsc:select-property', [$command, 'selectProperty'], [
			'shortdesc' => 'Select the Search Console property of a project (exact siteUrl from gsc:properties).',
			'synopsis' => [
				$project,
				['type' => 'assoc', 'name' => 'property', 'description' => 'Exact property identifier, e.g. sc-domain:example.com or https://www.example.com/.', 'optional' => false],
				['type' => 'flag', 'name' => 'reset-data', 'description' => 'Delete all GSC data of the project and re-import from the new property (required when data from another property exists).', 'optional' => true],
				['type' => 'flag', 'name' => 'yes', 'description' => 'Do not ask for confirmation of --reset-data.', 'optional' => true],
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function properties(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_CONNECTIONS);

		try {
			$list = $this->plugin->get(PropertyService::class)->properties($context);
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (PropertySelectionException $exception) {
			WP_CLI::error(self::describe($exception));
		}

		$selected = $context->project()->gscProperty;

		\WP_CLI\Utils\format_items($assocArgs['format'] ?? 'table', array_map(static fn (GscProperty $property): array => [
			'site_url' => $property->siteUrl,
			'type' => $property->isDomainProperty() ? 'domain' : 'url_prefix',
			'permission' => $property->permissionLevel,
			'search_analytics' => $property->canQuerySearchAnalytics() ? 'yes' : 'no',
			'suggested' => $property->siteUrl === $list->suggested ? 'yes' : 'no',
			'selected' => $property->siteUrl === $selected ? 'yes' : 'no',
			'requires_reset' => $list->requiresReset($property->siteUrl) ? 'yes' : 'no',
		], $list->properties), ['site_url', 'type', 'permission', 'search_analytics', 'suggested', 'selected', 'requires_reset']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function selectProperty(array $args, array $assocArgs): void
	{
		$context = CliProject::resolve($this->plugin, $assocArgs, Capabilities::MANAGE_CONNECTIONS);
		$reset = isset($assocArgs['reset-data']);

		if ($reset) {
			WP_CLI::confirm(sprintf('Delete ALL Search Console data of project %s and re-import it from %s?', $context->publicId(), $assocArgs['property']), $assocArgs);
		}

		try {
			$context = $this->plugin->get(PropertyService::class)->select($context, (string) $assocArgs['property'], $reset);
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (PropertySelectionException $exception) {
			WP_CLI::error(self::describe($exception));
		}

		WP_CLI::success(sprintf('Project %s now uses property %s (%s).', $context->publicId(), $context->project()->gscProperty, $context->project()->gscPermission));
	}

	private static function describe(PropertySelectionException $exception): string
	{
		$previous = $exception->getPrevious();
		$detail = $previous !== null ? ' ' . $previous->getMessage() : '';

		return match ($exception->reason()) {
			PropertySelectionException::NO_CONNECTION => 'The project is not connected to a Google account.',
			PropertySelectionException::CONNECTION_INACTIVE => 'The Google connection needs re-authorization (reconnect in the panel).',
			PropertySelectionException::NOT_FOUND => 'The property is not available to the connected Google account (use the exact site_url from gsc:properties).',
			PropertySelectionException::INSUFFICIENT_PERMISSION => 'The connected account cannot read Search Analytics data of this property (siteUnverifiedUser).',
			PropertySelectionException::RESET_REQUIRED => 'The project already has data from another property. Re-run with --reset-data to delete it and re-import.',
			PropertySelectionException::CONFIGURATION => 'Cannot decrypt the Google refresh token (check OSF_SEO_ENCRYPTION_KEY).',
			default => 'Search Console API request failed.' . $detail,
		};
	}
}
