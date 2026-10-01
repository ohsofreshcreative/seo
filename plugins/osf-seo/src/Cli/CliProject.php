<?php

declare(strict_types=1);

namespace OsfSeo\Cli;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Plugin;
use Throwable;
use WP_CLI;

/**
 * Kontekst projektu dla komend WP-CLI: z globalnym `--user` — uprawnienia i widoczność tego
 * użytkownika, bez `--user` — operator systemu. Nieistniejący i niedostępny projekt dają ten sam błąd.
 */
final class CliProject
{
	/**
	 * @param array<string, string> $assocArgs
	 */
	public static function resolve(Plugin $plugin, array $assocArgs, string $capability): ProjectContext
	{
		$publicId = trim((string) ($assocArgs['project'] ?? ''));

		if ($publicId === '') {
			WP_CLI::error('Missing --project=<public_id>.');
		}

		$guard = $plugin->get(ProjectGuard::class);
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
}
