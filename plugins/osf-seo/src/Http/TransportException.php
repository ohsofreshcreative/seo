<?php

declare(strict_types=1);

namespace OsfSeo\Http;

use RuntimeException;

/** Brak odpowiedzi HTTP (DNS, timeout, TLS). Komunikat zawiera tylko host i kod błędu — bez URL-a z parametrami. */
final class TransportException extends RuntimeException
{
}
