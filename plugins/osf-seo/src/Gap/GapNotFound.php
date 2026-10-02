<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use RuntimeException;

/** Luka, grupa, strona albo przebieg nie istnieje w projekcie (także: należy do innego projektu) → 404. */
final class GapNotFound extends RuntimeException
{
}
