<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Projects;

use Error;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Auth\Roles;
use OsfSeo\Projects\Project;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Projects\ProjectStatus;
use OsfSeo\Support\Ulid;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Krytyczny test IDOR: kto widzi który projekt i czy da się obejść autoryzację.
 */
final class ProjectAuthorizationTest extends ProjectsTestCase
{
	private int $admin;

	private int $staff;

	private int $clientA;

	private int $clientB;

	private int $subscriber;

	private Project $projectA;

	private Project $projectB;

	private Project $archivedC;

	protected function setUp(): void
	{
		parent::setUp();

		$this->admin = $this->createUser('administrator');
		$this->staff = $this->createUser(Roles::ADMIN);
		$this->clientA = $this->createUser(Roles::CLIENT);
		$this->clientB = $this->createUser(Roles::CLIENT);
		$this->subscriber = $this->createUser('subscriber');

		$this->projectA = $this->service->create(['name' => 'Projekt A', 'domain' => 'a-example.pl'], $this->admin)->project();
		$this->projectB = $this->service->create(['name' => 'Projekt B', 'domain' => 'b-example.pl'], $this->staff)->project();
		$archived = $this->service->create(['name' => 'Projekt C', 'domain' => 'c-example.pl'], $this->admin);

		$this->service->assignUser($this->guard->authorize($this->projectA->publicId, $this->admin), $this->clientA, ProjectRole::Viewer);
		$this->service->assignUser($this->guard->authorize($this->projectB->publicId, $this->admin), $this->clientB, ProjectRole::Manager);
		$this->service->assignUser($archived, $this->clientA);
		$this->archivedC = $this->service->changeStatus($archived, ProjectStatus::Archived)->project();
	}

	/**
	 * @param list<Project> $projects
	 * @return list<string>
	 */
	private static function names(array $projects): array
	{
		return array_map(static fn (Project $project): string => $project->name, $projects);
	}

	private function assertNotFound(string $publicId, int $userId, string $capability = Capabilities::ACCESS): ProjectNotFound
	{
		try {
			$this->guard->authorize($publicId, $userId, $capability);
		} catch (ProjectNotFound $exception) {
			return $exception;
		}

		self::fail(sprintf('Użytkownik %d uzyskał dostęp do "%s".', $userId, $publicId));
	}

	public function test_admin_and_osf_seo_admin_see_all_projects(): void
	{
		foreach ([$this->admin, $this->staff] as $user) {
			self::assertSame($this->projectA->internalId, $this->guard->authorize($this->projectA->publicId, $user)->projectId());
			self::assertSame($this->projectB->internalId, $this->guard->authorize($this->projectB->publicId, $user)->projectId());
			self::assertSame($this->archivedC->internalId, $this->guard->authorize($this->archivedC->publicId, $user)->projectId());
			self::assertSame(['Projekt A', 'Projekt B'], self::names($this->service->listFor($user)));
			self::assertSame(['Projekt C'], self::names($this->service->listFor($user, ProjectStatus::Archived)));
		}
	}

	public function test_client_a_sees_only_project_a(): void
	{
		$context = $this->guard->authorize($this->projectA->publicId, $this->clientA);

		self::assertSame($this->projectA->internalId, $context->projectId());
		self::assertSame(ProjectRole::Viewer, $context->memberRole());
		self::assertSame(['Projekt A'], self::names($this->service->listFor($this->clientA)));
		$this->assertNotFound($this->projectB->publicId, $this->clientA);
	}

	public function test_client_b_sees_only_project_b(): void
	{
		$context = $this->guard->authorize($this->projectB->publicId, $this->clientB);

		self::assertSame(ProjectRole::Manager, $context->memberRole());
		self::assertSame(['Projekt B'], self::names($this->service->listFor($this->clientB)));
		$this->assertNotFound($this->projectA->publicId, $this->clientB);
	}

	public function test_foreign_project_is_indistinguishable_from_non_existent_one(): void
	{
		$foreign = $this->assertNotFound($this->projectB->publicId, $this->clientA);
		$missing = $this->assertNotFound(Ulid::generate(), $this->clientA);
		$invalid = $this->assertNotFound('not-a-ulid', $this->clientA);

		foreach ([$missing, $invalid] as $other) {
			self::assertSame($foreign::class, $other::class);
			self::assertSame($foreign->getMessage(), $other->getMessage());
			self::assertSame($foreign->getCode(), $other->getCode());
		}

		self::assertSame(404, $foreign->getCode());
		self::assertStringNotContainsString($this->projectB->name, $foreign->getMessage());
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function manipulatedIdentifiers(): iterable
	{
		yield 'numeric id 1' => ['1'];
		yield 'numeric id 2' => ['2'];
		yield 'zero' => ['0'];
		yield 'negative' => ['-1'];
		yield 'sql injection' => ["' OR '1'='1"];
		yield 'sql injection in ulid shape' => ["01ARYZ6S41TSV4RR' OR 1=1 #"];
		yield 'wildcard' => ['%'];
		yield 'empty' => [''];
		yield 'array-like' => ['[]'];
	}

	#[DataProvider('manipulatedIdentifiers')]
	public function test_raw_or_manipulated_identifiers_never_resolve(string $identifier): void
	{
		foreach ([$this->clientA, $this->clientB, $this->admin] as $user) {
			$this->assertNotFound($identifier, $user);
		}
	}

	public function test_internal_ids_of_foreign_projects_do_not_work_as_public_ids(): void
	{
		foreach ([$this->projectA, $this->projectB, $this->archivedC] as $project) {
			$this->assertNotFound((string) $project->internalId, $this->clientA);
			$this->assertNotFound((string) $project->internalId, $this->admin);
		}
	}

	public function test_public_id_case_and_whitespace_do_not_bypass_membership(): void
	{
		$this->assertNotFound(strtolower($this->projectB->publicId), $this->clientA);
		$this->assertNotFound(' ' . $this->projectB->publicId . ' ', $this->clientA);

		// Dla uprawnionego klienta normalizacja działa.
		self::assertSame($this->projectA->internalId, $this->guard->authorize(strtolower($this->projectA->publicId), $this->clientA)->projectId());
	}

	public function test_archived_project_is_hidden_from_assigned_client(): void
	{
		$this->assertNotFound($this->archivedC->publicId, $this->clientA);
		self::assertSame([], $this->service->listFor($this->clientA, ProjectStatus::Archived));
	}

	public function test_user_without_panel_access_sees_nothing(): void
	{
		$this->assertNotFound($this->projectA->publicId, $this->subscriber);
		self::assertSame([], $this->service->listFor($this->subscriber));

		// Nawet przypisanie w project_users nie daje dostępu bez osf_seo_access.
		$this->service->assignUser($this->guard->authorize($this->projectA->publicId, $this->admin), $this->subscriber);
		$this->assertNotFound($this->projectA->publicId, $this->subscriber);
		self::assertSame([], $this->service->listFor($this->subscriber));
	}

	public function test_anonymous_user_sees_nothing(): void
	{
		$this->assertNotFound($this->projectA->publicId, 0);
		self::assertSame([], $this->service->listFor(0));
	}

	public function test_removing_membership_revokes_access_immediately(): void
	{
		$this->service->unassignUser($this->guard->authorize($this->projectA->publicId, $this->admin), $this->clientA);

		$this->assertNotFound($this->projectA->publicId, $this->clientA);
		self::assertSame([], $this->service->listFor($this->clientA));
	}

	public function test_deleted_wordpress_user_loses_memberships(): void
	{
		osf_seo()->boot();
		wp_delete_user($this->clientB);

		$members = $this->service->members($this->guard->authorize($this->projectB->publicId, $this->admin));

		self::assertSame([], array_values(array_filter($members, fn (array $m): bool => $m['user_id'] === $this->clientB)));
	}

	public function test_visible_project_without_capability_is_access_denied_not_hidden(): void
	{
		$this->expectException(AccessDenied::class);

		$this->guard->authorize($this->projectA->publicId, $this->clientA, Capabilities::MANAGE_PROJECTS);
	}

	public function test_invisible_project_with_higher_capability_request_is_still_not_found(): void
	{
		// Najpierw widoczność, potem uprawnienie — obca strona nie może dostać 403 (ujawnienie istnienia).
		$this->assertNotFound($this->projectB->publicId, $this->clientA, Capabilities::MANAGE_PROJECTS);
	}

	public function test_client_cannot_modify_own_project_through_service(): void
	{
		$context = $this->guard->authorize($this->projectA->publicId, $this->clientA);

		$attempts = [
			'update' => fn () => $this->service->update($context, ['name' => 'Przejęty', 'domain' => 'evil.pl']),
			'archive' => fn () => $this->service->changeStatus($context, ProjectStatus::Archived),
			'assign' => fn () => $this->service->assignUser($context, $this->clientB),
			'unassign' => fn () => $this->service->unassignUser($context, $this->clientA),
			'members' => fn () => $this->service->members($context),
		];

		foreach ($attempts as $name => $attempt) {
			try {
				$attempt();
				self::fail("Klient wykonał operację {$name}.");
			} catch (AccessDenied) {
			}
		}

		self::assertSame('Projekt A', $this->guard->authorize($this->projectA->publicId, $this->admin)->project()->name);
	}

	public function test_clients_and_subscribers_cannot_create_projects(): void
	{
		foreach ([$this->clientA, $this->subscriber, 0] as $user) {
			try {
				$this->service->create(['name' => 'X', 'domain' => 'x-example.pl'], $user);
				self::fail("Użytkownik {$user} utworzył projekt.");
			} catch (AccessDenied) {
			}
		}

		self::assertCount(2, $this->service->listFor($this->admin));
	}

	public function test_system_access_is_unavailable_outside_cli(): void
	{
		$this->expectException(AccessDenied::class);

		$this->guard->authorizeSystem($this->projectA->publicId);
	}

	public function test_system_access_is_available_to_wp_cron_jobs(): void
	{
		$cron = static fn (): bool => true;
		add_filter('wp_doing_cron', $cron);

		try {
			$context = $this->guard->authorizeSystem($this->projectA->publicId);
		} finally {
			remove_filter('wp_doing_cron', $cron);
		}

		self::assertTrue($context->isSystem());
		self::assertSame($this->projectA->publicId, $context->publicId());

		$this->expectException(AccessDenied::class);
		$this->guard->authorizeSystem($this->projectA->publicId);
	}

	public function test_project_context_cannot_be_constructed_outside_the_guard(): void
	{
		$constructor = (new ReflectionClass(ProjectContext::class))->getConstructor();

		self::assertNotNull($constructor);
		self::assertTrue($constructor->isPrivate());

		$this->expectException(Error::class);

		new ProjectContext($this->projectB, $this->clientA, null, true);
	}
}
