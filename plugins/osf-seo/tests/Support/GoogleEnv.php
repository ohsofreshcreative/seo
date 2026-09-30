<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use OsfSeo\Google\GoogleConfig;
use OsfSeo\Support\Config;

/** Konfiguracja Google przez zmienne środowiskowe (z testowymi wartościami) — sprzątana po teście. */
trait GoogleEnv
{
	/** @var list<string> */
	private array $googleEnv = [];

	protected string $clientSecret = '';

	protected function configureGoogle(bool $withSecret = true, ?string $key = null): GoogleConfig
	{
		$this->clientSecret = GoogleFakes::clientSecret();
		$this->setGoogleEnv(GoogleConfig::CLIENT_ID, GoogleFakes::CLIENT_ID);

		if ($withSecret) {
			$this->setGoogleEnv(GoogleConfig::CLIENT_SECRET, $this->clientSecret);
		}

		$this->setGoogleEnv(GoogleConfig::ENCRYPTION_KEY, $key ?? GoogleFakes::encryptionKey());

		return new GoogleConfig(new Config(), 'https://seo.example.test/oauth/google/callback');
	}

	protected function clearGoogleEnv(): void
	{
		foreach ([...$this->googleEnv, GoogleConfig::CLIENT_ID, GoogleConfig::CLIENT_SECRET, GoogleConfig::ENCRYPTION_KEY] as $name) {
			putenv($name);
		}

		$this->googleEnv = [];
	}

	private function setGoogleEnv(string $name, string $value): void
	{
		$this->googleEnv[] = $name;
		putenv($name . '=' . $value);
	}
}
