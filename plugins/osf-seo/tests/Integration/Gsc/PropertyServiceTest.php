<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Gsc;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Google\ConnectionStatus;
use OsfSeo\Gsc\PropertySelectionException;
use OsfSeo\Projects\ProjectRole;
use WP_Error;

final class PropertyServiceTest extends GscTestCase
{
	public function test_lists_domain_and_url_prefix_properties_with_suggestion_but_selects_nothing(): void
	{
		$context = $this->connectedProject('example.pl');
		$this->mockSites([
			['https://www.example.pl/', 'siteFullUser'],
			['sc-domain:example.pl', 'siteOwner'],
			['sc-domain:other-client.pl', 'siteOwner'],
		]);

		$list = $this->properties->properties($context);

		self::assertSame(['https://www.example.pl/', 'sc-domain:example.pl', 'sc-domain:other-client.pl'], array_map(static fn ($p) => $p->siteUrl, $list->properties));
		self::assertSame('sc-domain:example.pl', $list->suggested);
		self::assertFalse($list->hasData);
		self::assertNull($this->projects->reload($context->project())->gscProperty, 'Sugestia nie jest zapisywana bez potwierdzenia.');

		// Bearer tylko do googleapis.com, żądanie zawiera świeży access token.
		$request = $this->google->requestsTo(self::SITES_URL)[0];
		self::assertStringStartsWith('Bearer ya' . '29.test-', $request['headers']['Authorization']);
	}

	public function test_selecting_url_prefix_property_stores_exact_identifier_and_permission(): void
	{
		$context = $this->connectedProject('example.pl');
		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteRestrictedUser']]);

		$selected = $this->properties->select($context, 'https://www.example.pl/');

		self::assertSame('https://www.example.pl/', $selected->project()->gscProperty);
		self::assertSame('siteRestrictedUser', $selected->project()->gscPermission);
		self::assertSame('https://www.example.pl/', $selected->project()->gscDataProperty);
	}

	public function test_selection_is_verified_against_google_not_trusted_from_input(): void
	{
		$context = $this->connectedProject('example.pl');

		foreach (['sc-domain:attacker.pl', 'SC-DOMAIN:example.pl', 'https://www.example.pl', ''] as $tampered) {
			$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);

			try {
				$this->properties->select($context, $tampered);
				self::fail('Wybrano property spoza listy: ' . $tampered);
			} catch (PropertySelectionException $exception) {
				self::assertSame(PropertySelectionException::NOT_FOUND, $exception->reason());
			}
		}

		self::assertNull($this->projects->reload($context->project())->gscProperty);
	}

	public function test_property_without_search_analytics_access_is_rejected(): void
	{
		$context = $this->connectedProject('example.pl');
		$this->mockSites([['sc-domain:example.pl', 'siteUnverifiedUser']]);

		$list = $this->properties->properties($context);
		self::assertNull($list->suggested, 'Niezweryfikowana property nie jest sugerowana.');

		$this->mockSites([['sc-domain:example.pl', 'siteUnverifiedUser']]);
		$this->expectExceptionObject(new PropertySelectionException(PropertySelectionException::INSUFFICIENT_PERMISSION));

		$this->properties->select($context, 'sc-domain:example.pl');
	}

	public function test_account_without_properties(): void
	{
		$context = $this->connectedProject();
		$this->mockSites([]);

		$list = $this->properties->properties($context);

		self::assertSame([], $list->properties);
		self::assertNull($list->suggested);
	}

	public function test_api_error_is_reported_without_changing_the_project(): void
	{
		$context = $this->connectedProject();

		for ($i = 0; $i < 3; $i++) {
			$this->google->json(self::SITES_URL, 503, ['error' => ['code' => 503, 'status' => 'UNAVAILABLE']]);
		}

		try {
			$this->properties->properties($context);
			self::fail('Expected PropertySelectionException.');
		} catch (PropertySelectionException $exception) {
			self::assertSame(PropertySelectionException::API_ERROR, $exception->reason());
			self::assertStringContainsString('chwilowo', $exception->userMessage());
		}

		self::assertCount(3, $this->google->requestsTo(self::SITES_URL), '1 próba + 2 ponowienia.');
		self::assertSame([1.0, 3.0], $this->sleeper->sleeps);

		$this->google->on(self::SITES_URL, new WP_Error('http_request_failed', 'timeout'));
		$this->google->on(self::SITES_URL, new WP_Error('http_request_failed', 'timeout'));
		$this->google->on(self::SITES_URL, new WP_Error('http_request_failed', 'timeout'));
		$this->expectExceptionObject(new PropertySelectionException(PropertySelectionException::API_ERROR));
		$this->properties->properties($context);
	}

	public function test_expired_refresh_token_marks_connection_for_reauthorization(): void
	{
		$context = $this->connectedProject();
		$this->google->json(self::TOKEN_URL, 400, ['error' => 'invalid_grant']);

		try {
			$this->properties->properties($context);
			self::fail('Expected PropertySelectionException.');
		} catch (PropertySelectionException $exception) {
			self::assertSame(PropertySelectionException::CONNECTION_INACTIVE, $exception->reason());
		}

		self::assertSame(ConnectionStatus::NeedsReauth, $this->connections->find($context->project()->connectionId)->status);
		self::assertSame([], $this->google->requestsTo(self::SITES_URL), 'Bez tokenu nie ma zapytania do API.');

		$this->expectExceptionObject(new PropertySelectionException(PropertySelectionException::CONNECTION_INACTIVE));
		$this->properties->select($context, 'sc-domain:example.pl');
	}

	public function test_project_without_connection(): void
	{
		$context = $this->adminProject();

		$this->expectExceptionObject(new PropertySelectionException(PropertySelectionException::NO_CONNECTION));
		$this->properties->properties($context);
	}

	public function test_only_managers_of_visible_projects_can_list_or_select(): void
	{
		$context = $this->connectedProject('example.pl');
		$client = $this->createUser('osf_seo_client');
		$otherClient = $this->createUser('osf_seo_client');
		$this->service->assignUser($context, $client, ProjectRole::Manager);

		$clientContext = $this->guard->authorize($context->publicId(), $client);

		foreach ([fn () => $this->properties->properties($clientContext), fn () => $this->properties->select($clientContext, 'sc-domain:example.pl')] as $call) {
			try {
				$call();
				self::fail('Klient bez osf_seo_manage_connections zmienił/odczytał properties.');
			} catch (AccessDenied) {
			}
		}

		try {
			$this->guard->authorize($context->publicId(), $client, Capabilities::MANAGE_CONNECTIONS);
			self::fail('Expected AccessDenied.');
		} catch (AccessDenied) {
		}

		$this->expectException(ProjectNotFound::class);
		$this->guard->authorize($context->publicId(), $otherClient, Capabilities::MANAGE_CONNECTIONS);
	}

	public function test_changing_property_with_existing_data_requires_explicit_reset(): void
	{
		$context = $this->connectedProject('example.pl');
		$other = $this->connectedProject('other.pl');
		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);
		$context = $this->properties->select($context, 'sc-domain:example.pl');
		$this->seedGscData($context);
		$this->seedGscData($other);

		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);
		$list = $this->properties->properties($context);
		self::assertTrue($list->hasData);
		self::assertSame('sc-domain:example.pl', $list->dataProperty);
		self::assertTrue($list->requiresReset('https://www.example.pl/'));

		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);

		try {
			$this->properties->select($context, 'https://www.example.pl/');
			self::fail('Zmiana property bez resetu wymieszałaby dane.');
		} catch (PropertySelectionException $exception) {
			self::assertSame(PropertySelectionException::RESET_REQUIRED, $exception->reason());
		}

		self::assertSame('sc-domain:example.pl', $this->projects->reload($context->project())->gscProperty);
		self::assertSame(1, self::rowCount('gsc_query_daily', $context->projectId()), 'Bez potwierdzenia dane zostają.');

		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);
		$switched = $this->properties->select($context, 'https://www.example.pl/', true);

		self::assertSame('https://www.example.pl/', $switched->project()->gscProperty);
		self::assertSame('https://www.example.pl/', $switched->project()->gscDataProperty);

		foreach (['gsc_site_daily', 'gsc_query_daily', 'keywords'] as $table) {
			self::assertSame(0, self::rowCount($table, $context->projectId()), "Reset nie usunął {$table}.");
			self::assertSame(1, self::rowCount($table, $other->projectId()), "Reset usunął dane innego projektu ({$table}).");
		}

		self::assertStringContainsString('was reset by user', implode("\n", $this->logLines));
	}

	public function test_reselecting_the_same_property_after_reconnect_keeps_data(): void
	{
		$context = $this->connectedProject('example.pl');
		$this->mockSites([['sc-domain:example.pl', 'siteOwner']]);
		$context = $this->properties->select($context, 'sc-domain:example.pl');
		$this->seedGscData($context);

		// Nowe połączenie Google (inne konto) czyści wybraną property, ale nie pochodzenie danych.
		$connection = $this->storeConnection($context->project()->createdBy, \OsfSeo\Tests\Support\GoogleFakes::refreshToken(), '2000999');
		$this->projects->setConnection($context->projectId(), $connection->id);
		$project = $this->projects->reload($context->project());
		self::assertNull($project->gscProperty);
		self::assertSame('sc-domain:example.pl', $project->gscDataProperty);

		$this->mockSites([['sc-domain:example.pl', 'siteFullUser']]);
		$selected = $this->properties->select($context->withProject($project), 'sc-domain:example.pl');

		self::assertSame('siteFullUser', $selected->project()->gscPermission);
		self::assertSame(1, self::rowCount('gsc_query_daily', $context->projectId()), 'Ta sama property — dane zostają.');
	}

	public function test_data_of_unknown_origin_always_requires_reset(): void
	{
		$context = $this->connectedProject('example.pl');
		$this->seedGscData($context);
		$this->mockSites([['sc-domain:example.pl', 'siteOwner']]);

		$this->expectExceptionObject(new PropertySelectionException(PropertySelectionException::RESET_REQUIRED));
		$this->properties->select($context, 'sc-domain:example.pl');
	}
}
