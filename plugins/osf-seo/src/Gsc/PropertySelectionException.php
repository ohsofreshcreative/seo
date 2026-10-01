<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use RuntimeException;
use Throwable;

/**
 * Nieudany odczyt lub wybór property GSC. `reason()` — stabilny kod (CLI, testy, logi),
 * `userMessage()` — komunikat dla panelu.
 */
final class PropertySelectionException extends RuntimeException
{
	public const NO_CONNECTION = 'no_connection';

	public const CONNECTION_INACTIVE = 'connection_inactive';

	public const NOT_FOUND = 'property_not_found';

	public const INSUFFICIENT_PERMISSION = 'insufficient_permission';

	public const RESET_REQUIRED = 'reset_required';

	public const API_ERROR = 'api_error';

	public const CONFIGURATION = 'configuration';

	private const MESSAGES = [
		self::NO_CONNECTION => 'Projekt nie jest połączony z kontem Google. Najpierw połącz Google Search Console.',
		self::CONNECTION_INACTIVE => 'Połączenie z Google wymaga ponownej autoryzacji. Połącz konto Google ponownie.',
		self::NOT_FOUND => 'Ta property nie jest dostępna na połączonym koncie Google.',
		self::INSUFFICIENT_PERMISSION => 'Konto Google nie ma dostępu do danych tej property (property niezweryfikowana). Wybierz inną property albo nadaj dostęp w Search Console.',
		self::RESET_REQUIRED => 'Projekt ma już dane z innej property. Zmiana wymaga potwierdzenia usunięcia dotychczasowych danych i ponownego importu.',
		self::CONFIGURATION => 'Nie można odczytać danych dostępowych Google (konfiguracja klucza szyfrowania). Skontaktuj się z administratorem.',
	];

	public function __construct(private readonly string $reason, ?Throwable $previous = null)
	{
		parent::__construct('GSC property selection failed: ' . $reason . '.', 0, $previous);
	}

	public function reason(): string
	{
		return $this->reason;
	}

	public function userMessage(): string
	{
		$previous = $this->getPrevious();

		if ($this->reason === self::API_ERROR && $previous instanceof GscApiException) {
			return 'Nie udało się pobrać listy properties z Google: ' . lcfirst($previous->userMessage());
		}

		return self::MESSAGES[$this->reason] ?? 'Nie udało się pobrać listy properties z Google. Spróbuj ponownie za chwilę.';
	}
}
