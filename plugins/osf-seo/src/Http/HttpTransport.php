<?php

declare(strict_types=1);

namespace OsfSeo\Http;

/**
 * Wysyłka żądań HTTP. Produkcyjnie WP HTTP API (WpHttpTransport); w testach podmieniane
 * (jednostkowe: fałszywy transport, integracyjne: filtr `pre_http_request`).
 */
interface HttpTransport
{
	/**
	 * @param array<string, string> $headers
	 *
	 * @throws TransportException błąd sieci (brak odpowiedzi HTTP)
	 */
	public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
