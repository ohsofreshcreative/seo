<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use RuntimeException;
use Throwable;

/** Połączenie wymaga ponownej autoryzacji w Google (status needs_reauth/revoked albo invalid_grant). */
final class ReauthorizationRequired extends RuntimeException
{
	public function __construct(private readonly int $connectionId, ?Throwable $previous = null)
	{
		parent::__construct(sprintf('Google connection %d requires re-authorization.', $connectionId), 0, $previous);
	}

	public function connectionId(): int
	{
		return $this->connectionId;
	}
}
