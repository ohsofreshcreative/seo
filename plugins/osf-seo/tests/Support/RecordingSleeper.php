<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use OsfSeo\Support\Sleeper;

/** Zapisuje żądane przerwy zamiast czekać. */
final class RecordingSleeper implements Sleeper
{
	/** @var list<float> */
	public array $sleeps = [];

	public function sleep(float $seconds): void
	{
		$this->sleeps[] = $seconds;
	}
}
