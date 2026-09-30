<?php

declare(strict_types=1);

namespace OsfSeo\Auth;

/**
 * Ogranicza zgadywanie haseł w panelu: blokada po MAX_PER_ACCOUNT nieudanych próbach
 * dla pary login+IP oraz po MAX_PER_IP próbach z jednego IP, na WINDOW sekund.
 * Klucze są HMAC-owane (w bazie nie zapisujemy loginów ani adresów IP jawnie).
 */
final class LoginThrottle
{
	public const MAX_PER_ACCOUNT = 5;

	public const MAX_PER_IP = 20;

	public const WINDOW = 900;

	public function isLocked(string $login, string $ip): bool
	{
		return $this->count($this->accountKey($login, $ip)) >= self::MAX_PER_ACCOUNT
			|| $this->count($this->ipKey($ip)) >= self::MAX_PER_IP;
	}

	public function recordFailure(string $login, string $ip): void
	{
		foreach ([$this->accountKey($login, $ip), $this->ipKey($ip)] as $key) {
			set_transient($key, $this->count($key) + 1, self::WINDOW);
		}
	}

	/** Po udanym logowaniu — zeruje licznik konta (licznik IP wygasa sam). */
	public function clear(string $login, string $ip): void
	{
		delete_transient($this->accountKey($login, $ip));
	}

	private function count(string $key): int
	{
		$value = get_transient($key);

		return is_numeric($value) ? (int) $value : 0;
	}

	private function accountKey(string $login, string $ip): string
	{
		return 'osf_seo_login_a_' . $this->hash(strtolower(trim($login)) . '|' . $ip);
	}

	private function ipKey(string $ip): string
	{
		return 'osf_seo_login_i_' . $this->hash($ip);
	}

	private function hash(string $value): string
	{
		return substr(hash_hmac('sha256', $value, wp_salt('auth')), 0, 32);
	}
}
