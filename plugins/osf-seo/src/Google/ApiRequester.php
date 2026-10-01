<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Http\HttpResponse;

/**
 * Autoryzowane żądanie do API Google w imieniu połączenia (implementacja: GoogleApi).
 * Interfejs pozwala testować klientów API (np. Search Console) bez bazy i tokenów.
 */
interface ApiRequester
{
	/**
	 * @param array<string, mixed>|null $json treść JSON (POST)
	 *
	 * @throws ReauthorizationRequired
	 * @throws VaultException
	 * @throws OAuthEndpointError
	 * @throws \OsfSeo\Http\TransportException
	 */
	public function request(GoogleConnection $connection, string $method, string $url, ?array $json = null): HttpResponse;
}
