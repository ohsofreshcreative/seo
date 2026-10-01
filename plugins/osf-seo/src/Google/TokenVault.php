<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use Closure;
use OsfSeo\Support\Base64Url;

/**
 * Szyfrowanie refresh tokenów: XChaCha20-Poly1305 (AEAD, libsodium), losowy 24-bajtowy nonce.
 *
 * Koperta: `v1.<key id>.<base64url(nonce || szyfrogram)>`. Identyfikator klucza (HMAC z klucza,
 * 32 bity) pozwala rozpoznać zmianę klucza bez ujawniania go. Dane uwierzytelniane (AAD)
 * wiążą szyfrogram z kontekstem (właściciel + konto Google) — skopiowanie wartości do innego
 * wiersza kończy się błędem odszyfrowania.
 *
 * Klucz: OSF_SEO_ENCRYPTION_KEY (wp-config.php / env), 32 bajty w base64, opcjonalnie z prefiksem
 * `base64:`. Nigdy w bazie ani w repozytorium (`wp osf-seo google:generate-key` generuje nowy).
 */
final class TokenVault
{
	private const VERSION = 'v1';

	private const KEY_BYTES = 32;

	/**
	 * @param Closure(): ?string $keySource
	 */
	public function __construct(private readonly Closure $keySource)
	{
	}

	public static function isSupported(): bool
	{
		return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
	}

	public function encrypt(#[\SensitiveParameter] string $plaintext, string $context): string
	{
		[$key, $keyId] = $this->key();
		$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $this->aad($keyId, $context), $nonce, $key);

		return self::VERSION . '.' . $keyId . '.' . Base64Url::encode($nonce . $ciphertext);
	}

	public function decrypt(string $envelope, string $context): string
	{
		$parts = explode('.', $envelope);

		if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
			throw new VaultException(VaultException::MALFORMED, 'Encrypted value has an unknown format.');
		}

		[$key, $keyId] = $this->key();

		if (! hash_equals($keyId, $parts[1])) {
			throw new VaultException(VaultException::KEY_MISMATCH, 'Encrypted value was created with a different encryption key.');
		}

		$raw = Base64Url::decode($parts[2]);
		$nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

		if ($raw === null || strlen($raw) <= $nonceBytes + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
			throw new VaultException(VaultException::MALFORMED, 'Encrypted value is truncated or not base64url.');
		}

		$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			substr($raw, $nonceBytes),
			$this->aad($keyId, $context),
			substr($raw, 0, $nonceBytes),
			$key,
		);

		if ($plaintext === false) {
			throw new VaultException(VaultException::DECRYPTION_FAILED, 'Encrypted value failed authentication (tampered or wrong context).');
		}

		return $plaintext;
	}

	/** Nowy losowy klucz w formacie akceptowanym przez OSF_SEO_ENCRYPTION_KEY. */
	public static function generateKey(): string
	{
		return 'base64:' . base64_encode(random_bytes(self::KEY_BYTES));
	}

	/** Opis problemu z kluczem (bez jego wartości) albo null, gdy klucz jest poprawny. */
	public static function keyProblem(#[\SensitiveParameter] ?string $raw): ?string
	{
		try {
			self::parseKey($raw);
		} catch (VaultException $exception) {
			return $exception->getMessage();
		}

		return null;
	}

	private static function parseKey(#[\SensitiveParameter] ?string $raw): string
	{
		$raw = trim((string) $raw);

		if ($raw === '') {
			throw new VaultException(VaultException::NOT_CONFIGURED, GoogleConfig::ENCRYPTION_KEY . ' is not set.');
		}

		$key = base64_decode(str_starts_with($raw, 'base64:') ? substr($raw, 7) : $raw, true);

		if ($key === false || strlen($key) !== self::KEY_BYTES) {
			throw new VaultException(
				VaultException::INVALID_KEY,
				GoogleConfig::ENCRYPTION_KEY . ' must be 32 random bytes encoded in base64 (generate one with: wp osf-seo google:generate-key).',
			);
		}

		return $key;
	}

	/**
	 * @return array{0: string, 1: string} [klucz, identyfikator klucza]
	 */
	private function key(): array
	{
		$key = self::parseKey(($this->keySource)());

		return [$key, substr(hash_hmac('sha256', 'osf-seo token vault key id', $key), 0, 8)];
	}

	private function aad(string $keyId, string $context): string
	{
		return 'osf-seo|' . self::VERSION . '|' . $keyId . '|' . $context;
	}
}
