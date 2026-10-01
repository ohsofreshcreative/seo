<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Wynik próby uruchomienia przebiegu wyszukiwania (panel, CLI). Uruchomienie tylko kolejkuje przebieg —
 * płatne żądania wysyła przetwarzanie w tle (albo CLI w swoim procesie).
 */
final class DiscoveryStartResult
{
	public const QUEUED = 'queued';

	public const NOTHING_TO_DO = 'nothing_to_do';

	public const PLAN_CHANGED = 'plan_changed';

	public const ALREADY_RUNNING = 'already_running';

	public const RATE_LIMITED = 'rate_limited';

	public const NOT_CONFIGURED = 'not_configured';

	public const UNSUPPORTED_MARKET = 'unsupported_market';

	public const NO_SEEDS = 'no_seeds';

	public const OVER_BUDGET = 'over_budget';

	public const PAUSED = 'paused';

	public function __construct(
		public readonly string $status,
		public readonly ?DiscoveryPlan $plan = null,
		public readonly ?DiscoveryRun $run = null,
		public readonly ?string $reason = null,
	) {
	}

	public function isQueued(): bool
	{
		return $this->status === self::QUEUED;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'status' => $this->status,
			'reason' => $this->reason,
			'run' => $this->run?->toArray(),
			'plan' => $this->plan?->toArray(),
		];
	}
}
