<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit;

use LogicException;
use OsfSeo\Container;
use OsfSeo\ServiceNotFound;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ContainerTest extends TestCase
{
	public function test_singleton_is_created_lazily_and_shared(): void
	{
		$container = new Container();
		$calls = 0;

		$container->singleton('service', static function () use (&$calls): stdClass {
			$calls++;

			return new stdClass();
		});

		self::assertSame(0, $calls, 'Fabryka nie powinna być wywołana przy rejestracji.');

		$first = $container->get('service');
		$second = $container->get('service');

		self::assertSame($first, $second);
		self::assertSame(1, $calls);
	}

	public function test_factory_receives_container_to_resolve_dependencies(): void
	{
		$container = new Container();
		$container->singleton('dependency', static fn (): stdClass => new stdClass());
		$container->singleton('service', static function (Container $c): stdClass {
			$service = new stdClass();
			$service->dependency = $c->get('dependency');

			return $service;
		});

		self::assertSame($container->get('dependency'), $container->get('service')->dependency);
	}

	public function test_unknown_service_throws(): void
	{
		$this->expectException(ServiceNotFound::class);

		(new Container())->get('missing');
	}

	public function test_has_reports_registered_services(): void
	{
		$container = new Container();
		$container->singleton('service', static fn (): stdClass => new stdClass());

		self::assertTrue($container->has('service'));
		self::assertFalse($container->has('missing'));
	}

	public function test_re_registering_replaces_the_resolved_instance(): void
	{
		$container = new Container();
		$container->singleton('service', static fn (): stdClass => new stdClass());
		$old = $container->get('service');

		$container->singleton('service', static fn (): stdClass => new stdClass());

		self::assertNotSame($old, $container->get('service'));
	}

	public function test_circular_dependency_is_detected(): void
	{
		$container = new Container();
		$container->singleton('a', static fn (Container $c): object => $c->get('b'));
		$container->singleton('b', static fn (Container $c): object => $c->get('a'));

		$this->expectException(LogicException::class);

		$container->get('a');
	}
}
