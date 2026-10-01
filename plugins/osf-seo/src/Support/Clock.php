<?php

declare(strict_types=1);

namespace OsfSeo\Support;

use DateTimeImmutable;

/** Źródło czasu (UTC) — podmieniane w testach (TTL, znaczniki czasu). */
interface Clock
{
	public function now(): DateTimeImmutable;
}
