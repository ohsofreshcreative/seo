<?php

declare(strict_types=1);

namespace OsfSeo;

use OsfSeo\Auth\RoleManager;
use OsfSeo\Auth\WpRoleStore;
use OsfSeo\Cli\StatusCommand;
use OsfSeo\Setup\Installer;
use OsfSeo\Support\Config;
use OsfSeo\Support\Logger;

final class Plugin
{
	/** Musi być zgodna z nagłówkiem `Version` w osf-seo.php (pilnuje tego test). */
	public const VERSION = '0.1.0';

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
	 */
	public static function createContainer(): Container
	{
		$container = new Container();

		$container->singleton(Config::class, static fn (): Config => new Config());
		$container->singleton(Logger::class, static fn (Container $c): Logger => Logger::fromConfig($c->get(Config::class)));
		$container->singleton(RoleManager::class, static fn (): RoleManager => new RoleManager(new WpRoleStore()));
		$container->singleton(Installer::class, static fn (Container $c): Installer => new Installer(
			$c->get(RoleManager::class),
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

		if (defined('WP_CLI') && WP_CLI) {
			StatusCommand::register($this);
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
