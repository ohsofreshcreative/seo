<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Google;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\RoleManager;
use OsfSeo\Auth\WpRoleStore;
use OsfSeo\Google\AccessTokenProvider;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleApi;
use OsfSeo\Google\GoogleConfig;
use OsfSeo\Google\GoogleConnection;
use OsfSeo\Google\OAuthClient;
use OsfSeo\Google\OAuthFlow;
use OsfSeo\Google\OAuthStateStore;
use OsfSeo\Google\TokenVault;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Projects\ProjectService;
use OsfSeo\Tests\Integration\IntegrationTestCase;
use OsfSeo\Tests\Support\FrozenClock;
use OsfSeo\Tests\Support\GoogleEnv;
use OsfSeo\Tests\Support\GoogleFakes;

abstract class GoogleTestCase extends IntegrationTestCase
{
	use GoogleEnv;

	protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	protected const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	protected FrozenClock $clock;

	protected GoogleEndpointMock $google;

	protected GoogleConfig $config;

	protected TokenVault $vault;

	protected ConnectionRepository $connections;

	protected ProjectRepository $projects;

	protected ProjectGuard $guard;

	protected ProjectService $service;

	protected AccessTokenProvider $tokens;

	protected OAuthFlow $flow;

	protected GoogleApi $api;

	protected function setUp(): void
	{
		parent::setUp();

		(new RoleManager(new WpRoleStore()))->sync();
		self::freshTables('projects', 'project_users', 'connections');

		$this->clock = new FrozenClock();
		$this->google = new GoogleEndpointMock();
		$this->google->register();
		$this->config = $this->configureGoogle();

		$logger = $this->captureLogger();
		$transport = new WpHttpTransport();
		$config = $this->config;
		$this->vault = new TokenVault(static fn (): ?string => $config->encryptionKey());
		$oauth = new OAuthClient($this->config, $transport);
		$this->connections = new ConnectionRepository(self::db(), $this->clock);
		$this->projects = new ProjectRepository(self::db(), $this->clock);
		$this->guard = new ProjectGuard($this->projects);
		$this->service = new ProjectService($this->projects, $this->guard, $logger);
		$this->tokens = new AccessTokenProvider($this->connections, $this->vault, $oauth, $this->clock, $logger);
		$this->api = new GoogleApi($this->tokens, $transport);
		$this->flow = new OAuthFlow(
			$this->config,
			$oauth,
			new OAuthStateStore($this->clock),
			$this->vault,
			$this->connections,
			$this->projects,
			$this->guard,
			$this->tokens,
			$this->clock,
			$logger,
		);
	}

	protected function tearDown(): void
	{
		$this->google->unregister();
		$this->clearGoogleEnv();
		$db = self::db();
		$db->execute("DELETE FROM `{$db->optionsTable()}` WHERE option_name LIKE %s", ['%osf_seo_oauth_%']);

		parent::tearDown();
	}

	protected function now(): int
	{
		return $this->clock->now()->getTimestamp();
	}

	protected function adminProject(string $name = 'Projekt'): ProjectContext
	{
		$admin = $this->createUser('administrator');

		return $this->service->create(['name' => $name, 'domain' => strtolower($name) . '-' . bin2hex(random_bytes(3)) . '.pl'], $admin);
	}

	/**
	 * @return array<string, string> parametry adresu autoryzacji Google
	 */
	protected static function query(string $url): array
	{
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		return $query;
	}

	/**
	 * Połączenie zapisane bezpośrednio (bez przepływu OAuth) z zaszyfrowanym refresh tokenem.
	 */
	protected function storeConnection(int $ownerUserId, string $refreshToken, string $sub = '1000777'): GoogleConnection
	{
		$id = $this->connections->save(
			$ownerUserId,
			$sub,
			'owner@example.test',
			$this->vault->encrypt($refreshToken, GoogleConnection::vaultContextFor($ownerUserId, $sub)),
			[GoogleConfig::SCOPE_SEARCH_CONSOLE],
		);

		return $this->connections->find($id);
	}

	/**
	 * Miejsca w całej testowej bazie (wszystkie tabele z prefiksem, w tym opcje/transienty), gdzie występuje wartość.
	 *
	 * @return list<string>
	 */
	protected static function databaseOccurrences(string $needle): array
	{
		$db = self::db();
		$found = [];

		foreach ($db->fetchAll('SHOW TABLES LIKE %s', [$db->prefix() . '%']) as $row) {
			$table = (string) array_values($row)[0];

			foreach ($db->fetchAll("SELECT * FROM `{$table}`") as $record) {
				foreach ($record as $column => $value) {
					if ($value !== null && str_contains((string) $value, $needle)) {
						$found[] = $table . '.' . $column;
					}
				}
			}
		}

		return $found;
	}

	protected function assertNotStoredOrLogged(string $secret, string $label): void
	{
		self::assertSame([], self::databaseOccurrences($secret), $label . ' nie może być zapisany w bazie.');
		self::assertStringNotContainsString($secret, implode("\n", $this->logLines), $label . ' nie może trafić do logów.');
	}

	protected function tokenResponse(?string $accessToken = null, ?string $refreshToken = null, ?string $idToken = null, string $scope = ''): array
	{
		return GoogleFakes::tokenResponse($this->now(), $accessToken, $refreshToken, $idToken, $scope);
	}
}
