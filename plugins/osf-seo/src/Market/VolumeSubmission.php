<?php

declare(strict_types=1);

namespace OsfSeo\Market;

/**
 * Zlecenie wolumenu: dostawca asynchroniczny zwraca identyfikator zadania (wynik później, `fetchVolume`),
 * synchroniczny — od razu wynik.
 */
final class VolumeSubmission
{
	public function __construct(
		public readonly ?string $taskId,
		/** Koszt zgłoszony przy zleceniu (USD); null = nie podano. */
		public readonly ?float $cost,
		public readonly ?VolumeBatch $results = null,
	) {
	}
}
