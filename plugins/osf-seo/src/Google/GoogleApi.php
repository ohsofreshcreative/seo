<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use InvalidArgumentException;
use OsfSeo\Http\HttpResponse;
use OsfSeo\Http\HttpTransport;

/**
 * Autoryzowane żądania do API Google w imieniu połączenia. Po 401 odświeża access token
 * i ponawia żądanie dokładnie raz. Token Bearer trafia wyłącznie do https://*.googleapis.com.
 */
final class GoogleApi implements ApiRequester
{
	public function __construct(
		private readonly AccessTokenProvider $tokens,
		private readonly HttpTransport $http,
	) {
	}

	/**
	 * @param array<string, mixed>|null $json treść JSON (POST)
	 */
	public function request(GoogleConnection $connection, string $method, string $url, ?array $json = null): HttpResponse
	{
		self::assertGoogleApiUrl($url);

		$response = $this->send($this->tokens->token($connection), $method, $url, $json);

		if ($response->status === 401) {
			$response = $this->send($this->tokens->refresh($connection), $method, $url, $json);
		}

		return $response;
	}

	public static function assertGoogleApiUrl(string $url): void
	{
		$parts = parse_url($url);
		$host = strtolower((string) ($parts['host'] ?? ''));

		if (($parts['scheme'] ?? '') !== 'https' || ! str_ends_with($host, '.googleapis.com') || isset($parts['user']) || isset($parts['port'])) {
			throw new InvalidArgumentException('Google API requests are allowed only to https://*.googleapis.com.');
		}
	}

	/**
	 * @param array<string, mixed>|null $json
	 */
	private function send(#[\SensitiveParameter] string $accessToken, string $method, string $url, ?array $json): HttpResponse
	{
		$headers = ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'];

		if ($json !== null) {
			$headers['Content-Type'] = 'application/json';
		}

		return $this->http->request($method, $url, $headers, $json === null ? null : (string) json_encode($json));
	}
}
