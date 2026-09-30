<?php

declare(strict_types=1);

namespace OsfSeo;

use OsfSeo\Auth\LoginThrottle;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\RoleManager;
use OsfSeo\Auth\WpAdminAccess;
use OsfSeo\Auth\WpRoleStore;
use OsfSeo\Cli\DbCommand;
use OsfSeo\Cli\GoogleCommand;
use OsfSeo\Cli\ProjectCommand;
use OsfSeo\Cli\StatusCommand;
use OsfSeo\Database\Connection;
use OsfSeo\Database\Migrator;
use OsfSeo\Database\SchemaInspector;
use OsfSeo\Google\AccessTokenProvider;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleApi;
use OsfSeo\Google\GoogleConfig;
use OsfSeo\Google\OAuthClient;
use OsfSeo\Google\OAuthFlow;
use OsfSeo\Google\OAuthStateStore;
use OsfSeo\Google\TokenVault;
use OsfSeo\Http\HttpTransport;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Projects\ProjectService;
use OsfSeo\Setup\Installer;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Config;
use OsfSeo\Support\Logger;
use OsfSeo\Support\SystemClock;

final class Plugin
{
	/** Musi być zgodna z nagłówkiem `Version` w osf-seo.php (pilnuje tego test). */
	public const VERSION = '0.5.0';

	public const MIN_PHP = '8.2';

	public const MIN_WP = '6.6';

	private static ?self $instance = null;

	private bool $booted = false;

	public function __construct(
		private readonly string $file,
		private readonly Container $container,
	) {
	}

	public static function instance(): self
	{
		return self::$instance ??= new self(OSF_SEO_FILE, self::createContainer());
	}

	/**
	 * Rejestr usług pluginu — jedyne miejsce, w którym składane są zależności.
	 * Fabryki są leniwe: samo zbudowanie kontenera nie dotyka WordPressa ani bazy.
	 */
	public static function createContainer(): Container
	{
		$container = new Container();

		$container->singleton(Config::class, static fn (): Config => new Config());
		$container->singleton(Clock::class, static fn (): Clock => new SystemClock());
		$container->singleton(Logger::class, static fn (Container $c): Logger => Logger::fromConfig($c->get(Config::class)));
		$container->singleton(Connection::class, static fn (): Connection => Connection::fromGlobals());
		$container->singleton(Migrator::class, static fn (Container $c): Migrator => new Migrator(
			$c->get(Connection::class),
			$c->get(Logger::class),
		));
		$container->singleton(SchemaInspector::class, static fn (Container $c): SchemaInspector => new SchemaInspector($c->get(Connection::class)));
		$container->singleton(RoleManager::class, static fn (): RoleManager => new RoleManager(new WpRoleStore()));
		$container->singleton(Installer::class, static fn (Container $c): Installer => new Installer(
			$c->get(RoleManager::class),
			$c->get(Migrator::class),
			$c->get(Logger::class),
		));
		$container->singleton(ProjectRepository::class, static fn (Container $c): ProjectRepository => new ProjectRepository(
			$c->get(Connection::class),
			$c->get(Clock::class),
		));
		$container->singleton(LoginThrottle::class, static fn (): LoginThrottle => new LoginThrottle());
		$container->singleton(ProjectGuard::class, static fn (Container $c): ProjectGuard => new ProjectGuard($c->get(ProjectRepository::class)));
		$container->singleton(ProjectService::class, static fn (Container $c): ProjectService => new ProjectService(
			$c->get(ProjectRepository::class),
			$c->get(ProjectGuard::class),
			$c->get(Logger::class),
		));

		$container->singleton(HttpTransport::class, static fn (): HttpTransport => new WpHttpTransport());
		$container->singleton(GoogleConfig::class, static fn (Container $c): GoogleConfig => new GoogleConfig(
			$c->get(Config::class),
			home_url(GoogleConfig::CALLBACK_PATH),
		));
		// Klucz czytany przy każdym użyciu (nie jest kopiowany do pól obiektów ani do bazy).
		$container->singleton(TokenVault::class, static fn (Container $c): TokenVault => new TokenVault(
			static fn (): ?string => $c->get(GoogleConfig::class)->encryptionKey(),
		));
		$container->singleton(OAuthClient::class, static fn (Container $c): OAuthClient => new OAuthClient(
			$c->get(GoogleConfig::class),
			$c->get(HttpTransport::class),
		));
		$container->singleton(OAuthStateStore::class, static fn (Container $c): OAuthStateStore => new OAuthStateStore($c->get(Clock::class)));
		$container->singleton(ConnectionRepository::class, static fn (Container $c): ConnectionRepository => new ConnectionRepository(
			$c->get(Connection::class),
			$c->get(Clock::class),
		));
		$container->singleton(AccessTokenProvider::class, static fn (Container $c): AccessTokenProvider => new AccessTokenProvider(
			$c->get(ConnectionRepository::class),
			$c->get(TokenVault::class),
			$c->get(OAuthClient::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));
		$container->singleton(GoogleApi::class, static fn (Container $c): GoogleApi => new GoogleApi(
			$c->get(AccessTokenProvider::class),
			$c->get(HttpTransport::class),
		));
		$container->singleton(OAuthFlow::class, static fn (Container $c): OAuthFlow => new OAuthFlow(
			$c->get(GoogleConfig::class),
			$c->get(OAuthClient::class),
			$c->get(OAuthStateStore::class),
			$c->get(TokenVault::class),
			$c->get(ConnectionRepository::class),
			$c->get(ProjectRepository::class),
			$c->get(ProjectGuard::class),
			$c->get(AccessTokenProvider::class),
			$c->get(Clock::class),
			$c->get(Logger::class),
		));

		return $container;
	}

	public function boot(): void
	{
		if ($this->booted) {
			return;
		}

		$this->booted = true;

		$this->get(Installer::class)->maybeUpgrade();

		WpAdminAccess::register();

		add_action('deleted_user', function (int $userId): void {
			$this->get(ProjectService::class)->forgetDeletedUser($userId);
		});

		if (defined('WP_CLI') && WP_CLI) {
			StatusCommand::register($this);
			DbCommand::register($this);
			ProjectCommand::register($this);
			GoogleCommand::register($this);
		}
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get(string $id): object
	{
		return $this->container->get($id);
	}

	public function logger(): Logger
	{
		return $this->get(Logger::class);
	}

	public function file(): string
	{
		return $this->file;
	}
}
