<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Http\HttpTransport;

/**
 * Endpointy OAuth 2.0 Google: adres autoryzacji (PKCE S256), wymiana kodu, odświeżenie
 * access tokenu i unieważnienie tokenu. Sekret klienta wysyłany w treści POST (client_secret_post).
 */
final class OAuthClient
{
	public function __construct(
		private readonly GoogleConfig $config,
		private readonly HttpTransport $http,
	) {
	}

	public function authorizationUrl(#[\SensitiveParameter] string $state, string $codeChallenge): string
	{
		return GoogleConfig::AUTHORIZATION_ENDPOINT . '?' . http_build_query([
			'client_id' => $this->config->clientId(),
			'redirect_uri' => $this->config->redirectUri(),
			'response_type' => 'code',
			'scope' => implode(' ', GoogleConfig::SCOPES),
			'access_type' => 'offline',
			'prompt' => 'consent',
			'state' => $state,
			'code_challenge' => $codeChallenge,
			'code_challenge_method' => 'S256',
		], '', '&', PHP_QUERY_RFC3986);
	}

	public function exchangeCode(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $codeVerifier): TokenResponse
	{
		return $this->tokenRequest([
			'grant_type' => 'authorization_code',
			'code' => $code,
			'code_verifier' => $codeVerifier,
			'redirect_uri' => $this->config->redirectUri(),
		]);
	}

	public function refreshAccessToken(#[\SensitiveParameter] string $refreshToken): TokenResponse
	{
		return $this->tokenRequest([
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
		]);
	}

	/**
	 * Unieważnia token (refresh token unieważnia całe powiązane nadanie dostępu).
	 * Token już nieważny (`invalid_token`) traktujemy jako sukces.
	 */
	public function revoke(#[\SensitiveParameter] string $token): void
	{
		$response = $this->http->request('POST', GoogleConfig::REVOKE_ENDPOINT, [
			'Content-Type' => 'application/x-www-form-urlencoded',
		], http_build_query(['token' => $token], '', '&'));

		if ($response->isSuccess()) {
			return;
		}

		$error = OAuthEndpointError::fromResponse($response->status, $response->json());

		if ($error->error() !== 'invalid_token') {
			throw $error;
		}
	}

	/**
	 * @param array<string, string> $params
	 */
	private function tokenRequest(#[\SensitiveParameter] array $params): TokenResponse
	{
		$params['client_id'] = $this->config->clientId();
		$params['client_secret'] = $this->config->clientSecret();

		$response = $this->http->request('POST', GoogleConfig::TOKEN_ENDPOINT, [
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Accept' => 'application/json',
		], http_build_query($params, '', '&'));

		$json = $response->json();

		if (! $response->isSuccess()) {
			throw OAuthEndpointError::fromResponse($response->status, $json);
		}

		if (! is_array($json) || ! isset($json['access_token']) || ! is_string($json['access_token']) || $json['access_token'] === '') {
			throw new OAuthEndpointError($response->status, 'invalid_response');
		}

		return TokenResponse::fromArray($json);
	}
}
