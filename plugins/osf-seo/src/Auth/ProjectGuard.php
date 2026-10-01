<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

use Closure;
use OsfSeo\Projects\Project;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Support\Ulid;

/**
 * Jedyna brama do projektu: publiczny identyfikator (ULID) + użytkownik → ProjectContext.
 *
 * Reguły:
 * - wymagane `osf_seo_access`,
 * - `osf_seo_view_all_projects` (administrator, osf_seo_admin) widzi wszystkie projekty,
 * - pozostali — wyłącznie projekty przypisane w osf_project_users i niezarchiwizowane,
 * - brak dostępu jest nieodróżnialny od nieistnienia (ProjectNotFound → 404),
 * - brak dodatkowego capability przy widocznym projekcie → AccessDenied (403).
 *
 * Nie ma metody przyjmującej wewnętrzne ID projektu.
 */
final class ProjectGuard
{
	public function __construct(private readonly ProjectRepository $projects)
	{
	}

	/**
	 * @throws ProjectNotFound gdy projekt nie istnieje lub jest niedostępny dla użytkownika
	 * @throws AccessDenied gdy projekt jest widoczny, ale użytkownik nie ma $capability
	 */
	public function authorize(string $publicId, int $userId, string $capability = Capabilities::ACCESS): ProjectContext
	{
		if ($userId <= 0 || ! user_can($userId, Capabilities::ACCESS)) {
			throw new ProjectNotFound();
		}

		$normalized = Ulid::normalize($publicId);

		if ($normalized === null) {
			throw new ProjectNotFound();
		}

		$record = $this->projects->findVisibleByPublicId(
			$normalized,
			$userId,
			user_can($userId, Capabilities::VIEW_ALL_PROJECTS),
		);

		if ($record === null) {
			throw new ProjectNotFound();
		}

		if ($capability !== Capabilities::ACCESS && ! user_can($userId, $capability)) {
			throw new AccessDenied();
		}

		return self::issue($record['project'], $userId, $record['member_role'], false);
	}

	public function authorizeCurrentUser(string $publicId, string $capability = Capabilities::ACCESS): ProjectContext
	{
		return $this->authorize($publicId, get_current_user_id(), $capability);
	}

	/**
	 * Dostęp systemowy (bez użytkownika) — wyłącznie z WP-CLI (operator serwera) i z WP-Cron
	 * (kolejka synchronizacji). Nigdy w żądaniach panelu/REST.
	 *
	 * @throws ProjectNotFound
	 * @throws AccessDenied poza WP-CLI i WP-Cron
	 */
	public function authorizeSystem(string $publicId): ProjectContext
	{
		if (! self::isSystemProcess()) {
			throw new AccessDenied('System access is only available from WP-CLI or WP-Cron.');
		}

		$normalized = Ulid::normalize($publicId);
		$record = $normalized === null ? null : $this->projects->findVisibleByPublicId($normalized, 0, true);

		if ($record === null) {
			throw new ProjectNotFound();
		}

		return self::issue($record['project'], 0, null, true);
	}

	public static function isSystemProcess(): bool
	{
		return (defined('WP_CLI') && WP_CLI) || wp_doing_cron();
	}

	/**
	 * Wydanie kontekstu dla projektu, do którego dostęp został właśnie sprawdzony (np. po utworzeniu).
	 *
	 * @internal tylko dla ProjectService
	 */
	public function contextForCreatedProject(Project $project, int $userId): ProjectContext
	{
		return $userId > 0
			? $this->authorize($project->publicId, $userId)
			: $this->authorizeSystem($project->publicId);
	}

	private static function issue(Project $project, int $userId, ?ProjectRole $memberRole, bool $system): ProjectContext
	{
		// Konstruktor ProjectContext jest prywatny; zakres klasy daje dostęp tylko tej fabryce.
		$factory = Closure::bind(
			static fn (Project $project, int $userId, ?ProjectRole $memberRole, bool $system): ProjectContext => new ProjectContext($project, $userId, $memberRole, $system),
			null,
			ProjectContext::class,
		);

		return $factory($project, $userId, $memberRole, $system);
	}
}
