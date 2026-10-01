<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Wynik pobrania wolumenu dla paczki fraz.
 */
final class VolumeBatch
{
	/**
	 * @param list<VolumeMetrics> $items
	 */
	public function __construct(
		public readonly array $items,
		/** Koszt zgłoszony przez dostawcę dla tego wywołania (USD); null = nie podano. */
		public readonly ?float $cost = null,
	) {
	}
}
