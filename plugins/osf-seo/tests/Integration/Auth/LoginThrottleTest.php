<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Auth;

use OsfSeo\Auth\LoginThrottle;
use OsfSeo\Tests\Integration\IntegrationTestCase;

final class LoginThrottleTest extends IntegrationTestCase
{
	private LoginThrottle $throttle;

	private string $ip;

	protected function setUp(): void
	{
		parent::setUp();

		$this->throttle = osf_seo()->get(LoginThrottle::class);
		// Unikalny adres (TEST-NET-3) na test — liczniki z innych testów nie przeszkadzają.
		$this->ip = '203.0.113.' . random_int(1, 254) . '-' . bin2hex(random_bytes(3));
	}

	protected function tearDown(): void
	{
		$db = self::db();
		$db->execute("DELETE FROM `{$db->optionsTable()}` WHERE option_name LIKE %s", ['%osf_seo_login_%']);
		wp_cache_flush();

		parent::tearDown();
	}

	public function test_account_is_locked_after_max_failures_for_the_same_login_and_ip(): void
	{
		for ($i = 1; $i < LoginThrottle::MAX_PER_ACCOUNT; $i++) {
			$this->throttle->recordFailure('klient', $this->ip);
		}

		self::assertFalse($this->throttle->isLocked('klient', $this->ip));

		$this->throttle->recordFailure('klient', $this->ip);

		self::assertTrue($this->throttle->isLocked('klient', $this->ip));
		self::assertTrue($this->throttle->isLocked('  KLIENT ', $this->ip), 'Login porównywany bez wielkości liter i spacji.');
		self::assertFalse($this->throttle->isLocked('klient', $this->ip . '-inny'), 'Blokada konta dotyczy pary login + IP.');
		self::assertFalse($this->throttle->isLocked('inny-login', $this->ip));
	}

	public function test_clear_resets_the_account_counter(): void
	{
		for ($i = 0; $i < LoginThrottle::MAX_PER_ACCOUNT; $i++) {
			$this->throttle->recordFailure('klient', $this->ip);
		}

		$this->throttle->clear('klient', $this->ip);

		self::assertFalse($this->throttle->isLocked('klient', $this->ip));
	}

	public function test_ip_is_locked_after_max_failures_across_logins(): void
	{
		for ($i = 0; $i < LoginThrottle::MAX_PER_IP; $i++) {
			$this->throttle->recordFailure('login-' . $i, $this->ip);
		}

		self::assertTrue($this->throttle->isLocked('zupelnie-nowy-login', $this->ip));
		self::assertFalse($this->throttle->isLocked('zupelnie-nowy-login', $this->ip . '-inny'));
	}

	public function test_counters_do_not_store_login_or_ip_in_plain_text(): void
	{
		$login = 'jawny-login-' . bin2hex(random_bytes(4));
		$this->throttle->recordFailure($login, $this->ip);

		$db = self::db();
		$names = $db->fetchAll("SELECT option_name, option_value FROM `{$db->optionsTable()}` WHERE option_name LIKE %s", ['%osf_seo_login_%']);

		self::assertNotEmpty($names);

		foreach ($names as $row) {
			self::assertStringNotContainsString($login, $row['option_name'] . $row['option_value']);
			self::assertStringNotContainsString($this->ip, $row['option_name'] . $row['option_value']);
		}
	}
}
