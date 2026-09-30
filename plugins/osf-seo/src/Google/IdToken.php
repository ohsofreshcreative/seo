<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Support\Base64Url;

/**
 * Odczyt tożsamości (sub, email) z id_token.
 *
 * Token pochodzi bezpośrednio z endpointu tokenów Google przez TLS (wymiana kodu po stronie serwera),
 * więc zgodnie z OpenID Connect Core 1.0, §3.1.3.7 pkt 6 walidacja TLS zastępuje weryfikację podpisu.
 * Sprawdzamy wydawcę, odbiorcę (nasz client_id) i czas ważności.
 */
final class IdToken
{
	private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

	private const CLOCK_SKEW = 300;

	/**
	 * @return array{sub: string, email: string}
	 *
	 * @throws OAuthFlowException id_token_invalid
	 */
	public static function identity(#[\SensitiveParameter] string $jwt, string $clientId, int $now): array
	{
		$parts = explode('.', $jwt);
		$payload = count($parts) === 3 ? Base64Url::decode($parts[1]) : null;
		$claims = $payload === null ? null : json_decode($payload, true);

		if (! is_array($claims)) {
			throw new OAuthFlowException(OAuthFlowException::ID_TOKEN_INVALID);
		}

		$audience = $claims['aud'] ?? null;
		$audiences = is_array($audience) ? $audience : [$audience];
		$sub = $claims['sub'] ?? null;
		$email = $claims['email'] ?? '';

		$valid = in_array($claims['iss'] ?? null, self::ISSUERS, true)
			&& in_array($clientId, $audiences, true)
			&& is_numeric($claims['exp'] ?? null) && (int) $claims['exp'] + self::CLOCK_SKEW >= $now
			&& is_string($sub) && preg_match('/^[\x21-\x7e]{1,255}$/', $sub) === 1
			&& is_string($email) && strlen($email) <= 190;

		if (! $valid) {
			throw new OAuthFlowException(OAuthFlowException::ID_TOKEN_INVALID);
		}

		return ['sub' => $sub, 'email' => $email];
	}
}
