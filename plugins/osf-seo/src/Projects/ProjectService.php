<?php

declare(strict_types=1);

namespace OsfSeo\Projects;

use InvalidArgumentException;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Support\Logger;
use OsfSeo\Support\ValidationException;

/**
 * Operacje na projektach. Zmiany przyjmują wyłącznie ProjectContext (projekt po autoryzacji)
 * i dodatkowo sprawdzają wymagane capability (obrona w głąb). Komunikaty walidacji — po polsku.
 */
final class ProjectService
{
	/** Aktor systemowy (WP-CLI bez --user). */
	public const SYSTEM_ACTOR = 0;

	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly ProjectGuard $guard,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Projekty widoczne dla użytkownika; bez `osf_seo_access` — pusta lista.
	 *
	 * @return list<Project>
	 */
	public function listFor(int $userId, ?ProjectStatus $status = null): array
	{
		if ($userId <= 0 || ! user_can($userId, Capabilities::ACCESS)) {
			return [];
		}

		return $this->projects->listVisible($userId, user_can($userId, Capabilities::VIEW_ALL_PROJECTS), $status);
	}

	/**
	 * Wszystkie projekty — wyłącznie w WP-CLI (operator serwera).
	 *
	 * @return list<Project>
	 */
	public function listForSystem(?ProjectStatus $status = null): array
	{
		self::assertCli();

		return $this->projects->listVisible(0, true, $status);
	}

	/**
	 * @param array<string, mixed> $input name, domain, country?, language?
	 * @throws ValidationException
	 * @throws AccessDenied
	 */
	public function create(array $input, int $actorId): ProjectContext
	{
		$this->assertActorCan($actorId, Capabilities::MANAGE_PROJECTS);

		$data = $this->validate($input);
		$project = $this->projects->insert($data, $actorId);

		$this->logger->info('Project {project} created by user {user}.', ['project' => $project->publicId, 'user' => $actorId]);

		return $this->guard->contextForCreatedProject($project, $actorId);
	}

	/**
	 * @param array<string, mixed> $input name, domain, country?, language?
	 * @throws ValidationException
	 * @throws AccessDenied
	 */
	public function update(ProjectContext $context, array $input): ProjectContext
	{
		$context->assertCan(Capabilities::MANAGE_PROJECTS);

		$this->projects->update($context->projectId(), $this->validate($input));

		return $context->withProject($this->projects->reload($context->project()));
	}

	/**
	 * @throws AccessDenied
	 */
	public function changeStatus(ProjectContext $context, ProjectStatus $status): ProjectContext
	{
		$context->assertCan(Capabilities::MANAGE_PROJECTS);

		$this->projects->update($context->projectId(), ['status' => $status->value]);
		$this->logger->info('Project {project} status changed to {status} by user {user}.', [
			'project' => $context->publicId(),
			'status' => $status->value,
			'user' => $context->userId(),
		]);

		return $context->withProject($this->projects->reload($context->project()));
	}

	/**
	 * @throws AccessDenied
	 * @throws InvalidArgumentException gdy użytkownik nie istnieje
	 */
	public function assignUser(ProjectContext $context, int $userId, ProjectRole $role = ProjectRole::Viewer): void
	{
		$context->assertCan(Capabilities::MANAGE_USERS);

		if ($userId <= 0 || get_userdata($userId) === false) {
			throw new InvalidArgumentException('User does not exist.');
		}

		$this->projects->assign($context->projectId(), $userId, $role);
		$this->logger->info('User {member} assigned to project {project} as {role}.', [
			'member' => $userId,
			'project' => $context->publicId(),
			'role' => $role->value,
		]);
	}

	/**
	 * @throws AccessDenied
	 */
	public function unassignUser(ProjectContext $context, int $userId): bool
	{
		$context->assertCan(Capabilities::MANAGE_USERS);

		return $this->projects->unassign($context->projectId(), $userId);
	}

	/**
	 * @return list<array{user_id: int, role: ProjectRole}>
	 */
	public function members(ProjectContext $context): array
	{
		$context->assertCan(Capabilities::MANAGE_USERS);

		return $this->projects->members($context->projectId());
	}

	public function forgetDeletedUser(int $userId): void
	{
		$this->projects->removeUserEverywhere($userId);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{name: string, domain: string, country: string, language: string}
	 * @throws ValidationException
	 */
	public function validate(array $input): array
	{
		$errors = [];

		$name = trim(self::string($input['name'] ?? ''));

		if ($name === '') {
			$errors['name'] = 'Podaj nazwę projektu.';
		} elseif (mb_strlen($name) > 190) {
			$errors['name'] = 'Nazwa projektu może mieć maksymalnie 190 znaków.';
		}

		$domain = '';

		try {
			$domain = DomainNormalizer::normalize(self::string($input['domain'] ?? ''));
		} catch (InvalidArgumentException) {
			$errors['domain'] = 'Podaj poprawną domenę, np. example.pl.';
		}

		$country = strtolower(trim(self::string($input['country'] ?? 'pl')));

		if (preg_match('/^[a-z]{2}$/', $country) !== 1) {
			$errors['country'] = 'Kraj podaj jako dwuliterowy kod ISO, np. pl.';
		}

		$language = strtolower(trim(self::string($input['language'] ?? 'pl')));

		if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $language) !== 1) {
			$errors['language'] = 'Język podaj jako kod, np. pl lub pl-pl.';
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		return ['name' => $name, 'domain' => $domain, 'country' => $country, 'language' => $language];
	}

	private function assertActorCan(int $actorId, string $capability): void
	{
		if ($actorId === self::SYSTEM_ACTOR) {
			self::assertCli();

			return;
		}

		if ($actorId < 0 || ! user_can($actorId, $capability)) {
			throw new AccessDenied();
		}
	}

	private static function assertCli(): void
	{
		if (! (defined('WP_CLI') && WP_CLI)) {
			throw new AccessDenied('System access is only available from WP-CLI.');
		}
	}

	private static function string(mixed $value): string
	{
		return is_scalar($value) ? (string) $value : '';
	}
}
