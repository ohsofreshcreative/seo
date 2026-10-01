<?php

declare(strict_types=1);

namespace OsfSeo\Support;

/** Wstrzymanie wykonania (backoff przy ponowieniach) — w testach podmieniane, żeby nie czekać. */
interface Sleeper
{
	public function sleep(float $seconds): void;
}
