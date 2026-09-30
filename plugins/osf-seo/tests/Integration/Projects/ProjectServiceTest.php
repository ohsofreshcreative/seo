<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Projects;

use OsfSeo\Auth\Roles;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Projects\ProjectStatus;
use OsfSeo\Support\Ulid;
use OsfSeo\Support\ValidationException;

final class ProjectServiceTest extends ProjectsTestCase
{
	private int $admin;

	protected function setUp(): void
	{
		parent::setUp();
		$this->admin = $this->createUser('administrator');
	}

	public function test_create_normalizes_input_and_assigns_ulid(): void
	{
		$before = time();
		$project = $this->service->create([
			'name' => '  OhSoFresh  ',
			'domain' => 'https://www.OhSoFresh.pl/oferta/',
			'country' => 'PL',
			'language' => 'PL',
		], $this->admin)->project();

		self::assertTrue(Ulid::isValid($project->publicId));
		self::assertSame('OhSoFresh', $project->name);
		self::assertSame('ohsofresh.pl', $project->domain);
		self::assertSame('pl', $project->country);
		self::assertSame('pl', $project->language);
		self::assertSame(ProjectStatus::Active, $project->status);
		self::assertSame($this->admin, $project->createdBy);
		self::assertNull($project->connectionId);
		self::assertEqualsWithDelta($before, $project->createdAt->getTimestamp(), 5);
	}

	public function test_validation_errors_are_reported_per_field_in_polish(): void
	{
		try {
			$this->service->create(['name' => '', 'domain' => '192.168.0.1', 'country' => 'pol', 'language' => 'polski'], $this->admin);
			self::fail('Oczekiwano błędów walidacji.');
		} catch (ValidationException $exception) {
			self::assertSame([
				'name' => 'Podaj nazwę projektu.',
				'domain' => 'Podaj poprawną domenę, np. example.pl.',
				'country' => 'Kraj podaj jako dwuliterowy kod ISO, np. pl.',
				'language' => 'Język podaj jako kod, np. pl lub pl-pl.',
			], $exception->errors());
		}

		self::assertSame([], $this->service->listFor($this->admin));
	}

	public function test_html_in_name_is_stored_verbatim_and_escaping_is_left_to_views(): void
	{
		$project = $this->service->create(['name' => '<b>Klient</b> & "Spółka"', 'domain' => 'klient.pl'], $this->admin)->project();

		self::assertSame('<b>Klient</b> & "Spółka"', $project->name);
	}

	public function test_update_changes_fields_and_keeps_public_id(): void
	{
		$context = $this->service->create(['name' => 'Stara nazwa', 'domain' => 'stara.pl'], $this->admin);

		$updated = $this->service->update($context, ['name' => 'Nowa nazwa', 'domain' => 'nowa.pl', 'country' => 'de', 'language' => 'de-de']);

		self::assertSame($context->publicId(), $updated->publicId());
		self::assertSame('Nowa nazwa', $updated->project()->name);
		self::assertSame('nowa.pl', $updated->project()->domain);
		self::assertSame('de', $updated->project()->country);
		self::assertSame('de-de', $updated->project()->language);
	}

	public function test_status_transitions_and_filtering(): void
	{
		$context = $this->service->create(['name' => 'Projekt', 'domain' => 'projekt.pl'], $this->admin);

		self::assertSame(ProjectStatus::Paused, $this->service->changeStatus($context, ProjectStatus::Paused)->project()->status);
		self::assertCount(1, $this->service->listFor($this->admin), 'Wstrzymany projekt jest na liście domyślnej.');

		$archived = $this->service->changeStatus($context, ProjectStatus::Archived);
		self::assertSame([], $this->service->listFor($this->admin));
		self::assertSame([$context->publicId()], array_map(static fn ($p) => $p->publicId, $this->service->listFor($this->admin, ProjectStatus::Archived)));

		self::assertSame(ProjectStatus::Active, $this->service->changeStatus($archived, ProjectStatus::Active)->project()->status);
		self::assertCount(1, $this->service->listFor($this->admin));
	}

	public function test_members_can_be_assigned_with_roles_and_reassigned(): void
	{
		$context = $this->service->create(['name' => 'Projekt', 'domain' => 'projekt.pl'], $this->admin);
		$client = $this->createUser(Roles::CLIENT);

		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$this->service->assignUser($context, $client, ProjectRole::Manager);

		self::assertSame([['user_id' => $client, 'role' => ProjectRole::Manager]], $this->service->members($context));
		self::assertTrue($this->service->unassignUser($context, $client));
		self::assertFalse($this->service->unassignUser($context, $client));
		self::assertSame([], $this->service->members($context));
	}

	public function test_assigning_non_existent_user_is_rejected(): void
	{
		$context = $this->service->create(['name' => 'Projekt', 'domain' => 'projekt.pl'], $this->admin);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->assignUser($context, 999999);
	}

	public function test_listing_is_sorted_by_name(): void
	{
		foreach (['Zeta', 'alfa', 'Beta'] as $name) {
			$this->service->create(['name' => $name, 'domain' => strtolower($name) . '.pl'], $this->admin);
		}

		self::assertSame(['alfa', 'Beta', 'Zeta'], array_map(static fn ($p) => $p->name, $this->service->listFor($this->admin)));
	}
}
