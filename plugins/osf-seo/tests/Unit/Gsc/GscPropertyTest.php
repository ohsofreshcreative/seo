<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Gsc;

use InvalidArgumentException;
use OsfSeo\Gsc\GscProperty;
use OsfSeo\Gsc\PermissionLevel;
use OsfSeo\Gsc\PropertyList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GscPropertyTest extends TestCase
{
	public function test_domain_property_keeps_exact_identifier(): void
	{
		$property = GscProperty::fromApi(['siteUrl' => 'sc-domain:example.pl', 'permissionLevel' => 'siteOwner']);

		self::assertSame('sc-domain:example.pl', $property->siteUrl);
		self::assertTrue($property->isDomainProperty());
		self::assertSame('Domena', $property->typeLabel());
		self::assertSame('example.pl', $property->host());
		self::assertSame(PermissionLevel::Owner, $property->permission());
		self::assertTrue($property->canQuerySearchAnalytics());
	}

	public function test_url_prefix_property_keeps_exact_identifier(): void
	{
		$property = GscProperty::fromApi(['siteUrl' => 'https://www.Example.pl/', 'permissionLevel' => 'siteFullUser']);

		self::assertSame('https://www.Example.pl/', $property->siteUrl, 'Identyfikator Google bez normalizacji.');
		self::assertFalse($property->isDomainProperty());
		self::assertSame('Prefiks URL', $property->typeLabel());
		self::assertSame('example.pl', $property->host());
	}

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function permissions(): iterable
	{
		yield 'owner' => ['siteOwner', true];
		yield 'full user' => ['siteFullUser', true];
		yield 'restricted user' => ['siteRestrictedUser', true];
		yield 'unverified user' => ['siteUnverifiedUser', false];
		yield 'unknown future value' => ['siteSomethingNew', false];
	}

	#[DataProvider('permissions')]
	public function test_search_analytics_access_depends_on_permission(string $permission, bool $allowed): void
	{
		self::assertSame($allowed, (new GscProperty('sc-domain:example.pl', $permission))->canQuerySearchAnalytics());
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function invalidEntries(): iterable
	{
		yield 'missing siteUrl' => [['permissionLevel' => 'siteOwner']];
		yield 'missing permission' => [['siteUrl' => 'sc-domain:example.pl']];
		yield 'non-string siteUrl' => [['siteUrl' => 123, 'permissionLevel' => 'siteOwner']];
		yield 'ftp scheme' => [['siteUrl' => 'ftp://example.pl/', 'permissionLevel' => 'siteOwner']];
		yield 'url prefix without trailing slash' => [['siteUrl' => 'https://example.pl', 'permissionLevel' => 'siteOwner']];
		yield 'whitespace' => [['siteUrl' => 'https://exa mple.pl/', 'permissionLevel' => 'siteOwner']];
		yield 'too long for column' => [['siteUrl' => 'https://example.pl/' . str_repeat('a', 250) . '/', 'permissionLevel' => 'siteOwner']];
		yield 'invalid domain property' => [['siteUrl' => 'sc-domain:exa/mple.pl', 'permissionLevel' => 'siteOwner']];
		yield 'invalid permission token' => [['siteUrl' => 'sc-domain:example.pl', 'permissionLevel' => '<b>owner</b>']];
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	#[DataProvider('invalidEntries')]
	public function test_invalid_entries_are_rejected(array $entry): void
	{
		$this->expectException(InvalidArgumentException::class);

		GscProperty::fromApi($entry);
	}

	public function test_suggestion_prefers_domain_property_then_https_root(): void
	{
		$properties = [
			new GscProperty('http://example.pl/', 'siteOwner'),
			new GscProperty('https://www.example.pl/blog/', 'siteOwner'),
			new GscProperty('https://www.example.pl/', 'siteOwner'),
			new GscProperty('sc-domain:example.pl', 'siteRestrictedUser'),
			new GscProperty('sc-domain:other.pl', 'siteOwner'),
		];

		self::assertSame('sc-domain:example.pl', PropertyList::suggest($properties, 'example.pl'));
		self::assertSame('https://www.example.pl/', PropertyList::suggest(array_slice($properties, 0, 3), 'example.pl'));
		self::assertSame('http://example.pl/', PropertyList::suggest([$properties[0], $properties[1]], 'example.pl'));
	}

	public function test_suggestion_never_points_to_unusable_or_foreign_property(): void
	{
		self::assertNull(PropertyList::suggest([new GscProperty('sc-domain:example.pl', 'siteUnverifiedUser')], 'example.pl'));
		self::assertNull(PropertyList::suggest([new GscProperty('sc-domain:blog.example.pl', 'siteOwner')], 'example.pl'));
		self::assertNull(PropertyList::suggest([new GscProperty('sc-domain:example.pl.evil.test', 'siteOwner')], 'example.pl'));
		self::assertNull(PropertyList::suggest([], 'example.pl'));
	}

	public function test_property_list_reset_rules(): void
	{
		$properties = [new GscProperty('sc-domain:example.pl', 'siteOwner'), new GscProperty('https://example.pl/', 'siteOwner')];

		$withData = new PropertyList($properties, null, true, 'sc-domain:example.pl');
		self::assertFalse($withData->requiresReset('sc-domain:example.pl'));
		self::assertTrue($withData->requiresReset('https://example.pl/'));

		$unknownSource = new PropertyList($properties, null, true, null);
		self::assertTrue($unknownSource->requiresReset('sc-domain:example.pl'), 'Dane o nieznanym pochodzeniu — zawsze reset.');

		$noData = new PropertyList($properties, null, false, 'sc-domain:example.pl');
		self::assertFalse($noData->requiresReset('https://example.pl/'));
		self::assertSame($properties[1], $noData->find('https://example.pl/'));
		self::assertNull($noData->find('https://example.pl'));
	}

	public function test_permission_labels(): void
	{
		self::assertSame('Właściciel', PermissionLevel::labelFor('siteOwner'));
		self::assertSame('siteNew', PermissionLevel::labelFor('siteNew'));
		self::assertSame('—', PermissionLevel::labelFor(null));
	}
}
