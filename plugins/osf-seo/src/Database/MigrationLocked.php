<?php

declare(strict_types=1);

namespace OsfSeo\Database;

use RuntimeException;

/** Inny proces właśnie wykonuje migracje. */
final class MigrationLocked extends RuntimeException
{
}
