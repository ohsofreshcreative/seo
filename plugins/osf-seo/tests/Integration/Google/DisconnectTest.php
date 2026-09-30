<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Google;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Tests\Support\GoogleFakes;

final class DisconnectTest extends GoogleTestCase
{
	public function test_disconnect_revokes_at_google_and_removes_connection(): void
	{
		$context = $this->adminProject();
		$refresh = GoogleFakes::refreshToken();
		$connection = $this->storeConnection($context->userId(), $refresh);
		$this->projects->setConnection($context->projectId(), $connection->id);
		$this->google->json(self::REVOKE_URL, 200, []);

		$result = $this->flow->disconnect($this->guard->authorize($context->publicId(), $context->userId()));

		self::assertTrue($result->detached && $result->connectionRemoved && $result->revoked);
		self::assertSame($refresh, $this->google->requestsTo(self::REVOKE_URL)[0]['form']['token']);
		self::assertNull($this->connections->find($connection->id));
		self::assertNull($this->projects->reload($context->project())->connectionId);
		$this->assertNotStoredOrLogged($refresh, 'Refresh token');
		self::assertSame(0, array_sum($this->connections->statusCounts()), 'Brak zaszyfrowanych tokenów po usunięciu.');
	}

	public function test_shared_connection_is_only_detached_until_last_project(): void
	{
		$first = $this->adminProject('Pierwszy');
		$second = $this->service->create(['name' => 'Drugi', 'domain' => 'drugi.pl'], $first->userId());
		$connection = $this->storeConnection($first->userId(), GoogleFakes::refreshToken());
		$this->projects->setConnection($first->projectId(), $connection->id);
		$this->projects->setConnection($second->projectId(), $connection->id);

		$result = $this->flow->disconnect($this->guard->authorize($first->publicId(), $first->userId()));

		self::assertTrue($result->detached);
		self::assertFalse($result->connectionRemoved);
		self::assertSame([], $this->google->requests);
		self::assertNotNull($this->connections->find($connection->id));

		$this->google->json(self::REVOKE_URL, 200, []);
		$result = $this->flow->disconnect($this->guard->authorize($second->publicId(), $first->userId()));

		self::assertTrue($result->connectionRemoved && $result->revoked);
		self::assertNull($this->connections->find($connection->id));
	}

	public function test_failed_revoke_still_removes_local_tokens(): void
	{
		$context = $this->adminProject();
		$connection = $this->storeConnection($context->userId(), GoogleFakes::refreshToken());
		$this->projects->setConnection($context->projectId(), $connection->id);
		$this->google->json(self::REVOKE_URL, 503, ['error' => 'backend_error']);

		$result = $this->flow->disconnect($this->guard->authorize($context->publicId(), $context->userId()));

		self::assertTrue($result->connectionRemoved);
		self::assertFalse($result->revoked);
		self::assertNull($this->connections->find($connection->id));
		self::assertStringContainsString('Could not revoke', implode("\n", $this->logLines));
	}

	public function test_disconnect_without_connection_is_a_no_op(): void
	{
		$context = $this->adminProject();

		$result = $this->flow->disconnect($context);

		self::assertFalse($result->detached);
		self::assertSame([], $this->google->requests);
	}

	public function test_client_cannot_disconnect(): void
	{
		$context = $this->adminProject();
		$connection = $this->storeConnection($context->userId(), GoogleFakes::refreshToken());
		$this->projects->setConnection($context->projectId(), $connection->id);
		$client = $this->createUser('osf_seo_client');
		$this->service->assignUser($context, $client, ProjectRole::Manager);

		try {
			$this->flow->disconnect($this->guard->authorize($context->publicId(), $client));
			self::fail('Klient nie może rozłączać Google.');
		} catch (AccessDenied) {
			self::assertSame($connection->id, $this->projects->reload($context->project())->connectionId);
			self::assertSame([], $this->google->requests);
		}
	}

	public function test_changing_connection_clears_selected_property(): void
	{
		$context = $this->adminProject();
		$first = $this->storeConnection($context->userId(), GoogleFakes::refreshToken(), '1000001');
		$second = $this->storeConnection($context->userId(), GoogleFakes::refreshToken(), '1000002');
		$db = self::db();
		$property = fn (): ?string => $db->fetchValue("SELECT gsc_property FROM `{$db->table('projects')}` WHERE id = %d", [$context->projectId()]);

		$this->projects->setConnection($context->projectId(), $first->id);
		$db->execute("UPDATE `{$db->table('projects')}` SET gsc_property = 'sc-domain:example.pl', gsc_permission = 'siteOwner' WHERE id = %d", [$context->projectId()]);

		$this->projects->setConnection($context->projectId(), $first->id);
		self::assertSame('sc-domain:example.pl', $property(), 'To samo połączenie — property zostaje.');

		$this->projects->setConnection($context->projectId(), $second->id);
		self::assertNull($property(), 'Inne połączenie — property do ponownego wyboru.');
		self::assertSame($second->id, $this->projects->reload($context->project())->connectionId);
	}
}
