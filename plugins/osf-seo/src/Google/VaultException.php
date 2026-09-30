<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use RuntimeException;

/** Błąd szyfrowania/odszyfrowania tokenu. Komunikaty nigdy nie zawierają klucza ani szyfrogramu. */
final class VaultException extends RuntimeException
{
	public const NOT_CONFIGURED = 'not_configured';

	public const INVALID_KEY = 'invalid_key';

	public const KEY_MISMATCH = 'key_mismatch';

	public const MALFORMED = 'malformed';

	public const DECRYPTION_FAILED = 'decryption_failed';

	public function __construct(private readonly string $reason, string $message)
	{
		parent::__construct($message);
	}

	public function reason(): string
	{
		return $this->reason;
	}
}
