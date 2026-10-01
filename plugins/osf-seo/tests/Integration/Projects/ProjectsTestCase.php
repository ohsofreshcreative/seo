<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Projects;

use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\RoleManager;
use OsfSeo\Auth\WpRoleStore;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Projects\ProjectService;
use OsfSeo\Support\SystemClock;
use OsfSeo\Tests\Integration\IntegrationTestCase;

abstract class ProjectsTestCase extends IntegrationTestCase
{
	protected ProjectRepository $repository;

	protected ProjectGuard $guard;

	protected ProjectService $service;

	protected function setUp(): void
	{
		parent::setUp();

		(new RoleManager(new WpRoleStore()))->sync();
		self::freshTables('projects', 'project_users');

		$this->repository = new ProjectRepository(self::db(), new SystemClock());
		$this->guard = new ProjectGuard($this->repository);
		$this->service = new ProjectService($this->repository, $this->guard, $this->captureLogger());
	}
}
