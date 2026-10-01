<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Plugin;
use OsfSeo\Projects\Project;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Projects\ProjectService;
use OsfSeo\Projects\ProjectStatus;
use OsfSeo\Support\ValidationException;
use Throwable;
use WP_CLI;

/**
 * `wp osf-seo project:*` — obsługa projektów z serwera.
 *
 * Z globalnym `--user=<login>` komendy działają z uprawnieniami i widocznością tego użytkownika
 * (przydatne do sprawdzania dostępu); bez `--user` — jako operator systemu (wszystkie projekty).
 */
final class ProjectCommand
{
	public function __construct(private readonly Plugin $plugin)
	{
	}

	public static function register(Plugin $plugin): void
	{
		$command = new self($plugin);
		$format = [
			'type' => 'assoc',
			'name' => 'format',
			'description' => 'Output format.',
			'optional' => true,
			'default' => 'table',
			'options' => ['table', 'json', 'csv', 'ids'],
		];

		WP_CLI::add_command('osf-seo project:list', [$command, 'list'], [
			'shortdesc' => 'List projects visible to the current user (all projects without --user).',
			'synopsis' => [
				['type' => 'assoc', 'name' => 'status', 'description' => 'Filter by status (default: all except archived).', 'optional' => true, 'options' => ['active', 'paused', 'archived']],
				$format,
			],
		]);

		WP_CLI::add_command('osf-seo project:create', [$command, 'create'], [
			'shortdesc' => 'Create a project.',
			'synopsis' => [
				['type' => 'assoc', 'name' => 'name', 'description' => 'Project name.', 'optional' => false],
				['type' => 'assoc', 'name' => 'domain', 'description' => 'Domain, e.g. example.pl.', 'optional' => false],
				['type' => 'assoc', 'name' => 'country', 'description' => 'ISO country code.', 'optional' => true, 'default' => 'pl'],
				['type' => 'assoc', 'name' => 'language', 'description' => 'Language code.', 'optional' => true, 'default' => 'pl'],
				['type' => 'flag', 'name' => 'porcelain', 'description' => 'Output only the project public ID.', 'optional' => true],
			],
		]);

		WP_CLI::add_command('osf-seo project:assign', [$command, 'assign'], [
			'shortdesc' => 'Give a user access to a project.',
			'synopsis' => [
				['type' => 'positional', 'name' => 'project', 'description' => 'Project public ID (ULID).'],
				['type' => 'positional', 'name' => 'user', 'description' => 'User ID, login or e-mail.'],
				['type' => 'assoc', 'name' => 'role', 'description' => 'Role in the project.', 'optional' => true, 'default' => 'viewer', 'options' => ['viewer', 'manager']],
			],
		]);

		WP_CLI::add_command('osf-seo project:unassign', [$command, 'unassign'], [
			'shortdesc' => "Remove a user's access to a project.",
			'synopsis' => [
				['type' => 'positional', 'name' => 'project', 'description' => 'Project public ID (ULID).'],
				['type' => 'positional', 'name' => 'user', 'description' => 'User ID, login or e-mail.'],
			],
		]);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function list(array $args, array $assocArgs): void
	{
		$service = $this->plugin->get(ProjectService::class);
		$status = isset($assocArgs['status']) ? ProjectStatus::from($assocArgs['status']) : null;
		$userId = get_current_user_id();

		$projects = $userId > 0 ? $service->listFor($userId, $status) : $service->listForSystem($status);
		$format = $assocArgs['format'] ?? 'table';

		if ($format === 'ids') {
			WP_CLI::line(implode(' ', array_map(static fn (Project $p): string => $p->publicId, $projects)));

			return;
		}

		\WP_CLI\Utils\format_items($format, array_map(static fn (Project $p): array => [
			'public_id' => $p->publicId,
			'name' => $p->name,
			'domain' => $p->domain,
			'status' => $p->status->value,
			'country' => $p->country,
			'created_at' => $p->createdAt->format('Y-m-d H:i:s'),
		], $projects), ['public_id', 'name', 'domain', 'status', 'country', 'created_at']);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function create(array $args, array $assocArgs): void
	{
		try {
			$context = $this->plugin->get(ProjectService::class)->create($assocArgs, get_current_user_id());
		} catch (ValidationException $exception) {
			WP_CLI::error("Invalid project data:\n- " . implode("\n- ", $exception->errors()));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied: the user cannot manage projects.');
		}

		if (isset($assocArgs['porcelain'])) {
			WP_CLI::line($context->publicId());

			return;
		}

		WP_CLI::success(sprintf('Project "%s" created: %s', $context->project()->name, $context->publicId()));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function assign(array $args, array $assocArgs): void
	{
		$context = $this->context($args[0], Capabilities::MANAGE_USERS);
		$user = $this->user($args[1]);

		try {
			$this->plugin->get(ProjectService::class)->assignUser($context, $user->ID, ProjectRole::from($assocArgs['role'] ?? 'viewer'));
		} catch (AccessDenied) {
			WP_CLI::error('Access denied: the user cannot manage project members.');
		}

		WP_CLI::success(sprintf('User %s assigned to project %s.', $user->user_login, $context->publicId()));
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $assocArgs
	 */
	public function unassign(array $args, array $assocArgs): void
	{
		$context = $this->context($args[0], Capabilities::MANAGE_USERS);
		$user = $this->user($args[1]);

		try {
			$removed = $this->plugin->get(ProjectService::class)->unassignUser($context, $user->ID);
		} catch (AccessDenied) {
			WP_CLI::error('Access denied: the user cannot manage project members.');
		}

		$removed
			? WP_CLI::success(sprintf('User %s removed from project %s.', $user->user_login, $context->publicId()))
			: WP_CLI::warning(sprintf('User %s was not assigned to project %s.', $user->user_login, $context->publicId()));
	}

	private function context(string $publicId, string $capability): ProjectContext
	{
		$guard = $this->plugin->get(ProjectGuard::class);
		$userId = get_current_user_id();

		try {
			return $userId > 0 ? $guard->authorize($publicId, $userId, $capability) : $guard->authorizeSystem($publicId);
		} catch (ProjectNotFound) {
			WP_CLI::error('Project not found.');
		} catch (AccessDenied) {
			WP_CLI::error('Access denied.');
		} catch (Throwable $exception) {
			WP_CLI::error($exception->getMessage());
		}
	}

	private function user(string $identifier): \WP_User
	{
		$user = (new \WP_CLI\Fetchers\User())->get($identifier);

		if (! $user instanceof \WP_User) {
			WP_CLI::error(sprintf('User "%s" not found.', $identifier));
		}

		return $user;
	}
}
