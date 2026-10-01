<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Auth\ProjectContext;
use RuntimeException;
use Throwable;

/**
 * Nieudane łączenie konta Google. `reason()` to stabilny kod (logi, testy), `userMessage()` —
 * komunikat dla panelu. `context()` wskazuje projekt, jeśli udało się go ustalić ze `state`.
 */
final class OAuthFlowException extends RuntimeException
{
	public const NOT_CONFIGURED = 'not_configured';

	public const STATE_INVALID = 'state_invalid';

	public const STATE_EXPIRED = 'state_expired';

	public const STATE_USER_MISMATCH = 'state_user_mismatch';

	public const ACCESS_DENIED = 'access_denied';

	public const EXCHANGE_FAILED = 'exchange_failed';

	public const SCOPE_MISSING = 'scope_missing';

	public const ID_TOKEN_INVALID = 'id_token_invalid';

	public const NO_REFRESH_TOKEN = 'no_refresh_token';

	private const MESSAGES = [
		self::NOT_CONFIGURED => 'Integracja z Google nie jest skonfigurowana (dane OAuth i klucz szyfrowania ustawia się w wp-config.php).',
		self::STATE_INVALID => 'Link autoryzacji jest nieprawidłowy albo został już użyty. Rozpocznij łączenie ponownie.',
		self::STATE_EXPIRED => 'Autoryzacja w Google trwała zbyt długo. Rozpocznij łączenie ponownie.',
		self::STATE_USER_MISMATCH => 'Autoryzację rozpoczął inny użytkownik. Rozpocznij łączenie ze swojego konta.',
		self::ACCESS_DENIED => 'Nie udzielono dostępu w Google — połączenie nie zostało utworzone.',
		self::EXCHANGE_FAILED => 'Google nie potwierdził autoryzacji. Spróbuj ponownie za chwilę.',
		self::SCOPE_MISSING => 'Na ekranie zgody Google nie zaznaczono dostępu do danych Search Console. Połącz ponownie i zaznacz to uprawnienie.',
		self::ID_TOKEN_INVALID => 'Nie udało się potwierdzić konta Google. Spróbuj ponownie.',
		self::NO_REFRESH_TOKEN => 'Google nie przekazał tokenu odświeżania. Usuń dostęp aplikacji w ustawieniach konta Google i połącz ponownie.',
	];

	public function __construct(
		private readonly string $reason,
		private readonly ?ProjectContext $context = null,
		?Throwable $previous = null,
	) {
		parent::__construct('Google OAuth failed: ' . $reason . '.', 0, $previous);
	}

	public function reason(): string
	{
		return $this->reason;
	}

	public function context(): ?ProjectContext
	{
		return $this->context;
	}

	public function userMessage(): string
	{
		return self::MESSAGES[$this->reason] ?? 'Nie udało się połączyć z Google.';
	}
}
