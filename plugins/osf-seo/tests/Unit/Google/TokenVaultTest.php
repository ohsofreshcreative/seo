<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Google;

use OsfSeo\Google\TokenVault;
use OsfSeo\Google\VaultException;
use OsfSeo\Tests\Support\GoogleFakes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenVaultTest extends TestCase
{
	private const CONTEXT = 'google_refresh_token|7|1000123';

	private static function vault(?string $key): TokenVault
	{
		return new TokenVault(static fn (): ?string => $key);
	}

	public function test_encrypts_and_decrypts_with_authenticated_envelope(): void
	{
		$key = GoogleFakes::encryptionKey();
		$token = GoogleFakes::refreshToken();
		$vault = self::vault($key);

		$envelope = $vault->encrypt($token, self::CONTEXT);

		self::assertMatchesRegularExpression('/^v1\.[0-9a-f]{8}\.[A-Za-z0-9_-]+$/', $envelope);
		self::assertStringNotContainsString($token, $envelope);
		self::assertStringNotContainsString(substr($token, 8), $envelope);
		self::assertStringNotContainsString(substr($key, 7), $envelope);
		self::assertSame($token, $vault->decrypt($envelope, self::CONTEXT));
		self::assertNotSame($envelope, $vault->encrypt($token, self::CONTEXT), 'Losowy nonce — każde szyfrowanie inne.');
	}

	public function test_key_without_prefix_is_accepted(): void
	{
		$raw = base64_encode(random_bytes(32));

		self::assertSame('x', self::vault($raw)->decrypt(self::vault('base64:' . $raw)->encrypt('x', self::CONTEXT), self::CONTEXT));
	}

	public function test_tampered_ciphertext_fails_authentication(): void
	{
		$vault = self::vault(GoogleFakes::encryptionKey());
		$envelope = $vault->encrypt(GoogleFakes::refreshToken(), self::CONTEXT);
		$position = strlen($envelope) - 5;
		$tampered = substr_replace($envelope, $envelope[$position] === 'A' ? 'B' : 'A', $position, 1);

		$this->expectVaultError(VaultException::DECRYPTION_FAILED, fn () => $vault->decrypt($tampered, self::CONTEXT));
	}

	public function test_ciphertext_is_bound_to_its_context(): void
	{
		$vault = self::vault(GoogleFakes::encryptionKey());
		$envelope = $vault->encrypt(GoogleFakes::refreshToken(), self::CONTEXT);

		$this->expectVaultError(VaultException::DECRYPTION_FAILED, fn () => $vault->decrypt($envelope, 'google_refresh_token|8|1000123'));
	}

	public function test_different_key_is_detected(): void
	{
		$envelope = self::vault(GoogleFakes::encryptionKey())->encrypt(GoogleFakes::refreshToken(), self::CONTEXT);

		$this->expectVaultError(VaultException::KEY_MISMATCH, fn () => self::vault(GoogleFakes::encryptionKey())->decrypt($envelope, self::CONTEXT));
	}

	#[DataProvider('malformedEnvelopes')]
	public function test_malformed_envelope_is_rejected(string $envelope): void
	{
		$this->expectVaultError(VaultException::MALFORMED, fn () => self::vault(GoogleFakes::encryptionKey())->decrypt($envelope, self::CONTEXT));
	}

	/** @return iterable<string, array{string}> */
	public static function malformedEnvelopes(): iterable
	{
		yield 'plaintext' => ['1//not-encrypted'];
		yield 'unknown version' => ['v9.abcdef01.AAAA'];
		yield 'too many parts' => ['v1.abcdef01.AAAA.BBBB'];
		yield 'empty' => [''];
	}

	public function test_missing_key(): void
	{
		$this->expectVaultError(VaultException::NOT_CONFIGURED, fn () => self::vault(null)->encrypt('x', self::CONTEXT));
		$this->expectVaultError(VaultException::NOT_CONFIGURED, fn () => self::vault('  ')->encrypt('x', self::CONTEXT));
	}

	#[DataProvider('invalidKeys')]
	public function test_invalid_key_is_rejected_without_revealing_it(string $key): void
	{
		try {
			self::vault($key)->encrypt('x', self::CONTEXT);
			self::fail('Expected VaultException.');
		} catch (VaultException $exception) {
			self::assertSame(VaultException::INVALID_KEY, $exception->reason());
			self::assertStringNotContainsString($key, $exception->getMessage());
		}

		self::assertNotNull(TokenVault::keyProblem($key));
	}

	/** @return iterable<string, array{string}> */
	public static function invalidKeys(): iterable
	{
		yield 'short' => ['base64:' . base64_encode(random_bytes(16))];
		yield 'long' => [base64_encode(random_bytes(48))];
		yield 'not base64' => ['this is a passphrase, not a key!'];
		yield 'hex' => [bin2hex(random_bytes(32))];
	}

	public function test_generated_key_is_valid_and_unique(): void
	{
		$key = TokenVault::generateKey();

		self::assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $key);
		self::assertNull(TokenVault::keyProblem($key));
		self::assertNotSame($key, TokenVault::generateKey());
		self::assertTrue(TokenVault::isSupported());
	}

	private function expectVaultError(string $reason, callable $callback): void
	{
		try {
			$callback();
			self::fail('Expected VaultException ' . $reason);
		} catch (VaultException $exception) {
			self::assertSame($reason, $exception->reason());
		}
	}
}
