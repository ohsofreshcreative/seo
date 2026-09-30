<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

use OsfSeo\Projects\Project;
use OsfSeo\Projects\ProjectRole;

/**
 * Projekt, do którego dostęp został już sprawdzony. Wyłącznie ProjectGuard tworzy instancje
 * (konstruktor prywatny) — usługi przyjmujące ProjectContext nie muszą i nie mogą przyjmować
 * surowego ID projektu, więc nie da się ich wywołać dla projektu bez autoryzacji.
 */
final class ProjectContext
{
	private function __construct(
		private readonly Project $project,
		private readonly int $userId,
		private readonly ?ProjectRole $memberRole,
		private readonly bool $system,
	) {
	}

	public function project(): Project
	{
		return $this->project;
	}

	/** Wewnętrzne ID — tylko do zapytań w pluginie, nigdy do URL-i. */
	public function projectId(): int
	{
		return $this->project->internalId;
	}

	public function publicId(): string
	{
		return $this->project->publicId;
	}

	public function userId(): int
	{
		return $this->userId;
	}

	public function memberRole(): ?ProjectRole
	{
		return $this->memberRole;
	}

	/** Kontekst systemowy (WP-CLI) — bez użytkownika, z pełnymi uprawnieniami. */
	public function isSystem(): bool
	{
		return $this->system;
	}

	public function can(string $capability): bool
	{
		return $this->system || user_can($this->userId, $capability);
	}

	/**
	 * @throws AccessDenied
	 */
	public function assertCan(string $capability): void
	{
		if (! $this->can($capability)) {
			throw new AccessDenied();
		}
	}

	/** Nowy kontekst z odświeżonym projektem (po zmianie danych) — te same uprawnienia. */
	public function withProject(Project $project): self
	{
		if ($project->internalId !== $this->project->internalId) {
			throw new \LogicException('Cannot swap project in an authorized context.');
		}

		return new self($project, $this->userId, $this->memberRole, $this->system);
	}
}
