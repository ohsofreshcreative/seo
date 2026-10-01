<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use RuntimeException;

/**
 * Projekt nie jest gotowy do pobierania danych GSC (brak połączenia, połączenie wymaga ponownej
 * autoryzacji albo nie wybrano property).
 */
final class GscNotReady extends RuntimeException
{
	public const NO_CONNECTION = 'no_connection';

	public const CONNECTION_INACTIVE = 'connection_inactive';

	public const NO_PROPERTY = 'no_property';

	public const PROJECT_INACTIVE = 'project_inactive';

	private const MESSAGES = [
		self::NO_CONNECTION => 'Projekt nie jest połączony z Google Search Console.',
		self::CONNECTION_INACTIVE => 'Połączenie z Google wymaga ponownej autoryzacji.',
		self::NO_PROPERTY => 'Nie wybrano property Search Console.',
		self::PROJECT_INACTIVE => 'Projekt jest wstrzymany albo zarchiwizowany.',
	];

	public function __construct(private readonly string $reason)
	{
		parent::__construct('Project is not ready for Search Console: ' . $reason . '.');
	}

	public function reason(): string
	{
		return $this->reason;
	}

	public function userMessage(): string
	{
		return self::MESSAGES[$this->reason] ?? 'Projekt nie jest gotowy do synchronizacji.';
	}
}
