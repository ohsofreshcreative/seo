<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Support\Config;

/**
 * Konfiguracja integracji z Google (stałe w wp-config.php albo zmienne środowiskowe).
 * Wartości sekretów nigdy nie są logowane ani pokazywane — raporty podają tylko nazwy brakujących stałych.
 */
final class GoogleConfig
{
	public const CLIENT_ID = 'OSF_SEO_GOOGLE_CLIENT_ID';

	public const CLIENT_SECRET = 'OSF_SEO_GOOGLE_CLIENT_SECRET';

	public const ENCRYPTION_KEY = 'OSF_SEO_ENCRYPTION_KEY';

	/** Tylko odczyt danych Search Console; `openid email` wyłącznie do identyfikacji konta Google. */
	public const SCOPE_SEARCH_CONSOLE = 'https://www.googleapis.com/auth/webmasters.readonly';

	public const SCOPES = [self::SCOPE_SEARCH_CONSOLE, 'openid', 'email'];

	public const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

	public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	public const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

	/** Ścieżka callbacku względem home_url() — musi być zgodna z trasą panelu i wpisem w Google Cloud. */
	public const CALLBACK_PATH = '/oauth/google/callback';

	public function __construct(
		private readonly Config $config,
		private readonly string $redirectUri,
	) {
	}

	public function clientId(): string
	{
		return $this->require(self::CLIENT_ID);
	}

	public function clientSecret(): string
	{
		return $this->require(self::CLIENT_SECRET);
	}

	public function encryptionKey(): ?string
	{
		return $this->value(self::ENCRYPTION_KEY);
	}

	public function redirectUri(): string
	{
		return $this->redirectUri;
	}

	/**
	 * Nazwy brakujących stałych (bez wartości).
	 *
	 * @return list<string>
	 */
	public function missing(): array
	{
		return array_values(array_filter(
			[self::CLIENT_ID, self::CLIENT_SECRET, self::ENCRYPTION_KEY],
			fn (string $name): bool => $this->value($name) === null,
		));
	}

	/**
	 * Błędy konfiguracji poza brakującymi stałymi: zły format klucza, brak libsodium (bez wartości sekretów).
	 *
	 * @return list<string>
	 */
	public function errors(): array
	{
		$errors = [];
		$key = $this->encryptionKey();

		if ($key !== null && ($keyProblem = TokenVault::keyProblem($key)) !== null) {
			$errors[] = $keyProblem;
		}

		if (! TokenVault::isSupported()) {
			$errors[] = 'libsodium (XChaCha20-Poly1305) is not available';
		}

		return $errors;
	}

	/**
	 * Wszystkie problemy blokujące integrację.
	 *
	 * @return list<string>
	 */
	public function problems(): array
	{
		return [...array_map(static fn (string $name): string => $name . ' is not set', $this->missing()), ...$this->errors()];
	}

	public function isConfigured(): bool
	{
		return $this->problems() === [];
	}

	private function value(string $name): ?string
	{
		$value = $this->config->get($name);

		return $value === null || trim($value) === '' ? null : trim($value);
	}

	private function require(string $name): string
	{
		return $this->value($name) ?? throw new GoogleNotConfigured($name . ' is not set.');
	}
}
