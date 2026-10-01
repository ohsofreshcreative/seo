<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

/** Wynik „Synchronizuj teraz”. */
final class SyncRequestResult
{
	public const QUEUED = 'queued';

	public const ALREADY_QUEUED = 'already_queued';

	public const RATE_LIMITED = 'rate_limited';

	public function __construct(
		public readonly string $outcome,
		public readonly int $jobs = 0,
		public readonly int $retryInSeconds = 0,
	) {
	}

	public function userMessage(): string
	{
		return match ($this->outcome) {
			self::QUEUED => sprintf('Zaplanowano synchronizację (%d %s). Dane pojawią się po wykonaniu zadań w tle.', $this->jobs, $this->jobs === 1 ? 'zadanie' : 'zadania'),
			self::ALREADY_QUEUED => 'Synchronizacja już trwa albo czeka w kolejce — nie dodano kolejnych zadań.',
			default => sprintf('Synchronizację można zlecić ponownie za %d s.', max(1, $this->retryInSeconds)),
		};
	}
}
