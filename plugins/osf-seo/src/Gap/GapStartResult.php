<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Wynik próby uruchomienia importu (panel, CLI). Uruchomienie tylko kolejkuje przebieg — płatne żądania wysyła
 * przetwarzanie w tle (albo CLI w swoim procesie).
 */
final class GapStartResult
{
	public const QUEUED = 'queued';

	public const NOTHING_TO_DO = 'nothing_to_do';

	public const PLAN_CHANGED = 'plan_changed';

	public const ALREADY_RUNNING = 'already_running';

	public const RATE_LIMITED = 'rate_limited';

	public const NOT_CONFIGURED = 'not_configured';

	public const UNSUPPORTED_MARKET = 'unsupported_market';

	public const NO_COMPETITORS = 'no_competitors';

	public const OVER_BUDGET = 'over_budget';

	public const PAUSED = 'paused';

	public function __construct(
		public readonly string $status,
		public readonly ?GapPlan $plan = null,
		public readonly ?GapRun $run = null,
		public readonly ?string $reason = null,
	) {
	}

	public function isQueued(): bool
	{
		return $this->status === self::QUEUED;
	}

	public function message(): string
	{
		return match ($this->status) {
			self::QUEUED => 'Import zakolejkowany — działa w tle (możesz zamknąć przeglądarkę).',
			self::NOTHING_TO_DO => 'Nic do pobrania — dane wszystkich domen są świeże (bez kosztów). Luki zostaną przeliczone.',
			self::PLAN_CHANGED => 'Plan się zmienił (więcej żądań albo wyższy koszt niż w podglądzie). Sprawdź koszt ponownie.',
			self::ALREADY_RUNNING => 'Import tego projektu już trwa.',
			self::RATE_LIMITED => 'Import został uruchomiony przed chwilą. Odczekaj chwilę.',
			self::NOT_CONFIGURED => 'DataForSEO nie jest skonfigurowane.',
			self::UNSUPPORTED_MARKET => 'Rynek projektu nie jest obsługiwany.',
			self::NO_COMPETITORS => 'Brak aktywnych konkurentów — najpierw dodaj konkurenta.',
			self::OVER_BUDGET => 'Szacowany koszt przekracza pozostały limit kosztów DataForSEO (' . GapRun::reasonLabel((string) $this->reason) . ').',
			self::PAUSED => 'Płatne wywołania DataForSEO są wstrzymane po błędzie konta.',
			default => $this->status,
		};
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
